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
if ($action === 'save_all') {
    $localUser = trim($input['local_user'] ?? '');
    if ($localUser === '') {
        $localUser = 'root';
    }
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
