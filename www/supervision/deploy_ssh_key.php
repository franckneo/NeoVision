<?php
require_once '/var/www/common/init.php';
header('Content-Type: application/json');
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['success' => false, 'output' => __('access_denied', 'Accès refusé.')]);
    exit();
}
$input = json_decode(file_get_contents('php://input'), true);
$ip = trim($input['ip'] ?? '');
$remoteUser = trim($input['remote_user'] ?? $input['user'] ?? '');
$password = $input['password'] ?? '';
$localUser = trim($input['local_user'] ?? '');
if (empty($localUser)) {
    $accountsFile = '/opt/supervision/data/accounts.json';
    if (file_exists($accountsFile)) {
        $accData = json_decode(file_get_contents($accountsFile), true);
        $localUser = trim($accData['local_user'] ?? '');
    }
}
if (empty($localUser)) {
    $localUser = 'root';
}
if (empty($ip) || empty($remoteUser) || empty($password)) {
    echo json_encode([
        'success' => false,
        'output' => __('ssh_err_missing_fields', 'Erreur : IP, utilisateur distant ou mot de passe manquant.')
    ]);
    exit();
}
$localHome = ($localUser === 'root') ? '/root' : ('/home/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $localUser));
$sshDir = $localHome . '/.ssh';
$pubKeyPath = $sshDir . '/id_rsa.pub';
$logs = [];
$logs[] = "[*] " . sprintf(__('ssh_checking_key', "Vérification de la clé SSH locale pour '%s' (%s)..."), $localUser, $pubKeyPath);
if ($localUser === 'root') {
    $checkCmd = "test -f " . escapeshellarg($pubKeyPath);
} else {
    $checkCmd = "sudo -n -u " . escapeshellarg($localUser) . " test -f " . escapeshellarg($pubKeyPath);
}
exec($checkCmd . " 2>&1", $checkOut, $checkCode);
if ($checkCode !== 0) {
    $logs[] = "[!] " . sprintf("Erreur : la clé publique '%s' est introuvable pour l'utilisateur '%s'.", $pubKeyPath, $localUser);
    echo json_encode([
        'success' => false,
        'output' => implode("\n", $logs),
        'error' => "Clé SSH introuvable sur le serveur local."
    ]);
    exit();
}
$logs[] = "[✓] " . __('ssh_pubkey_found', 'Clé publique trouvée.');
$logs[] = "[*] " . sprintf(__('ssh_deploying_to', 'Déploiement de la clé vers %s@%s...'), $remoteUser, $ip);
$safePass = escapeshellarg($password);
$safeTarget = escapeshellarg("$remoteUser@$ip");
$safeKey = escapeshellarg($pubKeyPath);
$sshCmd = "/usr/bin/sshpass -p $safePass /usr/bin/ssh-copy-id -i $safeKey -o StrictHostKeyChecking=no -o ConnectTimeout=10 $safeTarget";
if ($localUser !== 'root' && get_current_user() !== $localUser) {
    $cmd = "sudo -n -u " . escapeshellarg($localUser) . " " . $sshCmd . " 2>&1";
} else {
    $cmd = $sshCmd . " 2>&1";
}
exec($cmd, $sshOut, $sshCode);
$fullOutput = trim(implode("\n", $sshOut));
if ($sshCode === 0 || strpos($fullOutput, 'Number of key(s) added') !== false || strpos($fullOutput, 'already exist') !== false) {
    $logs[] = "[✓] " . sprintf(__('ssh_installed_success', 'Clé SSH installée avec succès sur %s (%s) !'), $ip, $remoteUser);
    $logs[] = "[✓] " . __('ssh_nopasswd_ready', 'Connexion sans mot de passe opérationnelle.');
    echo json_encode([
        'success' => true,
        'output' => implode("\n", $logs),
        'message' => __('ssh_installed_success_short', 'Clé déployée avec succès !')
    ]);
} else {
    $logs[] = "[!] " . sprintf(__('ssh_err_deploy_failed', 'Échec lors de l\'envoi (Code %s) :'), $sshCode);
    $logs[] = $fullOutput ?: __('ssh_err_hint', 'Vérifiez sshpass, les identifiants ou les autorisations sudo.');
    echo json_encode([
        'success' => false,
        'output' => implode("\n", $logs),
        'error' => $fullOutput ?: __('ssh_err_hint', 'Échec du déploiement.')
    ]);
}
