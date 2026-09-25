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
$type = strtolower(trim($input['type'] ?? 'windows'));
$user = trim($input['user'] ?? '');
$encPassword = trim($input['enc_password'] ?? '');
if (!$ip) {
    echo json_encode(['success' => false, 'error' => __('msg_missing_ip', 'Adresse IP manquante')]);
    exit();
}
$pythonBin = file_exists('/opt/supervision/venv/bin/python')
    ? '/opt/supervision/venv/bin/python'
    : '/opt/supervision/venv/bin/python3';
$pyScript = <<<'PY'
import sys, json
try:
    sys.path.insert(0, '/opt/supervision')
    from lib.servers import get_servers
    from lib.crypto import decrypt_password, get_default_local_user
    data = json.loads(sys.argv[1])
    ip = data.get('ip', '')
    srv_type = data.get('type', 'windows')
    user = data.get('user', '')
    name = data.get('name', '')
    enc_password = data.get('enc_password', '')
    if srv_type == "linux":
        from lib.ssh import run_ssh
        u = user if user else get_default_local_user()
        res = run_ssh(u, ip, "echo OK", timeout=6)
        if res and res.returncode == 0:
            print(json.dumps({"success": True, "type": "ssh_ok", "user": u, "ip": ip}))
        else:
            err = res.stderr.strip() if res else "TIMEOUT"
            print(json.dumps({"success": False, "type": "ssh_fail", "details": err}))
    else:
        pwd = None
        if enc_password:
            try:
                pwd = decrypt_password(enc_password)
            except Exception as e:
                print(json.dumps({"success": False, "type": "decrypt_error", "details": str(e)}))
                sys.exit(0)
        if not pwd:
            servers = get_servers()
            for s in servers:
                if s.get("name") == name or s.get("ip") == ip:
                    try:
                        pwd = decrypt_password(s.get("enc_password"))
                    except Exception:
                        pwd = None
                    if not user:
                        user = s.get("user")
                    break
        if not pwd:
            print(json.dumps({"success": False, "type": "pwd_missing"}))
            sys.exit(0)
        from lib.winrm_client import get_session
        s = get_session(ip, user, pwd)
        if not s:
            print(json.dumps({"success": False, "type": "winrm_init_fail"}))
            sys.exit(0)
        r = s.run_ps("Write-Output OK")
        if r.status_code == 0 and "OK" in r.std_out.decode(errors="ignore"):
            print(json.dumps({"success": True, "type": "winrm_ok", "user": user, "ip": ip}))
        else:
            err = r.std_err.decode(errors="ignore").strip()
            print(json.dumps({"success": False, "type": "winrm_fail", "code": r.status_code, "details": err}))
except Exception as e:
    print(json.dumps({"success": False, "type": "script_error", "details": str(e)}))
PY;
$payloadJson = json_encode([
    'name' => $name,
    'ip' => $ip,
    'type' => $type,
    'user' => $user,
    'enc_password' => $encPassword
]);
$descriptors = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]];
$proc = proc_open([$pythonBin, "-c", $pyScript, $payloadJson], $descriptors, $pipes, null, ['PYTHONPATH' => '/opt/supervision']);
if (is_resource($proc)) {
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $res = json_decode(trim($out), true);
    if ($res) {
        if (!empty($res['success'])) {
            $msgType = $res['type'] ?? '';
            if ($msgType === 'ssh_ok') {
                $res['message'] = sprintf(__('msg_test_ssh_success', 'Connexion SSH réussie (%s@%s)'), $res['user'], $res['ip']);
            } elseif ($msgType === 'winrm_ok') {
                $res['message'] = sprintf(__('msg_test_winrm_success', 'Connexion WinRM réussie (%s@%s)'), $res['user'], $res['ip']);
            }
        } else {
            $errType = $res['type'] ?? '';
            $details = $res['details'] ?? '';
            if ($errType === 'ssh_fail') {
                $detailMsg = ($details === 'TIMEOUT') ? __('msg_test_timeout_error', 'Délai d\'attente dépassé ou erreur réseau') : $details;
                $res['error'] = sprintf(__('msg_test_ssh_failed', 'Échec SSH : %s'), $detailMsg);
            } elseif ($errType === 'decrypt_error') {
                $res['error'] = sprintf(__('msg_test_decrypt_error', 'Erreur déchiffrement : %s'), $details);
            } elseif ($errType === 'pwd_missing') {
                $res['error'] = __('msg_test_pwd_missing', 'Mot de passe introuvable (enregistrez le mot de passe d\'abord)');
            } elseif ($errType === 'winrm_init_fail') {
                $res['error'] = __('msg_test_winrm_init_fail', 'Impossible d\'initialiser la session WinRM');
            } elseif ($errType === 'winrm_fail') {
                $code = $res['code'] ?? '';
                $res['error'] = sprintf(__('msg_test_winrm_failed', 'Échec WinRM (code %s) : %s'), $code, $details);
            } elseif ($errType === 'script_error') {
                $res['error'] = sprintf(__('msg_test_script_error', 'Erreur script : %s'), $details);
            }
        }
        echo json_encode($res);
    } else {
        echo json_encode(['success' => false, 'error' => $err ?: __('msg_no_response_script', 'Aucune réponse du script')]);
    }
} else {
    echo json_encode(['success' => false, 'error' => __('msg_cannot_exec_python', 'Impossible d\'exécuter Python')]);
}
