<?php
require_once '/var/www/common/init.php';
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Non autorisé']);
    exit();
}
$accountsFile = '/opt/supervision/data/accounts.json';
$secretKeyFile = '/opt/supervision/secret.key';
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
if ($action === 'check_ssh_key' || $action === 'generate_ssh_key') {
    $accountsData = @json_decode(@file_get_contents($accountsFile), true) ?: [];
    $localUser = trim($accountsData['local_user'] ?? 'root');
    $pw = posix_getpwnam($localUser);
    if (!$pw) {
        echo json_encode(['success' => false, 'error' => "Utilisateur '$localUser' introuvable"]);
        exit();
    }
    $sshDir = rtrim($pw['dir'], '/') . '/.ssh';
    $keyFile = $sshDir . '/id_ed25519';
    $pubFile = $keyFile . '.pub';

    if ($action === 'check_ssh_key') {
        exec(sprintf("/usr/bin/sudo -n -u %s /bin/cat %s 2>/dev/null", escapeshellarg($localUser), escapeshellarg($pubFile)), $pubOut, $pubCode);
        $key = trim(implode("\n", $pubOut));
        echo json_encode(['success' => true, 'exists' => ($pubCode === 0 && !empty($key)), 'key' => $key]);
        exit();
    }
    if ($action === 'generate_ssh_key') {
        $force = !empty($input['force']);
        exec(sprintf("/usr/bin/sudo -n -u %s /usr/bin/test -f %s", escapeshellarg($localUser), escapeshellarg($keyFile)), $tOut, $tCode);
        if ($tCode === 0 && !$force) {
            echo json_encode(['success' => false, 'already_exists' => true, 'error' => 'Une clé existe déjà. Confirmez pour écraser.']);
            exit();
        }
        exec(sprintf("/usr/bin/sudo -n -u %s /usr/bin/mkdir -p %s", escapeshellarg($localUser), escapeshellarg($sshDir)));
        if ($localUser !== 'root') {
            exec(sprintf("/usr/bin/sudo -n -u %s /bin/chmod 755 %s", escapeshellarg($localUser), escapeshellarg($pw['dir'])));
            exec(sprintf("/usr/bin/sudo -n -u %s /bin/chmod 711 %s", escapeshellarg($localUser), escapeshellarg($sshDir)));
        } else {
            exec(sprintf("/bin/chmod 711 %s", escapeshellarg($sshDir)));
        }
        exec(sprintf("/usr/bin/sudo -n -u %s /bin/rm -f %s %s", escapeshellarg($localUser), escapeshellarg($keyFile), escapeshellarg($pubFile)));
        exec(sprintf("/usr/bin/sudo -n -u %s /usr/bin/ssh-keygen -q -t ed25519 -N '' -f %s -C %s 2>&1", escapeshellarg($localUser), escapeshellarg($keyFile), escapeshellarg("supervision@$localUser")), $out, $code);
        if ($localUser !== 'root') {
            exec(sprintf("/usr/bin/sudo -n -u %s /bin/chmod 600 %s", escapeshellarg($localUser), escapeshellarg($keyFile)));
            exec(sprintf("/usr/bin/sudo -n -u %s /bin/chmod 644 %s", escapeshellarg($localUser), escapeshellarg($pubFile)));
        } else {
            exec(sprintf("/bin/chmod 600 %s", escapeshellarg($keyFile)));
            exec(sprintf("/bin/chmod 644 %s", escapeshellarg($pubFile)));
        }
        exec(sprintf("/usr/bin/sudo -n -u %s /bin/cat %s 2>/dev/null", escapeshellarg($localUser), escapeshellarg($pubFile)), $genOut, $genCode);
        $genKey = trim(implode("\n", $genOut));
        if ($code === 0 && !empty($genKey)) {
            echo json_encode(['success' => true, 'key' => $genKey]);
        } else {
            echo json_encode(['success' => false, 'error' => implode(' ', $out) ?: 'Erreur génération']);
        }
        exit();
    }
}

if ($action === 'save_all') {
    $localUser = trim($input['local_user'] ?? '');
    $sshAccounts = $input['ssh_accounts'] ?? [];
    $winrmAccounts = $input['winrm_accounts'] ?? [];
    $cleanSsh = [];

    foreach ($sshAccounts as $ssh) {
        $name = trim($ssh['name'] ?? '');
        $user = trim($ssh['user'] ?? '');
        if ($user !== '') {
            $cleanSsh[] = [
                'id' => $ssh['id'] ?? ('ssh_' . uniqid()),
                'name' => $name ?: $user,
                'user' => $user
            ];
        }
    }

    $cleanWinrm = [];
    foreach ($winrmAccounts as $w) {
        $name = trim($w['name'] ?? '');
        $domain = trim($w['domain'] ?? '');
        $user = trim($w['user'] ?? '');
        $newPwd = $w['new_password'] ?? '';
        $encPwd = $w['enc_password'] ?? '';
        if ($user === '') continue;

        if (!empty($newPwd)) {
            $pyScript = <<<PY
import sys
from cryptography.fernet import Fernet
key = open('{$secretKeyFile}', 'rb').read().strip()
f = Fernet(key)
enc = f.encrypt(sys.argv[1].encode()).decode()
print(enc)
PY;
            $descriptors = [
                0 => ["pipe", "r"],
                1 => ["pipe", "w"],
                2 => ["pipe", "w"]
            ];
            $proc = proc_open("python3 -c " . escapeshellarg($pyScript) . " " . escapeshellarg($newPwd), $descriptors, $pipes);
            if (is_resource($proc)) {
                fclose($pipes[0]);
                $encOut = trim(stream_get_contents($pipes[1]));
                $errOut = trim(stream_get_contents($pipes[2]));
                fclose($pipes[1]);
                fclose($pipes[2]);
                $ret = proc_close($proc);
                if ($ret === 0 && !empty($encOut)) {
                    $encPwd = $encOut;
                } else {
                    echo json_encode(['success' => false, 'error' => "Erreur lors du chiffrement : " . $errOut]);
                    exit();
                }
            }
        }
        $cleanWinrm[] = [
            'id' => $w['id'] ?? ('win_' . uniqid()),
            'name' => $name ?: ($domain ? "$domain\\$user" : $user),
            'domain' => $domain,
            'user' => $user,
            'enc_password' => $encPwd
        ];
    }
    $dataToSave = [
        'local_user' => $localUser,
        'ssh_accounts' => $cleanSsh,
        'winrm_accounts' => $cleanWinrm
    ];
    if (file_put_contents($accountsFile, json_encode($dataToSave, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Impossible d\'écrire dans accounts.json']);
    }
    exit();
}
echo json_encode(['success' => false, 'error' => 'Action inconnue']);
