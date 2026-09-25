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
$jsonPath = '/opt/supervision/data/servers.json';
$existingServers = file_exists($jsonPath) ? (json_decode(file_get_contents($jsonPath), true) ?? []) : [];
$pwMap = [];
foreach ($existingServers as $s) { 
    if (!empty($s['name']) && !empty($s['enc_password'])) { 
        $pwMap[$s['name']] = $s['enc_password']; 
    } 
}
$updatedServers = [];
foreach ($input['servers'] as $srv) {
    $name = trim($srv['name'] ?? '');
    if (empty($name)) continue;
    $item = [
        'name' => $name,
        'ip' => trim($srv['ip'] ?? ''),
        'type' => trim($srv['type'] ?? 'windows'),
        'user' => trim($srv['user'] ?? '')
    ];
    if (isset($pwMap[$name])) { 
        $item['enc_password'] = $pwMap[$name]; 
    }
    $updatedServers[] = $item;
}
file_put_contents($jsonPath, json_encode($updatedServers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo json_encode(['success' => true]);
