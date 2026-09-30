<?php
require_once '/var/www/common/init.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non autorisé']);
    exit();
}

$accountsFile = '/opt/supervision/data/accounts.json';
$secretKeyFile = '/opt/supervision/secret.key';
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Requête JSON invalide']);
    exit();
}

function fernetCli(string $mode, string $value, string $secretKeyFile): ?string
{
    if ($value === '' || !is_readable($secretKeyFile)) {
        return null;
    }

    $pyScript = <<<'PY'
import sys
from cryptography.fernet import Fernet

key_path = sys.argv[1]
mode = sys.argv[2]

with open(key_path, "rb") as key_file:
    fernet = Fernet(key_file.read().strip())

value = sys.stdin.buffer.read()

if mode == "encrypt":
    result = fernet.encrypt(value)
elif mode == "decrypt":
    result = fernet.decrypt(value)
else:
    raise ValueError("Invalid mode")

sys.stdout.write(result.decode())
PY;

    $command = 'python3 -c ' . escapeshellarg($pyScript) . ' '
        . escapeshellarg($secretKeyFile) . ' '
        . escapeshellarg($mode);

    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        return null;
    }

    fwrite($pipes[0], $value);
    fclose($pipes[0]);

    $output = trim(stream_get_contents($pipes[1]));
    $error = trim(stream_get_contents($pipes[2]));

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    if ($exitCode !== 0 || $output === '') {
        error_log('NeoVision: échec du chiffrement/déchiffrement Fernet : ' . $error);
        return null;
    }

    return $output;
}

$action = $input['action'] ?? '';

if ($action === 'encrypt_ssh_password') {
    $password = (string)($input['password'] ?? '');

    if ($password === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Mot de passe vide']);
        exit();
    }

    $encrypted = fernetCli('encrypt', $password, $secretKeyFile);

    if ($encrypted === null) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Chiffrement SSH impossible']);
        exit();
    }

    echo json_encode(['success' => true, 'enc_password' => $encrypted]);
    exit();
}

if ($action === 'save_all') {
    $localUser = trim((string)($input['local_user'] ?? ''));
    if ($localUser === '') {
        $localUser = 'root';
    }

    $sshAccounts = $input['ssh_accounts'] ?? [];
    $winrmAccounts = $input['winrm_accounts'] ?? [];

    if (!is_array($sshAccounts) || !is_array($winrmAccounts)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Liste de comptes invalide']);
        exit();
    }

    $cleanSsh = [];

    foreach ($sshAccounts as $ssh) {
        if (!is_array($ssh)) {
            continue;
        }

        $name = trim((string)($ssh['name'] ?? ''));
        $user = trim((string)($ssh['user'] ?? ''));

        if ($user === '') {
            continue;
        }

        $encPwd = (string)($ssh['enc_password'] ?? '');
        $newPwd = (string)($ssh['new_password'] ?? '');

        if ($newPwd !== '') {
            $encrypted = fernetCli('encrypt', $newPwd, $secretKeyFile);

            if ($encrypted === null) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Chiffrement du mot de passe SSH impossible']);
                exit();
            }

            $encPwd = $encrypted;
        }

        $account = [
            'id' => (string)($ssh['id'] ?? ('ssh_' . uniqid())),
            'name' => $name !== '' ? $name : $user,
            'user' => $user,
        ];

        if ($encPwd !== '') {
            $account['enc_password'] = $encPwd;
        }

        $cleanSsh[] = $account;
    }

    $cleanWinrm = [];

    foreach ($winrmAccounts as $win) {
        if (!is_array($win)) {
            continue;
        }

        $name = trim((string)($win['name'] ?? ''));
        $domain = trim((string)($win['domain'] ?? ''));
        $user = trim((string)($win['user'] ?? ''));

        if ($user === '') {
            continue;
        }

        $newPwd = (string)($win['new_password'] ?? '');
        $encPwd = (string)($win['enc_password'] ?? '');

        if ($newPwd !== '') {
            $encrypted = fernetCli('encrypt', $newPwd, $secretKeyFile);

            if ($encrypted === null) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Chiffrement du mot de passe Windows impossible']);
                exit();
            }

            $encPwd = $encrypted;
        }

        $cleanWinrm[] = [
            'id' => (string)($win['id'] ?? ('win_' . uniqid())),
            'name' => $name !== '' ? $name : ($domain !== '' ? "$domain\\$user" : $user),
            'domain' => $domain,
            'user' => $user,
            'enc_password' => $encPwd,
        ];
    }

    $dataToSave = [
        'local_user' => $localUser,
        'ssh_accounts' => $cleanSsh,
        'winrm_accounts' => $cleanWinrm,
    ];

    $json = json_encode(
        $dataToSave,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    if ($json === false || file_put_contents($accountsFile, $json, LOCK_EX) === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Impossible d’écrire dans accounts.json']);
        exit();
    }

    echo json_encode(['success' => true]);
    exit();
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Action inconnue']);
