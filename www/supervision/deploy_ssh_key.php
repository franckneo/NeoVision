<?php
require_once '/var/www/common/init.php';
header('Content-Type: application/json');
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['success' => false, 'output' => __('access_denied', 'Accès refusé.')]);
    exit();
}
$input      = json_decode(file_get_contents('php://input'), true) ?: [];
$ip         = trim($input['ip'] ?? '');
$remoteUser = trim($input['remote_user'] ?? $input['user'] ?? '');
$password   = $input['password'] ?? '';

if ($ip === '' || $remoteUser === '' || $password === '') {
    echo json_encode(['success' => false, 'output' => __('ssh_err_missing_fields', 'Erreur : IP, utilisateur distant ou mot de passe manquant.')]);
    exit();
}
if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    echo json_encode(['success' => false, 'output' => __('ssh_err_invalid_ip', 'Erreur : adresse IP invalide.')]);
    exit();
}
if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.-]{0,31}$/', $remoteUser)) {
    echo json_encode(['success' => false, 'output' => __('ssh_err_invalid_user', 'Erreur : nom d\'utilisateur invalide.')]);
    exit();
}
if (strpos($password, "\n") !== false || strpos($password, "\r") !== false) {
    echo json_encode(['success' => false, 'output' => __('ssh_err_invalid_pass', 'Erreur : le mot de passe ne peut pas contenir de saut de ligne.')]);
    exit();
}
$pubKeyPath = '/var/lib/neovision/.ssh/id_ed25519.pub';
$script     = '/opt/supervision/deploy_ssh.sh';
$logs = [];
$logs[] = "[*] " . sprintf(__('ssh_deploying_to', 'Déploiement de la clé vers %s@%s...'), $remoteUser, $ip);
$cmd = ['/usr/bin/sudo', '-n', '-u', 'neovision', $script, $ip, $remoteUser];
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$proc = proc_open($cmd, $descriptors, $pipes);
if (!is_resource($proc)) {
    $logs[] = "[!] " . __('ssh_err_proc', 'Impossible de lancer le processus de déploiement.');
    echo json_encode(['success' => false, 'output' => implode("\n", $logs), 'error' => 'proc_open failed']);
    exit();
}
fwrite($pipes[0], $password . "\n");
fclose($pipes[0]);
unset($password);
stream_set_timeout($pipes[1], 30);
stream_set_timeout($pipes[2], 30);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$sshCode = proc_close($proc);
$fullOutput = trim($stdout . "\n" . $stderr);
if ($sshCode === 0
    || strpos($fullOutput, 'Number of key(s) added') !== false
    || strpos($fullOutput, 'already exist') !== false) {
    $logs[] = "[OK] " . sprintf(__('ssh_installed_success', 'Clé SSH installée avec succès sur %s (%s) !'), $ip, $remoteUser);
    $logs[] = "[OK] " . __('ssh_nopasswd_ready', 'Connexion sans mot de passe opérationnelle.');
    echo json_encode([
        'success' => true,
        'output'  => implode("\n", $logs),
        'message' => __('ssh_installed_success_short', 'Clé déployée avec succès !'),
    ]);
} else {
    $logs[] = "[!] " . sprintf(__('ssh_err_deploy_failed', 'Échec lors de l\'envoi (Code %s) :'), $sshCode);
    $logs[] = $fullOutput !== '' ? $fullOutput : __('ssh_err_hint', 'Vérifiez les identifiants et la connectivité vers la cible.');
    echo json_encode([
        'success' => false,
        'output'  => implode("\n", $logs),
        'error'   => $fullOutput !== '' ? $fullOutput : __('ssh_err_hint', 'Échec du déploiement.'),
    ]);
}
