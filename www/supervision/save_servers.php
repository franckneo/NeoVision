<?php
require_once '/var/www/common/init.php';
header('Content-Type: application/json');
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['success' => false, 'error' => __('msg_unauthorized', 'Non autorisé')]);
    exit();
}
$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['servers']) || !is_array($input['servers'])) {
    echo json_encode(['success' => false, 'error' => __('msg_invalid_data', 'Données invalides')]);
    exit();
}
$serversFile = '/opt/supervision/data/servers.json';
$alertConfigFile = '/opt/supervision/data/alert_config.json';
$dataDir = '/opt/supervision/data/';
$oldServersData = file_exists($serversFile) ? json_decode(file_get_contents($serversFile), true) : [];
$oldServers = $oldServersData['servers'] ?? [];
$newServers = [];
$renames = []; // old_name => new_name
foreach ($input['servers'] as $srv) {
    $name = trim($srv['name'] ?? '');
    if (empty($name)) continue;
    $oldName = trim($srv['old_name'] ?? '');
    if (!empty($oldName) && $oldName !== $name) {
        $renames[$oldName] = $name;
    }
    $entry = [
        'name' => $name,
        'ip' => trim($srv['ip'] ?? ''),
        'os' => trim($srv['os'] ?? 'linux'),
        'environment' => trim($srv['environment'] ?? 'prod')
    ];

    if (!empty($srv['user'])) {
        $entry['user'] = trim($srv['user']);
    }
    if (!empty($srv['auth_type'])) {
        $entry['auth_type'] = trim($srv['auth_type']);
    }
    if (isset($srv['password']) && $srv['password'] !== '') {
        $entry['password'] = $srv['password'];
    }

    $newServers[] = $entry;
}
$oldNames = array_column($oldServers, 'name');
$newNames = array_column($newServers, 'name');
$deletedNames = array_diff($oldNames, $newNames, array_keys($renames));
$alertConfig = file_exists($alertConfigFile) ? json_decode(file_get_contents($alertConfigFile), true) : [];
if (!is_array($alertConfig)) {
    $alertConfig = [];
}
foreach ($deletedNames as $delName) {
    unset($alertConfig[$delName]);
    $files = glob($dataDir . $delName . '_*.json');
    if ($files) {
        foreach ($files as $file) {
            @unlink($file);
        }
    }
}
foreach ($renames as $old => $new) {
    if (isset($alertConfig[$old])) {
        $alertConfig[$new] = $alertConfig[$old];
        unset($alertConfig[$old]);
    }
    $files = glob($dataDir . $old . '_*.json');
    if ($files) {
        $prefixOld = $dataDir . $old . '_';
        $prefixNew = $dataDir . $new . '_';
        foreach ($files as $file) {
            if (strpos($file, $prefixOld) === 0) {
                $suffix = substr($file, strlen($prefixOld));
                $target = $prefixNew . $suffix;
                @rename($file, $target);
            }
        }
    }
}
$resultServers = file_put_contents($serversFile, json_encode(['servers' => $newServers], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
file_put_contents($alertConfigFile, json_encode($alertConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
exec('python3 /opt/supervision/build_dashboard.py > /dev/null 2>&1 &');
if ($resultServers !== false) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => __('msg_write_error', 'Erreur d\'écriture')]);
}
