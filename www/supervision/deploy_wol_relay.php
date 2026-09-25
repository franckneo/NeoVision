<?php
require_once '/var/www/common/init.php';
header('Content-Type: application/json');
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['success' => false, 'error' => __('msg_unauthorized', 'Non autorisé')]);
    exit();
}
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$name = trim($input['name'] ?? '');
$ip = trim($input['ip'] ?? '');
$subnetsStr = trim($input['subnets'] ?? '');
$remoteUser = trim($input['remote_user'] ?? '');
$password = $input['password'] ?? '';
$accountsFile = '/opt/supervision/data/accounts.json';
$localUser = '';
if (file_exists($accountsFile)) {
    $accData = json_decode(file_get_contents($accountsFile), true);
    if (!empty($accData['local_user'])) $localUser = trim($accData['local_user']);
}
if (!empty($input['local_user'])) $localUser = trim($input['local_user']);
if (empty($localUser)) $localUser = 'root';
if (!$ip || !$remoteUser || !$password) {
    echo json_encode(['success' => false, 'error' => __('msg_missing_fields_wol', 'Champs obligatoires manquants (IP, utilisateur, mot de passe)')]);
    exit();
}
$subnets = array_values(array_filter(array_map('trim', explode(',', $subnetsStr))));
$alias = 'relai_' . preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower($name ?: $ip));
$payload = json_encode([
    'name' => $name,
    'ip' => $ip,
    'subnets' => $subnets,
    'remote_user' => $remoteUser,
    'password' => $password,
    'local_user' => $localUser,
    'alias' => $alias,
    'i18n' => [
        'missing_data' => __('msg_wol_missing_data', "Données d'entrée manquantes."),
        'pubkey_not_found' => __('msg_wol_pubkey_not_found', "Clé publique introuvable"),
        'cfg_write_error' => __('msg_wol_cfg_write_error', "Clé injectée mais impossible d'écrire dans"),
        'success_msg' => __('msg_wol_deploy_success', "Relais '%s' (%s) déployé et configuré avec succès !")
    ]
]);
$pyScript = <<<'PY'
import sys, json, os, paramiko, base64
try:
    if len(sys.argv) < 2:
        raise Exception("Données d'entrée manquantes.")
    data = json.loads(base64.b64decode(sys.argv[1]).decode('utf-8'))
    ip, remote_user, password, local_user, alias, subnets, name = data['ip'], data['remote_user'], data['password'], data['local_user'], data['alias'], data['subnets'], data['name']
    i18n = data.get('i18n', {})

    local_home = "/root" if local_user == "root" else f"/home/{local_user}"
    local_ssh_dir = os.path.join(local_home, ".ssh")
    priv_key_path, pub_key_path = os.path.join(local_ssh_dir, "id_rsa"), os.path.join(local_ssh_dir, "id_rsa.pub")

    if not os.path.exists(pub_key_path):
        err_msg = f"{i18n.get('pubkey_not_found', 'Clé publique introuvable')} ({pub_key_path})."
        print(json.dumps({"success": False, "error": err_msg}))
        sys.exit(0)
    with open(pub_key_path, "r") as f:
        pub_key = f.read().strip()
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(ip, username=remote_user, password=password, timeout=10)
    setup_cmd = f"mkdir -p ~/.ssh && chmod 700 ~/.ssh && touch ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys && grep -qxF '{pub_key}' ~/.ssh/authorized_keys || echo '{pub_key}' >> ~/.ssh/authorized_keys"
    _, stdout, _ = ssh.exec_command(setup_cmd)
    stdout.channel.recv_exit_status()
    install_cmd = f"echo '{password}' | sudo -S apt-get update -qq && echo '{password}' | sudo -S apt-get install -y -qq wakeonlan etherwake"
    _, stdout, _ = ssh.exec_command(install_cmd)
    stdout.channel.recv_exit_status()
    ssh.close()
    ssh_config_path = os.path.join(local_ssh_dir, "config")
    config_entry = f"Host {alias}\n    HostName {ip}\n    User {remote_user}\n    IdentityFile {priv_key_path}\n    StrictHostKeyChecking no\n    UserKnownHostsFile /dev/null\n"
    existing_cfg = ""
    if os.path.exists(ssh_config_path):
        with open(ssh_config_path, "r") as f:
            existing_cfg = f.read()
    lines, new_lines, skip = existing_cfg.split("\n"), [], False
    for line in lines:
        if line.strip() == f"Host {alias}":
            skip = True
            continue
        if skip and line.startswith("Host "):
            skip = False
        if not skip:
            new_lines.append(line)
    clean_cfg = "\n".join(new_lines).strip() + "\n\n" + config_entry.strip() + "\n"
    try:
        with open(ssh_config_path, "w") as f:
            f.write(clean_cfg)
    except Exception as e:
        err_msg = f"{i18n.get('cfg_write_error', 'Clé injectée mais impossible d\'écrire dans')} {ssh_config_path} : {str(e)}"
        print(json.dumps({"success": False, "error": err_msg}))
        sys.exit(0)
    dataFile = "/opt/supervision/data/wol_relays.json"
    os.makedirs("/opt/supervision/data", exist_ok=True)
    wol_data = {"relays": []}
    if os.path.exists(dataFile):
        try:
            with open(dataFile, "r") as f:
                wol_data = json.load(f)
        except Exception:
            wol_data = {"relays": []}
    relays = [r for r in wol_data.get("relays", []) if r.get("alias") != alias]
    relays.append({"name": name, "alias": alias, "ip": ip, "subnets": subnets, "remote_user": remote_user})
    wol_data["relays"] = relays
    with open(dataFile, "w") as f:
        json.dump(wol_data, f, indent=4)
    succ_tpl = i18n.get('success_msg', "Relais '%s' (%s) déployé et configuré avec succès !")
    print(json.dumps({"success": True, "message": succ_tpl % (name, alias)}))
except Exception as err:
    print(json.dumps({"success": False, "error": str(err)}))
PY;
$descriptors = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]];
$process = proc_open("python3 -W ignore - " . escapeshellarg(base64_encode($payload)), $descriptors, $pipes);
if (is_resource($process)) {
    fwrite($pipes[0], $pyScript);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $errorOutput = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($process);
    $res = json_decode(trim($output), true);
    echo $res ? json_encode($res) : json_encode(['success' => false, 'error' => $output ?: ($errorOutput ?: __('msg_unknown_error', 'Erreur inconnue'))]);
} else {
    echo json_encode(['success' => false, 'error' => __('msg_python_proc_failed', 'Impossible de lancer le processus Python')]);
}
