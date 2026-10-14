<?php
declare(strict_types=1);
require_once '/var/www/common/init.php';
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: index.php");
    exit();
}
date_default_timezone_set('Europe/Brussels');
$dataDir = '/opt/supervision/data';
$configFile = $dataDir . '/as400_config.json';
if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);
function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function loadJson(string $path, array $default = []): array {
    if (!is_readable($path)) return $default;
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : $default;
}
function saveJson(string $path, array $data): bool {
    return file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}
$config = loadJson($configFile, ['blacklist' => []]);
$config['blacklist'] = is_array($config['blacklist'] ?? null) ? $config['blacklist'] : [];
$dbConfig = require '/var/www/common/as400_db.php';
$pdo = new PDO($dbConfig['dsn'], $dbConfig['user'], $dbConfig['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$syslogLevels = [0 => ['label' => '0 - Emergency'], 1 => ['label' => '1 - Alert'], 2 => ['label' => '2 - Critical'], 3 => ['label' => '3 - Error'], 4 => ['label' => '4 - Warning'], 5 => ['label' => '5 - Notice'], 6 => ['label' => '6 - Info'], 7 => ['label' => '7 - Debug']];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    if ($action === 'toggle_ack' && $id !== false && $id !== null) {
        $stmt = $pdo->prepare('UPDATE SystemEvents SET ack = 1 - ack WHERE ID = ?');
        $stmt->execute([$id]);
    } elseif ($action === 'delete_log' && $id !== false && $id !== null) {
        $stmt = $pdo->prepare('DELETE FROM SystemEvents WHERE ID = ?');
        $stmt->execute([$id]);
    } elseif ($action === 'save_settings') {
        $blacklistRaw = trim((string)($_POST['blacklist'] ?? ''));
        $newBlacklist = [];
        foreach (explode("\n", $blacklistRaw) as $line) {
            $host = trim($line);
            if ($host !== '' && !str_starts_with($host, '#')) $newBlacklist[] = $host;
        }
        $config['blacklist'] = array_values(array_unique($newBlacklist));
        saveJson($configFile, $config);
    }
    header('Location: as400.php?' . http_build_query($_GET));
    exit();
}
$searchQuery = trim((string)($_GET['q'] ?? ''));
$severityFilter = trim((string)($_GET['severity'] ?? 'all'));
$ackFilter = trim((string)($_GET['ack_status'] ?? 'all'));
$where = ["FromHost <> 'proxy-api'"];
$params = [];
if (!empty($config['blacklist'])) {
    $placeholders = implode(',', array_fill(0, count($config['blacklist']), '?'));
    $where[] = "FromHost NOT IN ($placeholders)";
    $params = array_merge($params, $config['blacklist']);
}
if ($severityFilter !== 'all' && ctype_digit($severityFilter) && (int)$severityFilter >= 0 && (int)$severityFilter <= 7) {
    $where[] = 'Priority = ?';
    $params[] = (int)$severityFilter;
}
if ($ackFilter === 'acked') $where[] = 'ack = 1';
if ($ackFilter === 'unacked') $where[] = 'ack = 0';
if ($searchQuery !== '') {
    $where[] = '(FromHost LIKE ? OR Message LIKE ?)';
    $params[] = '%' . $searchQuery . '%';
    $params[] = '%' . $searchQuery . '%';
}
$stmt = $pdo->prepare('SELECT ID, ReceivedAt, FromHost, Message, Priority, ack FROM SystemEvents WHERE ' . implode(' AND ', $where) . ' ORDER BY ID DESC LIMIT 1000');
$stmt->execute($params);
$logs = $stmt->fetchAll();
$filteredLogs = [];
foreach ($logs as $row) {
    $filteredLogs[] = ['id' => (string)$row['ID'], 'timestamp' => (string)$row['ReceivedAt'], 'host' => (string)($row['FromHost'] ?? ''), 'message' => (string)($row['Message'] ?? ''), 'severity' => (int)($row['Priority'] ?? 6), 'ack' => (bool)$row['ack']];
}
$blacklistText = implode("\n", $config['blacklist']);
?>
<!DOCTYPE html>
<html lang="<?= h((string)($_SESSION['lang'] ?? 'fr')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= __('as400_page_title', 'Supervision Syslog') ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="history-body <?= !empty($isDark) ? 'dark-mode' : '' ?>">
<div class="header">
    <h1 class="history-title"><?= __('as400_title', 'Supervision AS400 / Syslog') ?></h1>
    <div class="header-buttons">
        <a href="dashboard.php" class="btn btn-gray"><?= __('btn_back_home', 'Retour à l\'accueil') ?></a>
        <a href="history.php" class="btn btn-gray"><?= __('btn_history', 'Historique') ?></a>
        <a href="mailing.php" class="btn btn-blue">✉️</a>
        <button id="toggleDarkMode" class="btn btn-gray"><?= __('btn_dark_mode', 'Mode 🌙') ?></button>
        <a href="logout.php" class="btn btn-red"><?= __('btn_logout', 'Se déconnecter') ?></a>
    </div>
