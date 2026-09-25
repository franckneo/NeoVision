<?php
require_once '/var/www/common/init.php';
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location:index.php");
    exit();
}
$networkAlertConfig = json_decode(@file_get_contents('/opt/supervision/data/network_alert_config.json'), true) ?: [];
if (isset($networkAlertConfig['servers']) && is_array($networkAlertConfig['servers'])) {
    foreach ($networkAlertConfig['servers'] as &$srv) {
        if (isset($srv['service_errors']) && is_array($srv['service_errors'])) {
            $srv['service_errors'] = array_values(array_map(function ($e) {
                if (is_string($e)) return $e;
                if (is_array($e)) {
                    $name    = $e['name']    ?? $e['service'] ?? '';
                    $status  = $e['status']  ?? $e['state']    ?? '';
                    $message = $e['message'] ?? $e['error']    ?? '';
                    $parts   = array_filter([$name, $status, $message], fn($x) => $x !== '');
                    return implode(' : ', $parts);
                }
                return (string)$e;
            }, $srv['service_errors']));
        }
    }
    unset($srv);
}
$LANG = $LANG ?? [];
$currentLang = $_SESSION['lang'] ?? 'fr';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang) ?>">
<head>
<meta charset="UTF-8">
<title><?= __('app_title', 'Supervision') ?></title>
<link rel="icon" href="favicon.ico">
<script>(function(){const isDark=document.cookie.split("; ").reduce((r,v)=>{const parts=v.split("=");return parts[0].trim()==="darkMode"?parts[1]==="enabled":r;},false);if(isDark){document.documentElement.classList.add('dark-mode');}})();</script>
<link rel="stylesheet" href="/assets/style.css">
<script src="/assets/js/dashboard.js"></script>
</body>
</html>

