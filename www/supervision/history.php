<?php
declare(strict_types=1);
require_once '/var/www/common/init.php';
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location:index.php");
    exit();
}
date_default_timezone_set('Europe/Brussels');
$historyDir = '/opt/supervision/history';
$csvFile = $historyDir . '/alerts.csv';
$activeFile = $historyDir . '/active_alerts.json';

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function parseDateValue(string $value): ?DateTimeImmutable {
    if ($value === '') {
        return null;
    }
    try {
        return new DateTimeImmutable($value, new DateTimeZone('Europe/Brussels'));
    } catch (Exception $e) {
        return null;
    }
}

function durationFromDates(string $start, string $end): string {
    $a = parseDateValue($start);
    $b = parseDateValue($end);
    if (!$a || !$b) {
        return '';
    }
    $seconds = max(0, $b->getTimestamp() - $a->getTimestamp());
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secs = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
}

function durationToSeconds(string $duration): int {
    if (!preg_match('/^(\d+):(\d{2}):(\d{2})$/', $duration, $m)) {
        return 0;
    }
    return ((int)$m[1] * 3600) + ((int)$m[2] * 60) + (int)$m[3];
}

function loadCsv(string $file): array {
    if (!is_readable($file)) {
        return [];
    }
    $handle = fopen($file, 'rb');
    if ($handle === false) {
        return [];
    }
    $header = fgetcsv($handle, 0, ';');
    if (!$header) {
        fclose($handle);
        return [];
    }
    $header = array_map(
        static fn($v) => trim((string)$v),
        $header
    );
    $rows = [];
    while (($data = fgetcsv($handle, 0, ';')) !== false) {
        if ($data === [null] || count($data) === 0) {
            continue;
        }
        if (count($data) === 1 && trim((string)$data[0]) === '') {
            continue;
        }
        $row = [];
        foreach ($header as $index => $field) {
            $row[$field] = isset($data[$index]) ? trim((string)$data[$index]) : '';
        }
        if (empty(implode('', $row))) {
            continue;
        }
        $row['_active'] = false;
        $rows[] = $row;
    }
    fclose($handle);
    return $rows;
}

function translateHistoryField($field, $val) {
    if (!$val) return '';
    if ($field === 'probleme') {
        if (preg_match('/^Espace disque \((CRITICAL|WARNING)\) au-dessus du seuil$/i', $val, $m)) {
            return str_replace('{level}', $m[1], __('hist_prob_disk_above_threshold', $val));
        }
        if (preg_match('/^(.*) ne fonctionne pas normalement$/i', $val, $m)) {
            return str_replace('{service}', $m[1], __('hist_prob_service_abnormal', $val));
        }
        $map = [
            'Serveur hors ligne' => 'hist_prob_server_offline',
            'Utilisation mémoire au-dessus du seuil' => 'hist_prob_ram_above_threshold',
            'Uptime au-dessus du seuil' => 'hist_prob_uptime_above_threshold',
            'Mises à jour disponibles' => 'hist_prob_updates_available',
            'Redémarrage requis' => 'hist_prob_reboot_required',
            'Équipement réseau inaccessible' => 'hist_prob_network_offline',
        ];
        if (isset($map[$val])) {
            return __($map[$val], $val);
        }
    }
    if ($field === 'details') {
        if (preg_match('/^(\d+)\s+pertes consécutives$/i', $val, $m)) {
            return str_replace('{count}', $m[1], __('hist_detail_losses', $val));
        }
        if (preg_match('/^Disque\s+(.+)$/i', $val, $m)) {
            return str_replace('{disk}', $m[1], __('hist_detail_disk', $val));
        }
    }
    if ($field === 'valeur' || $field === 'seuil') {
        if (preg_match('/^(\d+(?:\.\d+)?)\s*jours$/i', $val, $m)) {
            return $m[1] . ' ' . __('hist_unit_days', 'jours');
        }
        if (strtolower($val) === 'oui') return __('val_yes', 'oui');
        if (strtolower($val) === 'non') return __('val_no', 'non');
    }

    return $val;
}

