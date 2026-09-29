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
<script>
const I18N = <?= json_encode($LANG, JSON_UNESCAPED_UNICODE) ?>;
function t(key, fallback) { return I18N[key] || fallback || key; }
const CURRENT_LOCALE = '<?= ($currentLang === "en") ? "en-US" : (($currentLang === "de") ? "de-DE" : (($currentLang === "es") ? "es-ES" : (($currentLang === "it") ? "it-IT" : (($currentLang === "nl") ? "nl-NL" : (($currentLang === "pt") ? "pt-PT" : "fr-FR"))))) ?>';
</script>
</head>
<body class="dashboard">
<div id="sidebar">
    <a href="all-disks.php" class="btn btn-blue all-disks-link"><?= __('btn_all_disks', 'All Disks') ?></a>
    <h2><?= __('sidebar_servers', 'Serveurs') ?></h2>
    <div class="sidebar-servers-container"><ul id="serverList"></ul></div>
    <div class="sidebar-footer"><a href="param.php" class="btn btn-gray sidebar-param-btn"><?= __('sidebar_settings', 'Paramètres') ?></a></div>
</div>
<div id="main">
<div class="main-nav">
    <button class="main-nav-btn active" data-view="servers">🖥️ <?= __('nav_servers', 'Serveurs') ?></button>
    <button class="main-nav-btn" data-view="switchs">🔀 <?= __('nav_switchs', 'Switchs') ?></button>
    <button class="main-nav-btn" data-view="aps">📡 <?= __('nav_aps', 'Bornes Wi-Fi') ?></button>
    <button class="main-nav-btn" data-view="others">⚙️ <?= __('nav_others', 'Autre') ?></button>
    <div class="header-buttons">
    <a href="uptimePC.php" class="btn btn-gray"><?= __('btn_status_pc', 'Status PC') ?></a>
    <a href="mailing.php" class="btn btn-blue">✉️</a>
    <a href="history.php" class="btn btn-gray"><?= __('btn_history', 'Historique') ?></a>
    <button id="toggleDarkMode" class="btn btn-gray"><?= __('btn_dark_mode', 'Mode 🌙') ?></button>
    <a href="logout.php" class="btn btn-red"><?= __('btn_logout', 'Se déconnecter') ?></a>
    </div>
