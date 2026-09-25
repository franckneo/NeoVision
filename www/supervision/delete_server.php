<?php
require_once '/var/www/common/init.php';
header('Content-Type: application/json');
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) { 
    echo json_encode(['success' => false, 'error' => __('msg_unauthorized', 'Non autorisé')]); 
    exit(); 
}
$input = json_decode(file_get_contents('php://input'), true);
$serverName = trim($input['name'] ?? '');
if (empty($serverName)) { 
    echo json_encode(['success' => false, 'error' => __('msg_invalid_name', 'Nom invalide')]); 
    exit(); 
}
$jsonPath = '/opt/supervision/data/servers.json';
if (!file_exists($jsonPath)) { 
    echo json_encode(['success' => false, 'error' => __('msg_servers_json_not_found', 'Fichier servers.json introuvable')]); 
    exit(); 
}
$servers = json_decode(file_get_contents($jsonPath), true) ?? [];
$filtered = array_values(array_filter($servers, function($s) use ($serverName) { 
    return ($s['name'] ?? '') !== $serverName; 
}));
if (count($servers) === count($filtered)) { 
    echo json_encode(['success' => false, 'error' => __('msg_server_not_found', 'Serveur non trouvé')]); 
    exit(); 
}
file_put_contents($jsonPath, json_encode($filtered, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
foreach (glob("/opt/supervision/data/{$serverName}_*.json") as $f) { 
    if (is_file($f)) { 
        @unlink($f); 
    } 
}
echo json_encode(['success' => true]);