function loadActive(string $file): array {
    if (!is_readable($file)) {
        return [];
    }
    $content = trim((string)@file_get_contents($file));
    if ($content === '') {
        return [];
    }
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

function sortLink(string $field, string $label, string $currentSort, string $currentDir): string {
    $newDir = ($field === $currentSort && $currentDir === 'asc') ? 'desc' : 'asc';
    $query = $_GET;
    $query['sort'] = $field;
    $query['dir'] = $newDir;
    return '<a href="?' . h(http_build_query($query)) . '">' . h($label) . '</a>';
}

$rows = loadCsv($csvFile);
$active = loadActive($activeFile);
$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Brussels'));
$hideUnder10 = isset($_GET['hide_under_10']) && $_GET['hide_under_10'] === '1';
$last7Days = isset($_GET['last_7_days']) && $_GET['last_7_days'] === '1';
$sevenDaysAgo = $now->modify('-7 days');

foreach ($active as $incident) {
    if (!is_array($incident)) {
        continue;
    }
    $start = (string)($incident['debut'] ?? '');
    $duration = durationFromDates($start, $now->format('Y-m-d H:i:s'));
    $rows[] = [
        'debut' => $start,
        'fin' => '',
        'duree' => $duration,
        'equipement' => (string)($incident['equipement'] ?? ''),
        'type' => (string)($incident['type'] ?? ''),
        'probleme' => (string)($incident['probleme'] ?? ''),
        'valeur' => (string)($incident['valeur'] ?? ''),
        'seuil' => (string)($incident['seuil'] ?? ''),
        'ip' => (string)($incident['ip'] ?? ''),
        'details' => (string)($incident['details'] ?? ''),
        '_active' => true,
    ];
}

$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$server = trim((string)($_GET['server'] ?? ''));
$type = trim((string)($_GET['type'] ?? ''));
$status = trim((string)($_GET['status'] ?? 'all'));
$sort = (string)($_GET['sort'] ?? 'debut');
$dir = strtolower((string)($_GET['dir'] ?? 'desc'));
$allowedSort = ['debut', 'fin', 'duree', 'equipement', 'type'];
if (!in_array($sort, $allowedSort, true)) {
    $sort = 'debut';
}
$dir = $dir === 'asc' ? 'asc' : 'desc';

$filtered = array_values(array_filter(
    $rows,
    static function (array $row) use (
        $dateFrom,
        $dateTo,
        $server,
        $type,
        $status,
        $hideUnder10,
        $last7Days,
        $sevenDaysAgo
    ): bool {
        if (
            $hideUnder10 &&
            empty($row['_active']) &&
            durationToSeconds((string)($row['duree'] ?? '')) < 600
        ) {
            return false;
        }
        if ($last7Days) {
            $isActive = !empty($row['_active']);
            $startDate = parseDateValue((string)($row['debut'] ?? ''));
            $isRecent = $startDate && $startDate >= $sevenDaysAgo;
            if (!$isActive && !$isRecent) {
                return false;
            }
        }
        if ($status === 'active' && empty($row['_active'])) {
            return false;
        }
        if ($status === 'closed' && !empty($row['_active'])) {
            return false;
        }
        if ($server !== '' && stripos($row['equipement'] ?? '', $server) === false) {
            return false;
        }
        if ($type !== '' && strcasecmp($row['type'] ?? '', $type) !== 0) {
            return false;
        }
        $start = substr((string)($row['debut'] ?? ''), 0, 10);
        if ($dateFrom !== '' && $start < $dateFrom) {
            return false;
        }
        if ($dateTo !== '' && $start > $dateTo) {
            return false;
        }
        return true;
    }
));

usort(
    $filtered,
    static function (array $a, array $b) use ($sort, $dir): int {
        if ($sort === 'duree') {
            $durationA = durationToSeconds((string)($a['duree'] ?? ''));
            $durationB = durationToSeconds((string)($b['duree'] ?? ''));
            $cmp = $durationA <=> $durationB;
        } elseif ($sort === 'equipement' || $sort === 'type') {
            $cmp = strcasecmp((string)($a[$sort] ?? ''), (string)($b[$sort] ?? ''));
        } else {
            $dateA = parseDateValue((string)($a[$sort] ?? ''));
            $dateB = parseDateValue((string)($b[$sort] ?? ''));
            if (!$dateA && !$dateB) {
                $cmp = 0;
            } elseif (!$dateA) {
                $cmp = -1;
            } elseif (!$dateB) {
                $cmp = 1;
            } else {
                $cmp = $dateA->getTimestamp() <=> $dateB->getTimestamp();
            }
        }
        return $dir === 'asc' ? $cmp : -$cmp;
    }
);

$servers = [];
$types = [];
foreach ($rows as $row) {
    if (($row['equipement'] ?? '') !== '') {
        $servers[$row['equipement']] = true;
    }
    if (($row['type'] ?? '') !== '') {
        $types[$row['type']] = true;
    }
}
ksort($servers, SORT_NATURAL | SORT_FLAG_CASE);
ksort($types, SORT_NATURAL | SORT_FLAG_CASE);

$sortLabels = [
    'debut'      => __('col_start', 'Début'),
    'fin'        => __('col_end', 'Fin'),
    'duree'      => __('col_duration', 'Durée'),
    'equipement' => __('col_equipment', 'Équipement'),
    'type'       => __('col_type', 'Type'),
];
$currentSortLabel = $sortLabels[$sort] ?? $sort;
$currentDirLabel = ($dir === 'asc') 
    ? __('lbl_old_to_recent', 'ancien → récent') 
    : __('lbl_recent_to_old', 'récent → ancien');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($_SESSION['lang'] ?? 'fr') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= __('history_page_title', 'Historique des alertes - NeoVision') ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="history-body <?= !empty($isDark) ? 'dark-mode' : '' ?>">
<div class="header">
    <h1 class="history-title"><?= __('history_title', 'Historique des alertes') ?></h1>
    <div class="header-buttons">
        <a href="dashboard.php" class="btn btn-gray"><?= __('btn_back_home', 'Retour à l\'accueil') ?></a>
        <a href="mailing.php" class="btn btn-blue">✉️</a>
        <button id="toggleDarkMode" class="btn btn-gray"><?= __('btn_dark_mode', 'Mode 🌙') ?></button>
        <a href="logout.php" class="btn btn-red"><?= __('btn_logout', 'Se déconnecter') ?></a>
    </div>
</div>
<div class="history-container">
    <form class="history-filters" method="get">
        <div>
            <label class="history-label" for="date_from"><?= __('lbl_start_date', 'Date début') ?></label>
            <input class="history-input" id="date_from" type="date" name="date_from" value="<?= h($dateFrom) ?>">
        </div>
        <div>
            <label class="history-label" for="date_to"><?= __('lbl_end_date', 'Date fin') ?></label>
            <input class="history-input" id="date_to" type="date" name="date_to" value="<?= h($dateTo) ?>">
        </div>
        <div>
            <label class="history-label" for="server"><?= __('lbl_server_device', 'Serveur / équipement') ?></label>
            <input class="history-input" id="server" type="text" name="server" value="<?= h($server) ?>" placeholder="RDS03...">
        </div>
        <div>
            <label class="history-label" for="type"><?= __('lbl_type', 'Type') ?></label>
            <select class="history-select" id="type" name="type">
                <option value=""><?= __('opt_all', 'Tous') ?></option>
                <?php foreach (array_keys($types) as $value): ?>
                    <option value="<?= h($value) ?>" <?= $type === $value ? 'selected' : '' ?>>
                        <?= h($value) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="history-label" for="status"><?= __('lbl_status', 'État') ?></label>
            <select class="history-select" id="status" name="status">
                <option value="all" <?= $status === 'all' ? 'selected' : '' ?>><?= __('opt_status_all', 'Tous') ?></option>
                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>><?= __('opt_status_active', 'En cours') ?></option>
                <option value="closed" <?= $status === 'closed' ? 'selected' : '' ?>><?= __('opt_status_closed', 'Terminées') ?></option>
            </select>
        </div>
        <div>
            <button class="btn btn-gray" type="submit"><?= __('btn_filter', 'Filtrer') ?></button>
        </div>
        <div>
            <a class="btn btn-gray" href="?"><?= __('btn_reset', 'Réinitialiser') ?></a>
        </div>
        <div class="history-subfilters">
            <label>
                <input type="checkbox" id="hideUnder10Min" name="hide_under_10" value="1" <?= $hideUnder10 ? 'checked' : '' ?> onchange="this.form.submit()">
                <?= __('lbl_hide_under_10', '⏱️ Masquer < 10 min') ?>
            </label>
            <label>
                <input type="checkbox" id="last7DaysBtn" name="last_7_days" value="1" <?= $last7Days ? 'checked' : '' ?> onchange="this.form.submit()">
                <?= __('lbl_7_days', '📅 7 jours') ?>
            </label>
        </div>
    </form>
    <div class="history-summary" id="historyCount">
        <?= sprintf(__('history_summary_format', '%s incident(s) affiché(s) — ordre : %s %s'), count($filtered), h($currentSortLabel), h($currentDirLabel)) ?>
    </div>
    <div class="history-table-wrap">
        <table class="history-table">
            <thead>
                <tr>
                    <th><?= sortLink('debut', __('col_start', 'Début'), $sort, $dir) ?></th>
                    <th><?= sortLink('fin', __('col_end', 'Fin'), $sort, $dir) ?></th>
                    <th><?= sortLink('duree', __('col_duration', 'Durée'), $sort, $dir) ?></th>
                    <th><?= sortLink('equipement', __('col_equipment', 'Équipement'), $sort, $dir) ?></th>
                    <th><?= sortLink('type', __('col_type', 'Type'), $sort, $dir) ?></th>
                    <th><?= __('col_issue', 'Problème') ?></th>
                    <th><?= __('col_value', 'Valeur') ?></th>
                    <th><?= __('col_threshold', 'Seuil') ?></th>
                    <th><?= __('col_ip', 'IP') ?></th>
                    <th><?= __('col_details', 'Détails') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$filtered): ?>
                <tr>
                    <td colspan="10" class="history-muted"><?= __('msg_no_incidents_found', 'Aucun incident trouvé.') ?></td>
                </tr>
            <?php else: ?>
                <?php foreach ($filtered as $row): ?>
                    <tr>
                        <td class="history-nowrap"><?= h((string)($row['debut'] ?? '')) ?></td>
                        <td class="history-nowrap">
                            <?php if (!empty($row['_active'])): ?>
                                <span class="history-active"><?= __('lbl_status_ongoing_badge', 'EN COURS') ?></span>
                            <?php else: ?>
                                <?= h((string)($row['fin'] ?? '')) ?>
                            <?php endif; ?>
                        </td>
                        <td class="history-nowrap"><?= h((string)($row['duree'] ?? '')) ?></td>
                        <td><strong><?= h((string)($row['equipement'] ?? '')) ?></strong></td>
			<td><?= h((string)($row['type'] ?? '')) ?></td>
                        <td><?= h(translateHistoryField('probleme', (string)($row['probleme'] ?? ''))) ?></td>
                        <td><?= h(translateHistoryField('valeur', (string)($row['valeur'] ?? ''))) ?></td>
                        <td><?= h(translateHistoryField('seuil', (string)($row['seuil'] ?? ''))) ?></td>
                        <td class="history-nowrap"><?= h((string)($row['ip'] ?? '')) ?></td>
                        <td><?= h(translateHistoryField('details', (string)($row['details'] ?? ''))) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="/assets/darkmode.js"></script>
</body>
</html>
