<?php
require_once '/var/www/common/init.php';
header('Content-Type: application/json; charset=utf-8');
function writeSaveLog($message) {
    $logFile = '/var/log/supervision/save_servers.log';
    $date = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$date] $message\n", FILE_APPEND);
}
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    writeSaveLog("ACCÈS REFUSÉ : Tentative non autorisée.");
    echo json_encode(['success' => false, 'error' => __('msg_unauthorized', 'Non autorisé')]);
    exit();
}
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!isset($input['servers']) || !is_array($input['servers'])) {
    writeSaveLog("ERREUR : Données JSON reçues invalides. Contenu brut : " . substr($rawInput, 0, 200));
    echo json_encode(['success' => false, 'error' => __('msg_invalid_data', 'Données invalides')]);
    exit();
}
$serversFile = '/opt/supervision/data/servers.json';
$alertConfigFile = '/opt/supervision/data/alert_config.json';
$dataDir = '/opt/supervision/data/';
$oldServersData = file_exists($serversFile) ? json_decode(@file_get_contents($serversFile), true) : [];
$oldServers = [];
if (is_array($oldServersData)) {
    $oldServers = isset($oldServersData['servers']) ? $oldServersData['servers'] : $oldServersData;
}
$oldServersMap = [];
foreach ($oldServers as $s) {
    if (!empty($s['name'])) {
        $oldServersMap[$s['name']] = $s;
    }
}
$newServers = [];
$renames = [];
foreach ($input['servers'] as $srv) {
    $name = trim($srv['name'] ?? '');
    if (empty($name)) {
        continue;
    }
    $oldName = trim($srv['old_name'] ?? '');
    if (!empty($oldName) && $oldName !== $name) {
        $renames[$oldName] = $name;
    }
    $existingKey = (!empty($oldName) && isset($oldServersMap[$oldName])) ? $oldName : (isset($oldServersMap[$name]) ? $name : null);
    $existing = $existingKey ? $oldServersMap[$existingKey] : [];
    $entry = [
        'name' => $name,
        'ip'   => trim($srv['ip'] ?? '')
    ];
    $type = strtolower(trim($srv['type'] ?? $existing['type'] ?? 'windows'));
    $entry['type'] = $type;
    if (!empty($srv['user'])) {
        $entry['user'] = trim($srv['user']);
    } elseif (!empty($existing['user'])) {
        $entry['user'] = $existing['user'];
    }
    if (!empty($srv['enc_password'])) {
        $entry['enc_password'] = trim($srv['enc_password']);
    } elseif (!empty($existing['enc_password'])) {
        $entry['enc_password'] = $existing['enc_password'];
    }
    $newServers[] = $entry;
}
$oldNames = array_column($oldServers, 'name');
$newNames = array_column($newServers, 'name');
$unmatchedOld = array_diff($oldNames, $newNames, array_keys($renames));
$unmatchedNew = array_diff($newNames, $oldNames, array_values($renames));
if (count($unmatchedOld) === 1 && count($unmatchedNew) === 1) {
    $autoOld = reset($unmatchedOld);
    $autoNew = reset($unmatchedNew);
    $renames[$autoOld] = $autoNew;
    writeSaveLog("RENOMMAGE AUTO-DÉTECTÉ : '$autoOld' -> '$autoNew'");
} elseif (!empty($unmatchedOld) && !empty($unmatchedNew)) {
    $oldIpMap = [];
    foreach ($oldServers as $s) {
        if (!empty($s['ip']) && in_array($s['name'], $unmatchedOld, true)) {
            $oldIpMap[$s['ip']] = $s['name'];
        }
    }
    foreach ($newServers as $s) {
        if (!empty($s['ip']) && in_array($s['name'], $unmatchedNew, true) && isset($oldIpMap[$s['ip']])) {
            $matchedOld = $oldIpMap[$s['ip']];
            $renames[$matchedOld] = $s['name'];
            writeSaveLog("RENOMMAGE AUTO-DÉTECTÉ (via IP {$s['ip']}) : '$matchedOld' -> '{$s['name']}'");
        }
    }
}
$alertConfig = file_exists($alertConfigFile) ? json_decode(@file_get_contents($alertConfigFile), true) : [];
if (!is_array($alertConfig)) {
    $alertConfig = [];
}
foreach ($renames as $old => $new) {
    writeSaveLog("APPLICATION RENOMMAGE : '$old' -> '$new'");
    if (isset($alertConfig[$old])) {
        $alertConfig[$new] = $alertConfig[$old];
        unset($alertConfig[$old]);
        writeSaveLog("MIGRATION CONFIG ALERTE : '$old' -> '$new'");
    }
    $files = glob($dataDir . $old . '_*.json');
    if ($files) {
        $prefixOld = $dataDir . $old . '_';
        $prefixNew = $dataDir . $new . '_';
        foreach ($files as $file) {
            if (strpos($file, $prefixOld) === 0) {
                $suffix = substr($file, strlen($prefixOld));
                $target = $prefixNew . $suffix;
                if (file_exists($target)) {
                    @unlink($target);
                }
                if (@rename($file, $target)) {
                    writeSaveLog("RENOMMAGE FICHIER (mv) : " . basename($file) . " -> " . basename($target));
                } else {
                    writeSaveLog("ERREUR RENOMMAGE : Impossible de renommer " . basename($file));
                }
            }
        }
    }
}
$deletedNames = array_diff($oldNames, $newNames, array_keys($renames));
foreach ($deletedNames as $delName) {
    if (isset($alertConfig[$delName])) {
        unset($alertConfig[$delName]);
        writeSaveLog("SUPPRESSION CONFIG ALERTE : Serveur '$delName'");
    }
    $files = glob($dataDir . $delName . '_*.json');
    if ($files) {
        foreach ($files as $file) {
            @unlink($file);
            writeSaveLog("SUPPRESSION FICHIER : " . basename($file));
        }
    }
}
$saveServersResult = @file_put_contents($serversFile, json_encode($newServers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$saveAlertResult = @file_put_contents($alertConfigFile, json_encode($alertConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
if ($saveServersResult === false) {
    writeSaveLog("ERREUR CRITIQUE : Échec d'écriture dans $serversFile (droits www-data ?)");
    echo json_encode(['success' => false, 'error' => "Erreur d'écriture dans servers.json"]);
    exit();
}
writeSaveLog("SUCCÈS : Enregistrement de " . count($newServers) . " serveurs terminé avec succès.");
@exec('python3 /opt/supervision/build_dashboard.py > /dev/null 2>&1 &');
echo json_encode(['success' => true]);
