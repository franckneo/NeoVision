<?php
require_once '/var/www/common/init.php';
if(!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true){ header("Location:index.php"); exit(); }
$currentLang = $_SESSION['lang'] ?? 'fr';
$uptimeI18n = [
    'lang' => $currentLang,
    'wakeBtn' => __('btn_wake', 'Réveiller'),
    'wakeConfirm' => __('confirm_wake_pc', 'Réveiller %s ?'),
    'waking' => __('btn_waking', 'Envoi...'),
    'woken' => __('btn_woken', 'Envoyé ✓'),
    'retry' => __('btn_retry', 'Réessayer'),
    'sendFailed' => __('err_send_failed', 'Échec de l’envoi'),
    'macMissing' => __('lbl_mac_missing', 'MAC absente'),
    'sinceMin' => __('lbl_since_min', 'Depuis %d min'),
    'sinceHours' => __('lbl_since_hours', 'Depuis %d h'),
    'sinceDays' => __('lbl_since_days', 'Depuis %d j'),
    'dayUnit' => __('lbl_day_unit_short', 'j'),
    'resultSingle' => __('lbl_result_single', '%d résultat'),
    'resultPlural' => __('lbl_result_plural', '%d résultats'),
    'noDataToExport' => __('msg_no_data_to_export', 'Aucune donnée à exporter.')
];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <title><?= __('uptimepc_page_title', 'Status des postes') ?></title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="/assets/style.css?v=4">
</head>
<body class="details uptime-page <?= !empty($isDark) ? 'dark-mode' : '' ?>">
<div id="main">
    <div class="header-main">
        <h1><?= __('uptimepc_main_title', 'Uptime & update des postes') ?></h1>
        <div class="header-buttons">
            <a href="dashboard.php" class="btn btn-gray"><?= __('btn_back_home', 'Retour à l\'accueil') ?></a>
            <button id="btnExportCsv" class="btn btn-blue" onclick="exportFilteredTableToCSV()" title="<?= __('btn_export_csv_title', 'Télécharger les données filtrées en CSV pour Excel') ?>">
            📥 <?= __('btn_export_csv', 'Télécharger (CSV)') ?></button>
            <a href="param.php?tab=pcs" class="btn btn-gray"><?= __('btn_edit-pc', 'Modifier') ?></a>
            <button id="toggleDarkMode" class="btn btn-gray"><?= __('btn_dark_mode', 'Mode 🌙') ?></button>
            <a href="logout.php" class="btn btn-red"><?= __('btn_logout', 'Se déconnecter') ?></a>
        </div>
    </div>
    <div class="stat-card">
        <h3 class="card-title"><?= __('card_pc_status', 'État des machines') ?></h3>
        <div class="search-container">
            <div class="search-input-wrapper">
                <input type="text" id="searchInput" placeholder="<?= __('search_pc_placeholder', '🔍 Rechercher un PC, un détail (ou taper null pour détails vides)...') ?>" oninput="handleSearchInput()">
                <span id="clearSearchBtn" onclick="clearSearch()">&times;</span>
            </div>
            <span id="rowCount">0 <?= __('lbl_results_suffix', 'résultat(s)') ?></span>
            <div class="search-filters">
                <label class="filter-label"><input type="checkbox" id="offline21Checkbox" onchange="filterTable(); saveFilters();"> <strong><?= __('filter_offline_21d', 'Hors ligne > 21j') ?></strong></label>
                <label class="filter-label"><input type="checkbox" id="bigTimeCheckbox" onchange="filterTable(); saveFilters();"> <strong><?= __('filter_big_time', 'Big Time') ?></strong> (<?= __('filter_big_time_desc', '> 15j & en ligne') ?>)</label>
            </div>
        </div>
        <table id="uptimeTable">
            <thead>
                <tr>
                    <th onclick="sortTable('uptimeTable', 0, 'text')"><?= __('col_computer', 'Ordinateur') ?></th>
                    <th><?= __('col_user', 'Utilisateur') ?></th>
                    <th><?= __('col_site', 'Site') ?></th>
                    <th><?= __('col_ip', 'IP') ?></th>
                    <th onclick="sortTable('uptimeTable', 4, 'uptime')"><?= __('col_uptime', 'Uptime') ?></th>
                    <th onclick="sortTable('uptimeTable', 5, 'status')"><?= __('col_status', 'Status') ?></th>
                    <th onclick="sortTable('uptimeTable', 6, 'date')"><?= __('col_last_ok_response', 'Dernière réponse OK') ?></th>
                    <th onclick="sortTable('uptimeTable', 7, 'number')"><?= __('col_updates', 'MAJ') ?></th>
                    <th onclick="sortTable('uptimeTable', 8, 'number')"><?= __('col_since', 'Depuis') ?></th>
                    <th><?= __('col_details', 'Détails') ?></th>
                    <th>WakeMe</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>
<button id="backToTop" title="<?= __('btn_back_to_top', 'Retour en haut') ?>"><span>↑</span></button>
<script>window.UPTIME_I18N = <?= json_encode($uptimeI18n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="/assets/darkmode.js"></script>
<script src="/assets/js/uptimepc.js"></script>
</body>
</html>
