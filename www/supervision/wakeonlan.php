<?php
require_once '/var/www/common/init.php';
header('Content-Type: application/json; charset=utf-8');
$logFile = '/var/log/supervision/wakeonlan.log';
function wolLog(string $message): void
{
    global $logFile;
    file_put_contents(
        $logFile,
        '[' . date('c') . '] ' . $message . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}
function wolResponse(
    bool $success,
    string $message,
    int $status = 200
): never {
    http_response_code($status);
    echo json_encode(
        [
            'success' => $success,
            'message' => $message
        ],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}
if (
    !isset($_SESSION['loggedin']) ||
    $_SESSION['loggedin'] !== true
) {
    wolLog('Échec : non authentifié');
    wolResponse(false, __('msg_unauthenticated', 'Non authentifié'), 401);
}
$data = json_decode(
    file_get_contents('php://input'),
    true
) ?? [];
$mac = strtoupper(trim((string)($data['mac'] ?? '')));
$ip  = trim((string)($data['ip'] ?? ''));
if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) {
    wolLog('Échec : MAC invalide');
    wolResponse(false, __('msg_invalid_mac', 'Adresse MAC invalide'), 400);
}
function ipInCidr(string $ip, string $cidr): bool
{
    if (!str_contains($cidr, '/')) {
        return $ip === $cidr;
    }
    [$subnet, $bits] = explode('/', $cidr, 2);
    $ipLong = ip2long($ip);
    $subnetLong = ip2long($subnet);
    $bits = (int)$bits;
    if (
        $ipLong === false ||
        $subnetLong === false ||
        $bits < 0 ||
        $bits > 32
    ) {
        return false;
    }
    if ($bits === 0) {
        return true;
    }
    $mask = -1 << (32 - $bits);
    return (($ipLong & $mask) === ($subnetLong & $mask));
}
$accountsFile = '/opt/supervision/data/accounts.json';
$relaysFile   = '/opt/supervision/data/wol_relays.json';
$localUser    = '';
if (is_readable($accountsFile)) {
    $accounts = json_decode(
        file_get_contents($accountsFile),
        true
    );
    if (is_array($accounts) && !empty($accounts['local_user'])) {
        $localUser = trim($accounts['local_user']);
    }
}
if (empty($localUser)) {
    $localUser = 'root';
}
$localHome = $localUser === 'root'
    ? '/root'
    : "/home/$localUser";
$sshConfigFile = "$localHome/.ssh/config";
$relaysData = is_readable($relaysFile)
    ? json_decode(file_get_contents($relaysFile), true)
    : [];
$targetRelayAlias = null;
foreach (($relaysData['relays'] ?? []) as $relay) {
    foreach (($relay['subnets'] ?? []) as $cidr) {
        if ($ip && ipInCidr($ip, $cidr)) {
            $targetRelayAlias = $relay['alias'] ?? null;
            break 2;
        }
    }
}
if ($targetRelayAlias) {
    $remoteCommand = '/usr/bin/wakeonlan ' . escapeshellarg($mac);
    $cmd =
        '/usr/bin/sudo -n -u ' .
        escapeshellarg($localUser) .
        ' /usr/bin/ssh' .
        ' -F ' . escapeshellarg($sshConfigFile) .
        ' -o ConnectTimeout=5' .
        ' -o StrictHostKeyChecking=no' .
        ' ' . escapeshellarg($targetRelayAlias) .
        ' ' . escapeshellarg($remoteCommand);
    $route = "via $targetRelayAlias";
} else {
    $cmd = '/usr/bin/wakeonlan ' . escapeshellarg($mac);
    $route = 'en direct';
}
$output = [];
$returnCode = 0;
exec($cmd . ' 2>&1', $output, $returnCode);
if ($returnCode !== 0) {
    $error = implode(' ', $output);
    wolLog("Échec WOL $route, code=$returnCode");
    wolResponse(
        false,
        sprintf(__('msg_wol_failure', 'Échec WOL : %s'), ($error ?: __('msg_unknown_error', 'erreur inconnue'))),
        500
    );
}
wolLog("Succès WOL $route");
wolResponse(
    true,
    $targetRelayAlias
        ? sprintf(__('msg_wol_sent_via', 'Paquet envoyé via %s'), $targetRelayAlias)
        : __('msg_wol_sent_direct', 'Paquet envoyé en direct')
);