</div>
<div id="view-servers" class="view-section">
    <div class="header-main">
    <div class="tabs">
    <div class="tab" data-tab="uptime"><?= __('tab_uptime', 'Uptime') ?></div>
    <div class="tab" data-tab="disques"><?= __('tab_disks', 'Disques') ?></div>
    <div class="tab" data-tab="memoire"><?= __('tab_memory', 'Mémoire') ?></div>
    <div class="tab" data-tab="update"><?= __('tab_update', 'Update') ?></div>
    <div class="tab" data-tab="reboot"><?= __('tab_reboot', 'Reboot') ?></div>
    </div>
    </div>
    <div id="uptime" class="tab-content">
    <h1><?= __('title_uptime_supervision', 'Supervision des uptimes') ?> <img src="neovision.png" class="logo"></h1>
    <table id="uptimeTable">
    <thead><tr>
    <th onclick="sortTable('uptimeTable',0,'text')"><?= __('col_name', 'Nom') ?></th>
    <th onclick="sortTable('uptimeTable',1,'uptime')"><?= __('col_uptime', 'Uptime') ?></th>
    <th onclick="sortTable('uptimeTable',2,'date')"><?= __('col_last_update', 'Dernière mise à jour') ?></th>
    <th onclick="sortTable('uptimeTable',3,'status')"><?= __('col_status', 'Statut') ?></th>
    <th><?= __('col_cause', 'Cause') ?></th>
    </tr></thead>
    <tbody></tbody>
    </table>
    </div>
    <div id="disques" class="tab-content">
    <h1><?= __('title_disks_supervision', 'Supervision des disques') ?> <img src="neovision.png" class="logo"></h1>
    <table id="disquesTable">
    <thead><tr>
    <th onclick="sortTable('disquesTable',0,'text')"><?= __('col_name', 'Nom') ?></th>
    <th onclick="sortTable('disquesTable',1,'text')"><?= __('col_disk', 'Disque') ?></th>
    <th onclick="sortTable('disquesTable',2,'size')"><?= __('col_total_size', 'Taille totale') ?></th>
    <th onclick="sortTable('disquesTable',3,'percent')"><?= __('col_disk_usage_percent', 'Utilisation (%)') ?></th>
    <th><?= __('col_evolution', 'Évolution') ?></th>
    <th onclick="sortTable('disquesTable',5,'date')"><?= __('col_last_update', 'Dernière mise à jour') ?></th>
    </tr></thead>
    <tbody></tbody>
    </table>
    </div>
    <div id="memoire" class="tab-content">
    <h1><?= __('title_memory_supervision', 'Supervision de la mémoire') ?> <img src="neovision.png" class="logo"></h1>
    <table id="memoryTable">
    <thead><tr>
    <th onclick="sortTable('memoryTable',0,'text')"><?= __('col_name', 'Nom') ?></th>
    <th onclick="sortTable('memoryTable',1,'percent')"><?= __('col_memory_usage', 'Utilisation mémoire') ?></th>
    <th onclick="sortTable('memoryTable',2,'date')"><?= __('col_last_update', 'Dernière mise à jour') ?></th>
    </tr></thead>
    <tbody></tbody>
    </table>
    </div>
    <div id="update" class="tab-content">
    <div class="tab-header-row">
    <h1><?= __('title_update_supervision', 'Supervision des mises à jour') ?> <img src="neovision.png" class="logo"></h1>
    <div class="tab-header-actions">
    <span id="refreshStatus"></span>
    <button id="refreshNowBtn" class="btn btn-blue">🔄 <?= __('btn_refresh', 'Actualiser') ?></button>
    </div>
    </div>
    <table id="updateTable">
    <thead><tr>
    <th onclick="sortTable('updateTable',0,'text')"><?= __('col_name', 'Nom') ?></th>
    <th onclick="sortTable('updateTable',1,'numeric')"><?= __('col_pending_updates', 'Mises à jour en attente') ?></th>
    </tr></thead>
    <tbody></tbody>
    </table>
    </div>
    <div id="reboot" class="tab-content">
    <div class="tab-header-row">
        <h1><?= __('title_reboot_supervision', 'Serveurs nécessitant un redémarrage') ?> <img src="neovision.png" class="logo"></h1>
        <div class="tab-header-actions">
            <span id="refreshStatusReboot"></span>
            <button id="refreshNowBtnReboot" class="btn btn-blue">🔄 <?= __('btn_refresh', 'Actualiser') ?></button>
        </div>
    </div>
    <table id="rebootTable">
    <thead><tr>
    <th onclick="sortTable('rebootTable',0,'text')"><?= __('col_name', 'Nom') ?></th>
    <th onclick="sortTable('rebootTable',1,'text')"><?= __('col_reboot_required', 'Redémarrage requis') ?></th>
    </tr></thead>
    <tbody></tbody>
    </table>
    </div>
</div>
<div id="view-switchs" class="view-section" style="display:none;">
    <h1><?= __('title_switchs_supervision', 'Supervision des Switchs') ?> <img src="neovision.png" class="logo"></h1>
    <div id="switchsQuadrants" class="network-quadrants"></div>
</div>
<div id="view-aps" class="view-section" style="display:none;">
    <h1><?= __('title_aps_supervision', 'Supervision des Bornes Wi-Fi') ?> <img src="neovision.png" class="logo"></h1>
    <div id="apsQuadrants" class="network-quadrants"></div>
</div>
<div id="view-others" class="view-section" style="display:none;">
    <h1><?= __('title_others_supervision', 'Supervision Services / Interfaces') ?> <img src="neovision.png" class="logo"></h1>
    <div id="othersQuadrants" class="network-quadrants"></div>
</div>
</div>
<script src="/assets/darkmode.js"></script>
<script>window.NETWORK_ALERT_CONFIG = <?= json_encode($networkAlertConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;</script>
<script src="/assets/js/dashboard.js"></script>
</body>
</html>

