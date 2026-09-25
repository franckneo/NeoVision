<?php
require_once '/var/www/common/init.php';
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => __('msg_unauthorized', 'Non autorisé')]);
    exit();
}
header('Content-Type: application/json; charset=utf-8');
$usersJsonPath = '/opt/supervision/data/pc/users.json';
$wupdateMount = '/mnt/wupdate';
$data = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $data['action'] ?? '';
$currentUsers = file_exists($usersJsonPath) 
    ? (json_decode(file_get_contents($usersJsonPath), true) ?? []) 
    : [];
if ($action === 'save_all') {
    $pcs = $data['pcs'] ?? [];
    $updatedData = [];
    foreach ($pcs as $pc) {
        $computerName = strtoupper(trim($pc['computer'] ?? ''));
        if (empty($computerName)) continue;
        $existing = $currentUsers[$computerName] ?? [];
        $updatedData[$computerName] = array_merge($existing, [
            'user'   => trim($pc['user'] ?? ''),
            'site'   => trim($pc['site'] ?? ''),
            'detail' => trim($pc['detail'] ?? ''),
            'mac'    => strtolower(trim($pc['mac'] ?? ''))
        ]);
    }
    ksort($updatedData, SORT_NATURAL | SORT_FLAG_CASE);
    $tmp = $usersJsonPath . '.tmp';
    if (file_put_contents($tmp, json_encode($updatedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false) {
        rename($tmp, $usersJsonPath);
        echo json_encode([
            'success' => true,
            'message' => __('alert_pcs_saved', 'Postes enregistrés avec succès !')
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'error' => __('msg_cannot_save', 'Impossible de sauvegarder')
        ]);
    }
    exit();
}
if ($action === 'delete_pc') {
    $computerName = strtoupper(trim($data['computer'] ?? ''));
    if (empty($computerName)) {
        echo json_encode([
            'success' => false, 
            'error' => __('msg_invalid_name', 'Nom non valide')
        ]);
        exit();
    }
    if (isset($currentUsers[$computerName])) {
        unset($currentUsers[$computerName]);
        $tmp = $usersJsonPath . '.tmp';
        file_put_contents($tmp, json_encode($currentUsers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        rename($tmp, $usersJsonPath);
    }
    $deletedFiles = [];
    if (is_dir($wupdateMount)) {
        foreach (scandir($wupdateMount) as $file) {
            if (in_array($file, ['.', '..'])) continue;
            $pathInfo = pathinfo($file);
            if (strtoupper($pathInfo['filename']) === $computerName) {
                $fullPath = $wupdateMount . '/' . $file;
                if (@unlink($fullPath)) {
                    $deletedFiles[] = $file;
                }
            }
        }
    }
    echo json_encode([
        'success' => true,
        'deleted_from_mount' => $deletedFiles
    ]);
    exit();
}
echo json_encode([
    'success' => false, 
    'error' => __('msg_unknown_error', 'Erreur inconnue')
]);