</div>
<div class="history-container">
    <form class="history-filters" method="get">
        <div>
            <label class="history-label" for="q"><?= __('lbl_search', 'Recherche') ?></label>
            <input class="history-input" id="q" type="text" name="q" value="<?= h($searchQuery) ?>" placeholder="Nom du serveur, texte...">
        </div>
        <div>
            <label class="history-label" for="severity"><?= __('lbl_urgency', 'Urgence Syslog') ?></label>
            <select class="history-select" id="severity" name="severity">
                <option value="all"><?= __('opt_all', 'Tous') ?></option>
                <?php foreach ($syslogLevels as $lvl => $info): ?>
                    <option value="<?= $lvl ?>" <?= $severityFilter === (string)$lvl ? 'selected' : '' ?>><?= h($info['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="history-label" for="ack_status"><?= __('lbl_status_ack', 'État ACK') ?></label>
            <select class="history-select" id="ack_status" name="ack_status">
                <option value="all" <?= $ackFilter === 'all' ? 'selected' : '' ?>><?= __('opt_status_all', 'Tous') ?></option>
                <option value="unacked" <?= $ackFilter === 'unacked' ? 'selected' : '' ?>><?= __('opt_unacked', 'Non acquitté') ?></option>
                <option value="acked" <?= $ackFilter === 'acked' ? 'selected' : '' ?>><?= __('opt_acked', 'Acquitté') ?></option>
            </select>
        </div>
        <div><button class="btn btn-gray" type="submit"><?= __('btn_filter', 'Filtrer') ?></button></div>
        <div><a class="btn btn-gray" href="?"><?= __('btn_reset', 'Réinitialiser') ?></a></div>
        <div><button type="button" class="btn btn-gray" onclick="openSettingsModal()" title="<?= __('btn_settings', 'Paramètres') ?>">⚙️</button></div>
    </form>
    <div class="history-summary" id="historyCount"><?= sprintf(__('as400_summary_count', '%d message(s) affiché(s)'), count($filteredLogs)) ?></div>
    <div class="history-table-wrap">
        <table class="history-table">
            <thead>
                <tr>
                    <th class="col-date-as400"><?= __('col_date', 'Date / Heure') ?></th>
                    <th class="col-server-as400"><?= __('col_servers', 'Serveur') ?></th>
                    <th><?= __('col_message', 'Message') ?></th>
                    <th class="col-urgency-as400"><?= __('col_urgency', 'Urgence') ?></th>
                    <th class="col-ack-as400"><?= __('col_ack', 'ACK') ?></th>
                    <th class="col-action-as400"><?= __('col_delete', 'Action') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$filteredLogs): ?>
                <tr><td colspan="6" class="history-muted text-center p-20"><?= __('msg_no_logs_found', 'Aucun événement AS400 enregistré.') ?></td></tr>
            <?php else: ?>
                <?php foreach ($filteredLogs as $entry):
                    $host = $entry['host'];
                    $sev = $entry['severity'];
                    $isAck = $entry['ack'];
                    $id = $entry['id'];
                ?>
                    <tr class="<?= $isAck ? 'row-acked' : '' ?>">
                        <td class="history-nowrap"><?= h($entry['timestamp']) ?></td>
                        <td><strong><?= h($host) ?></strong></td>
                        <td class="text-break"><?= nl2br(h($entry['message'])) ?></td>
                        <td class="text-center"><span class="badge-sev badge-sev-<?= $sev ?>"><?= h($syslogLevels[$sev]['label'] ?? (string)$sev) ?></span></td>
                        <td class="text-center">
                            <form method="post">
                                <input type="hidden" name="action" value="toggle_ack">
                                <input type="hidden" name="id" value="<?= h($id) ?>">
                                <button type="submit" class="btn-icon" title="<?= $isAck ? 'Marquer non-lu' : 'Acquitter' ?>"><?= $isAck ? '✅' : '⬜' ?></button>
                            </form>
                        </td>
                        <td class="text-center">
                            <form method="post" onsubmit="return confirm('Supprimer ce log ?');">
                                <input type="hidden" name="action" value="delete_log">
                                <input type="hidden" name="id" value="<?= h($id) ?>">
                                <button type="submit" class="btn-icon" title="Supprimer">🗑️</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<div id="settingsModal" class="modal-overlay">
    <div class="modal-card">
        <h2>⚙️ <?= __('title_as400_settings', 'Configuration AS400') ?></h2>
        <form method="post">
            <input type="hidden" name="action" value="save_settings">
            <p><strong><?= __('lbl_blacklist', 'Liste noire Hostnames (anti-spam)') ?></strong></p>
            <small class="modal-hint"><?= __('hint_blacklist_host', 'Un hostname par ligne à ignorer') ?></small>
            <textarea name="blacklist" rows="6"><?= h($blacklistText) ?></textarea>
            <div class="modal-actions">
                <button type="button" class="btn btn-gray" onclick="closeSettingsModal()"><?= __('btn_cancel', 'Annuler') ?></button>
                <button type="submit" class="btn btn-blue"><?= __('btn_save', 'Enregistrer') ?></button>
            </div>
        </form>
    </div>
</div>
<script src="/assets/darkmode.js"></script>
<script>
function openSettingsModal() { document.getElementById('settingsModal').style.display = 'flex'; }
function closeSettingsModal() { document.getElementById('settingsModal').style.display = 'none'; }
window.onclick = function(event) {
    var modal = document.getElementById('settingsModal');
    if (event.target === modal) closeSettingsModal();
}
</script>
</body>
</html>
