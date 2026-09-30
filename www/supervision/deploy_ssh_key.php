<?php
require_once '/var/www/common/init.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode([
        'success' => false,
        'output' => __('access_denied', 'Accès refusé.')
    ]);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['success' => false, 'output' => 'Requête invalide.']);
    exit();
}

$ip = trim((string)($input['ip'] ?? ''));
$remoteUser = trim((string)($input['remote_user'] ?? $input['user'] ?? ''));
$password = (string)($input['password'] ?? '');
$encPassword = (string)($input['enc_password'] ?? '');
$secretKeyFile = '/opt/supervision/secret.key';

$localUser = trim((string)($input['local_user'] ?? ''));

if ($localUser === '') {
    $accountsFile = '/opt/supervision/data/accounts.json';
    if (is_file($accountsFile)) {
        $accData = json_decode(file_get_contents($accountsFile), true);
        if (is_array($accData)) {
            $localUser = trim((string)($accData['local_user'] ?? ''));
        }
    }
}

if ($localUser === '') {
    $localUser = 'root';
}

if ($password === '' && $encPassword === '') {
    $accountsFile = '/opt/supervision/data/accounts.json';

    if (is_file($accountsFile)) {
        $accData = json_decode(file_get_contents($accountsFile), true);

        foreach (($accData['ssh_accounts'] ?? []) as $account) {
            if (!is_array($account)) {
                continue;
            }

            $accountId = (string)($input['ssh_account_id'] ?? '');
            $accountUser = (string)($account['user'] ?? '');

            if (
                ($accountId !== '' && (string)($account['id'] ?? '') === $accountId)
                || ($accountId === '' && $accountUser === $remoteUser)
            ) {
                $encPassword = (string)($account['enc_password'] ?? '');
                break;
            }
        }
    }
}

if ($password === '' && $encPassword !== '') {
    if (!is_readable($secretKeyFile)) {
        echo json_encode([
            'success' => false,
            'output' => 'Clé de chiffrement absente ou inaccessible.'
        ]);
        exit();
    }

    $pyScript = <<<'PY'
import sys
from cryptography.fernet import Fernet

key_path = sys.argv[1]
with open(key_path, "rb") as key_file:
    fernet = Fernet(key_file.read().strip())

encrypted = sys.stdin.buffer.read()
sys.stdout.write(fernet.decrypt(encrypted).decode())
PY;

    $command = '/usr/bin/python3 -c '
        . escapeshellarg($pyScript) . ' '
        . escapeshellarg($secretKeyFile);

    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ],
        $pipes
    );

    if (!is_resource($process)) {
        echo json_encode([
            'success' => false,
            'output' => 'Impossible de lancer le déchiffrement du mot de passe SSH.'
        ]);
        exit();
    }

    fwrite($pipes[0], $encPassword);
    fclose($pipes[0]);

    $decryptedPassword = stream_get_contents($pipes[1]);
    $decryptError = trim(stream_get_contents($pipes[2]));

    fclose($pipes[1]);
    fclose($pipes[2]);

    $decryptCode = proc_close($process);

    if ($decryptCode !== 0 || $decryptedPassword === '') {
        error_log('NeoVision SSH password decrypt failed: ' . $decryptError);
        echo json_encode([
            'success' => false,
            'output' => 'Impossible de déchiffrer le mot de passe SSH enregistré.'
        ]);
        exit();
    }

    $password = $decryptedPassword;
}

if ($ip === '' || $remoteUser === '' || $password === '') {
    echo json_encode([
        'success' => false,
        'output' => __('ssh_err_missing_fields', 'Erreur : IP, utilisateur distant ou mot de passe manquant.')
    ]);
    exit();
}

$localHome = ($localUser === 'root')
    ? '/root'
    : '/home/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $localUser);

$sshDir = $localHome . '/.ssh';
$keyCandidates = [
    $sshDir . '/id_ed25519.pub',
    $sshDir . '/id_rsa.pub'
];

$pubKeyPath = '';
foreach ($keyCandidates as $candidate) {
    if ($localUser === 'root') {
        $checkCmd = 'test -f ' . escapeshellarg($candidate);
    } else {
        $checkCmd = 'sudo -n -u '
            . escapeshellarg($localUser)
            . ' test -f '
            . escapeshellarg($candidate);
    }

    exec($checkCmd . ' 2>&1', $checkOut, $checkCode);
    if ($checkCode === 0) {
        $pubKeyPath = $candidate;
        break;
    }
}

if ($pubKeyPath === '') {
    echo json_encode([
        'success' => false,
        'output' => "[!] Aucune clé publique Ed25519 ou RSA trouvée pour l'utilisateur local '$localUser'.",
        'error' => 'Clé SSH introuvable sur le serveur local.'
    ]);
    exit();
}

$logs = [];
$logs[] = '[*] ' . sprintf(
    __('ssh_checking_key', "Vérification de la clé SSH locale pour '%s' (%s)..."),
    $localUser,
    $pubKeyPath
);
$logs[] = '[✓] ' . __('ssh_pubkey_found', 'Clé publique trouvée.');
$logs[] = '[*] ' . sprintf(
    __('ssh_deploying_to', 'Déploiement de la clé vers %s@%s...'),
    $remoteUser,
    $ip
);

$safePass = escapeshellarg($password);
$safeTarget = escapeshellarg($remoteUser . '@' . $ip);
$safeKey = escapeshellarg($pubKeyPath);

$sshCmd = '/usr/bin/sshpass -p ' . $safePass
    . ' /usr/bin/ssh-copy-id -i ' . $safeKey
    . ' -o StrictHostKeyChecking=no'
    . ' -o ConnectTimeout=10 '
    . $safeTarget;

if ($localUser !== 'root' && get_current_user() !== $localUser) {
    $cmd = 'sudo -n -u ' . escapeshellarg($localUser) . ' '
        . $sshCmd . ' 2>&1';
} else {
    $cmd = $sshCmd . ' 2>&1';
}

exec($cmd, $sshOut, $sshCode);
$fullOutput = trim(implode("\n", $sshOut));

if (
    $sshCode === 0
    || strpos($fullOutput, 'Number of key(s) added') !== false
    || strpos($fullOutput, 'already exist') !== false
) {
    $logs[] = '[✓] ' . sprintf(
        __('ssh_installed_success', 'Clé SSH installée avec succès sur %s (%s) !'),
        $ip,
        $remoteUser
    );
    $logs[] = '[✓] ' . __('ssh_nopasswd_ready', 'Connexion sans mot de passe opérationnelle.');

    echo json_encode([
        'success' => true,
        'output' => implode("\n", $logs),
        'message' => __('ssh_installed_success_short', 'Clé déployée avec succès !')
    ]);
} else {
    $logs[] = '[!] ' . sprintf(
        __('ssh_err_deploy_failed', "Échec lors de l'envoi (Code %s) :"),
        $sshCode
    );
    $logs[] = $fullOutput ?: __('ssh_err_hint', 'Vérifiez sshpass, les identifiants ou les autorisations sudo.');

    echo json_encode([
        'success' => false,
        'output' => implode("\n", $logs),
        'error' => $fullOutput ?: __('ssh_err_hint', 'Échec du déploiement.')
    ]);
}
