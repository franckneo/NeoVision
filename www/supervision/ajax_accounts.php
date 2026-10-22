<?php
require_once '/var/www/common/init.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Non autorisé']);
    exit();
}
$accountsFile  = '/opt/supervision/data/accounts.json';
$secretKeyFile = '/opt/supervision/secret.key';
$pythonBin     = '/opt/supervision/venv/bin/python3';
$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';

function encrypt_password(string $plain, string $keyFile, string $pythonBin): array
{
    $pyScript = <<<'PY'
import sys
from cryptography.fernet import Fernet
key_path = sys.argv[1]
with open(key_path, 'rb') as fh:
    key = fh.read().strip()
plain = sys.stdin.read()
sys.stdout.write(Fernet(key).encrypt(plain.encode()).decode())
PY;

    $cmd = [$pythonBin, '-c', $pyScript, $keyFile];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return ['ok' => false, 'value' => 'Impossible de lancer le processus de chiffrement'];
    }
    fwrite($pipes[0], $plain);
    fclose($pipes[0]);
    $encOut = trim(stream_get_contents($pipes[1]));
    $errOut = trim(stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $ret = proc_close($proc);
    if ($ret !== 0 || $encOut === '') {
        return ['ok' => false, 'value' => 'Erreur lors du chiffrement : ' . ($errOut ?: 'sortie vide')];
    }
    return ['ok' => true, 'value' => $encOut];
}

if ($action === 'check_ssh_key') {
    $pubFile = '/var/lib/neovision/.ssh/id_ed25519.pub';
    $exists  = is_file($pubFile) && is_readable($pubFile);
    $key     = $exists ? trim((string)file_get_contents($pubFile)) : '';
    echo json_encode(['success' => true, 'exists' => $exists && $key !== '', 'key' => $key]);
    exit();
}

if ($action === 'generate_ssh_key') {
    echo json_encode(['success' => false, 'error' => "La génération de clé se fait via install.sh ou : sudo -u neovision ssh-keygen -t ed25519 -N '' -f /var/lib/neovision/.ssh/id_ed25519"]);
    exit();
}

if ($action === 'encrypt_pwd') {
    $pwd = (string)($input['password'] ?? '');
    if ($pwd === '') {
        echo json_encode(['success' => false, 'error' => 'Mot de passe vide']);
        exit();
    }
    $res = encrypt_password($pwd, $secretKeyFile, $pythonBin);
    if (!$res['ok']) {
        echo json_encode(['success' => false, 'error' => $res['value']]);
        exit();
    }
    echo json_encode(['success' => true, 'enc_password' => $res['value']]);
    exit();
}

if ($action === 'save_all') {
    $sshAccounts   = $input['ssh_accounts'] ?? [];
    $winrmAccounts = $input['winrm_accounts'] ?? [];
    $cleanSsh = [];
    foreach ($sshAccounts as $ssh) {
        $name = trim($ssh['name'] ?? '');
        $user = trim($ssh['user'] ?? '');
        if ($user === '') continue;
        $cleanSsh[] = [
            'id'   => $ssh['id'] ?? ('ssh_' . uniqid()),
            'name' => $name ?: $user,
            'user' => $user,
        ];
    }
    $cleanWinrm = [];
    foreach ($winrmAccounts as $w) {
        $name   = trim($w['name'] ?? '');
        $domain = trim($w['domain'] ?? '');
        $user   = trim($w['user'] ?? '');
        $newPwd = (string)($w['new_password'] ?? '');
        $encPwd = (string)($w['enc_password'] ?? '');
        if ($user === '') continue;
        if ($newPwd !== '') {
            $res = encrypt_password($newPwd, $secretKeyFile, $pythonBin);
            if (!$res['ok']) {
                echo json_encode(['success' => false, 'error' => $res['value']]);
                exit();
            }
            $encPwd = $res['value'];
        }
        $cleanWinrm[] = [
            'id'           => $w['id'] ?? ('win_' . uniqid()),
            'name'         => $name ?: ($domain ? "$domain\\$user" : $user),
            'domain'       => $domain,
            'user'         => $user,
            'enc_password' => $encPwd,
        ];
    }
    $dataToSave = [
        'ssh_accounts'   => $cleanSsh,
        'winrm_accounts' => $cleanWinrm,
    ];
    $json = json_encode($dataToSave, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (file_put_contents($accountsFile, $json) !== false) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => "Impossible d'écrire dans accounts.json"]);
    }
    exit();
}
echo json_encode(['success' => false, 'error' => 'Action inconnue']);
