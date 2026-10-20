<?php
require_once '/var/www/common/init.php';
require_once '/opt/supervision/mailing/notification.php';
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location:index.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    if ($_POST['action'] === 'test_ping') {
        $ip = trim($_POST['ip'] ?? '');
        if (!filter_var($ip, FILTER_VALIDATE_IP)) { echo json_encode(['success' => false, 'error' => 'IP invalide']); exit(); }
        exec(sprintf("ping -c 2 -W 2 %s", escapeshellarg($ip)), $output, $status);
        echo json_encode(['success' => ($status === 0), 'error' => $status === 0 ? '' : 'Hôte injoignable']);
        exit();
    } elseif ($_POST['action'] === 'save_pcs') {
        $rawPcs = json_decode($_POST['pcs_data'] ?? '[]', true);
        if (!is_array($rawPcs)) { echo json_encode(['success' => false, 'error' => 'Format invalide']); exit(); }
        $usersPcFile = '/opt/supervision/data/pc/users.json';
        $currentData = file_exists($usersPcFile) ? (json_decode(file_get_contents($usersPcFile), true) ?: []) : [];
        if (file_exists($usersPcFile)) @copy($usersPcFile, $usersPcFile . '.bak');
        $newData = [];
        foreach ($rawPcs as $row) {
            $name = trim($row['computer'] ?? '');
            if ($name === '') continue;
            $existing = $currentData[$name] ?? [];
            $u = trim($row['user'] ?? ''); $s = trim($row['site'] ?? ''); $d = trim($row['detail'] ?? ''); $m = trim($row['mac'] ?? '');
            $newData[$name] = array_merge($existing, [
                'user' => ($u !== '' || empty($existing['user'])) ? $u : $existing['user'],
                'site' => ($s !== '' || empty($existing['site'])) ? $s : $existing['site'],
                'detail' => ($d !== '' || empty($existing['detail'])) ? $d : $existing['detail'],
                'mac' => ($m !== '' || empty($existing['mac'])) ? $m : $existing['mac']
            ]);
        }
        $deletedPcs = array_diff(array_keys($currentData), array_keys($newData));
        $wupdateDir = '/mnt/wupdate';
        $warnings = [];
        if (!empty($deletedPcs)) {
            foreach ($deletedPcs as $delPc) {
                $safeName = basename($delPc);
                $candidates = array_unique(["$wupdateDir/$safeName.txt", "$wupdateDir/" . strtoupper($safeName) . ".txt", "$wupdateDir/" . strtolower($safeName) . ".txt"]);
                foreach ($candidates as $filePath) {
                    if (file_exists($filePath) && !@unlink($filePath)) { $warnings[] = "Fichier $safeName non supprimé dans $wupdateDir"; }
                }
            }
        }
        if (file_put_contents($usersPcFile, json_encode($newData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false) {
            $response = ['success' => true];
            if (!empty($warnings)) $response['warning'] = implode("\n", $warnings);
            echo json_encode($response);
        } else {
            echo json_encode(['success' => false, 'error' => "Erreur d'écriture sur $usersPcFile"]);
        }
        exit();
    } elseif ($_POST['action'] === 'save_wifis' || $_POST['action'] === 'save_switchs' || $_POST['action'] === 'save_others') {
        $type = str_replace('save_', '', $_POST['action']);
        $fileMap = ['wifis' => '/opt/supervision/data/wifi.json', 'switchs' => '/opt/supervision/data/switchs.json', 'others' => '/opt/supervision/data/others.json'];
        $filePath = $fileMap[$type] ?? null;
        if (!$filePath) { echo json_encode(['success' => false, 'error' => 'Type invalide']); exit(); }
        $rawData = json_decode($_POST['devices_data'] ?? '{}', true);
        if (!is_array($rawData)) { echo json_encode(['success' => false, 'error' => 'Format invalide']); exit(); }
        $groupedData = [];
        foreach ($rawData as $device) {
            $site = trim($device['site'] ?? 'Defaut');
            if (!isset($groupedData[$site])) $groupedData[$site] = [];
            $devEntry = ['name' => trim($device['name'] ?? ''), 'ip' => trim($device['ip'] ?? '')];
            if ($type === 'others') {
                $devEntry['type'] = trim($device['type'] ?? '');
                if (trim($device['type'] ?? '') === 'snmp') {
                    $devEntry['snmp_version'] = trim($device['snmp_version'] ?? '1');
                    $devEntry['community'] = trim($device['community'] ?? 'public');
                    if ($devEntry['snmp_version'] === '3') {
                        $devEntry['username'] = trim($device['username'] ?? '');
                        $devEntry['privacy_password'] = trim($device['privacy_password'] ?? '');
                    }
                    $devEntry['oid'] = trim($device['oid'] ?? '');
                }
            }
            $groupedData[$site][] = $devEntry;
	    $netConfigFile = '/opt/supervision/data/network_alert_config.json';
            $netConfig = file_exists($netConfigFile) ? json_decode(file_get_contents($netConfigFile), true) : [];
            if (!is_array($netConfig)) $netConfig = [];
            $category = ['wifis' => 'wifis', 'switchs' => 'switchs', 'others' => 'others'][$type];
            $deviceName = $devEntry['name'];
            if ($deviceName !== '' && !isset($netConfig[$category][$deviceName])) $netConfig[$category][$deviceName] = ['enabled' => true];
        }
        $devicesSaved = file_put_contents($filePath, json_encode($groupedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
        $alertsSaved = file_put_contents($netConfigFile, json_encode($netConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
	if ($devicesSaved && $alertsSaved) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => "Erreur d'écriture"]);
        }
        exit();
	} elseif ($_POST['action'] === 'test_mail_config') {
	    $mailConfigPath = '/opt/supervision/data/mail_config.json';
	    $mailConfig = file_exists($mailConfigPath) ? (json_decode(file_get_contents($mailConfigPath), true) ?: []) : [];
	    $recipient = trim((string)($mailConfig['test_recipient'] ?? ''));
	    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) { echo json_encode(['success' => false, 'error' => 'Adresse destinataire du test invalide']); exit(); }
	    try {
	        smtpSend($recipient, 'Test de configuration SMTP', 'Cet email confirme que la configuration SMTP fonctionne.', $mailConfig);
	        echo json_encode(['success' => true, 'recipient' => $recipient]);
	    } catch (Throwable $e) {
	        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
	    }
	    exit();
    } elseif ($_POST['action'] === 'save_mail_config') {
        $mailConfigPath = '/opt/supervision/data/mail_config.json';
        $mailConfig = [
            'sender_name' => trim($_POST['sender_name'] ?? ''),
            'from_email' => trim($_POST['from_email'] ?? ''),
            'reply_to' => trim($_POST['reply_to'] ?? ''),
	    'test_recipient' => trim($_POST['test_recipient'] ?? ''),
            'smtp_mode' => trim($_POST['smtp_mode'] ?? 'local'),
            'smtp_host' => trim($_POST['smtp_host'] ?? ''),
            'smtp_port' => (int)($_POST['smtp_port'] ?? 25),
            'smtp_auth' => ($_POST['smtp_auth'] ?? '0') === '1',
            'smtp_user' => trim($_POST['smtp_user'] ?? ''),
            'smtp_pass' => $_POST['smtp_pass'] ?? '',
            'smtp_secure' => trim($_POST['smtp_secure'] ?? 'none')
        ];
        if (!filter_var($mailConfig['from_email'], FILTER_VALIDATE_EMAIL)) { echo json_encode(['success' => false, 'error' => 'Adresse expéditeur invalide']); exit(); }
        if ($mailConfig['reply_to'] !== '' && !filter_var($mailConfig['reply_to'], FILTER_VALIDATE_EMAIL)) { echo json_encode(['success' => false, 'error' => 'Adresse de réponse invalide']); exit(); }
        if ($mailConfig['smtp_port'] < 1 || $mailConfig['smtp_port'] > 65535) { echo json_encode(['success' => false, 'error' => 'Port SMTP invalide']); exit(); }
        if (file_put_contents($mailConfigPath, json_encode($mailConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false) { echo json_encode(['success' => true]); }
        else { echo json_encode(['success' => false, 'error' => "Erreur d'écriture sur $mailConfigPath"]); }
        exit();
    }
}
$activeTab = isset($_GET['lang']) ? 'langue' : ($_COOKIE['activeParamView'] ?? 'comptes');
$serversJsonPath = '/opt/supervision/data/servers.json';
$serversRaw = file_exists($serversJsonPath) ? (json_decode(file_get_contents($serversJsonPath), true) ?? []) : [];
$servers = isset($serversRaw['servers']) && is_array($serversRaw['servers']) ? $serversRaw['servers'] : $serversRaw;
$accountsJsonPath = '/opt/supervision/data/accounts.json';
$accountsData = file_exists($accountsJsonPath) ? (json_decode(file_get_contents($accountsJsonPath), true) ?? []) : [];
$localSupervisorUser = trim($accountsData['local_user'] ?? '');
$sshAccounts = $accountsData['ssh_accounts'] ?? [];
$usersPcFile = '/opt/supervision/data/pc/users.json';
$pcsList = file_exists($usersPcFile) ? json_decode(file_get_contents($usersPcFile), true) : [];
if (!is_array($pcsList)) $pcsList = [];
$winrmAccounts = $accountsData['winrm_accounts'] ?? [];
$wolJsonPath = '/opt/supervision/data/wol_relays.json';
$wolData = file_exists($wolJsonPath) ? (json_decode(file_get_contents($wolJsonPath), true) ?? []) : [];
$wolRelays = $wolData['relays'] ?? [];
$wifisPath = '/opt/supervision/data/wifi.json';
$wifisRaw = file_exists($wifisPath) ? (json_decode(file_get_contents($wifisPath), true) ?? []) : [];
$wifis = [];
foreach ($wifisRaw as $site => $devices) { if (is_array($devices)) { foreach ($devices as $d) { if (is_array($d)) { $d['site'] = $site; $wifis[] = $d; } } } }
$switchsPath = '/opt/supervision/data/switchs.json';
$switchsRaw = file_exists($switchsPath) ? (json_decode(file_get_contents($switchsPath), true) ?? []) : [];
$switchs = [];
foreach ($switchsRaw as $site => $devices) { if (is_array($devices)) { foreach ($devices as $d) { if (is_array($d)) { $d['site'] = $site; $switchs[] = $d; } } } }
$othersPath = '/opt/supervision/data/others.json';
$othersRaw = file_exists($othersPath) ? (json_decode(file_get_contents($othersPath), true) ?? []) : [];
$others = [];
foreach ($othersRaw as $site => $devices) { if (is_array($devices)) { foreach ($devices as $d) { if (is_array($d)) { $d['site'] = $site; $others[] = $d; } } } }
$LANG = $LANG ?? [];
$mailConfigPath = '/opt/supervision/data/mail_config.json';
$mailConfig = file_exists($mailConfigPath) ? (json_decode(file_get_contents($mailConfigPath), true) ?? []) : [];
$mailConfig = array_merge(['sender_name' => '', 'from_email' => '', 'reply_to' => '', 'smtp_mode' => 'local', 'smtp_host' => '127.0.0.1', 'smtp_port' => 25, 'smtp_auth' => false, 'smtp_user' => '', 'smtp_pass' => '', 'smtp_secure' => 'none'], $mailConfig);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($_currentLang ?? 'fr') ?>">
<head>
<meta charset="UTF-8">
<title><?= __('settings_page_title', 'Paramètres - Supervision') ?></title>
<link rel="icon" href="favicon.ico">
<script>(function(){const isDark=document.cookie.split("; ").reduce((r,v)=>{const p=v.split("=");return p[0].trim()==="darkMode"?p[1]==="enabled":r;},false);if(isDark){document.documentElement.classList.add('dark-mode');}})();</script>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body class="dashboard <?= (!empty($_COOKIE['darkMode']) && $_COOKIE['darkMode'] === 'enabled') ? 'dark-mode' : '' ?>">
<div id="sidebar">
<h2><?= __('settings_title', 'Paramètres') ?></h2>
<div class="sidebar-servers-container">
<button class="btn btn-blue all-disks-link param-nav-btn <?= $activeTab === 'comptes' ? 'active' : '' ?>" data-param="comptes"><?= __('tab_accounts', 'Comptes') ?></button>
<button class="btn btn-blue all-disks-link param-nav-btn <?= $activeTab === 'serveurs' ? 'active' : '' ?>" data-param="serveurs"><?= __('tab_servers', 'Serveurs') ?></button>
<button class="btn btn-blue all-disks-link param-nav-btn <?= $activeTab === 'pcs' ? 'active' : '' ?>" data-param="pcs"><?= __('tab_computers', 'Ordinateurs') ?></button>
<button class="btn btn-blue all-disks-link param-nav-btn <?= $activeTab === 'switchs' ? 'active' : '' ?>" data-param="switchs"><?= __('tab_switchs', 'Switchs') ?></button>
<button class="btn btn-blue all-disks-link param-nav-btn <?= $activeTab === 'wifis' ? 'active' : '' ?>" data-param="wifis"><?= __('tab_wifi', 'Bornes Wifi') ?></button>
<button class="btn btn-blue all-disks-link param-nav-btn <?= $activeTab === 'others' ? 'active' : '' ?>" data-param="others"><?= __('tab_others', 'Autres Équipements') ?></button>
<button class="btn btn-blue all-disks-link param-nav-btn <?= $activeTab === 'emails' ? 'active' : '' ?>" data-param="emails">Emails</button>
<button class="btn btn-blue all-disks-link param-nav-btn <?= $activeTab === 'wol' ? 'active' : '' ?>" data-param="wol"><?= __('tab_wol', 'Relais WOL') ?></button>
<button class="btn btn-blue all-disks-link param-nav-btn <?= $activeTab === 'langue' ? 'active' : '' ?>" data-param="langue"><?= __('tab_language', 'Langue') ?></button>
</div>
</div>
<div id="main">
<div class="header-main">
<h1><?= __('settings_title', 'Paramètres') ?></h1>
<div class="header-buttons">
<a href="dashboard.php" class="btn btn-gray"><?= __('btn_dashboard', 'Dashboard') ?></a>
<button id="toggleDarkMode" class="btn btn-gray"><?= __('btn_darkmode', 'Mode 🌙') ?></button>
<a href="logout.php" class="btn btn-red"><?= __('btn_logout', 'Se déconnecter') ?></a>
</div>
</div>

<div id="view-comptes" class="view-section param-view <?= $activeTab === 'comptes' ? '' : 'view-hidden' ?>">
<div class="stat-card">
<h1><?= __('accounts_main_title', 'Gestion des Comptes & Accès') ?></h1>
<div class="section-header-actions">
<button type="button" id="btnCancelAccounts" class="btn btn-gray" onclick="location.reload();"><?= __('btn_cancel', 'Annuler') ?></button>
<button type="button" id="btnSaveAccounts" class="btn btn-red"><?= __('btn_save', 'Enregistrer') ?></button>
</div>
<p><?= __('accounts_desc', 'Définissez les comptes utilisés pour déployer les clés SSH et pour la connexion WinRM aux serveurs Windows.') ?></p>
<div class="accounts-box">
<h3>⚙️ <?= __('local_supervisor_account', 'Compte local de supervision') ?></h3>
<label><strong><?= __('local_system_user_label', 'Utilisateur système local (Source SSH) :') ?></strong></label>
<input type="text" id="inputLocalSupervisorUser" class="table-input input-local-user" value="<?= htmlspecialchars($localSupervisorUser) ?>" placeholder="ex: user ou root">
<div style="margin-top:8px;display:flex;gap:8px;align-items:center;">
<button type="button" id="btnCheckSshKey" class="btn btn-blue" style="padding:4px 10px;font-size:12px;">Vérifier clé SSH</button>
<button type="button" id="btnGenSshKey" class="btn btn-red" style="padding:4px 10px;font-size:12px;">Générer clé SSH (ed25519)</button>
<span id="sshKeyStatus" style="font-size:12px;"></span>
</div>
</div>
</div>
<h3>🐧 <?= __('ssh_accounts_title', 'Comptes SSH (Ubuntu / Linux)') ?></h3>
<table class="table-servers-config" id="tableSshAccounts">
<thead><tr><th><?= __('col_friendly_name', 'Nom convivial') ?></th><th><?= __('col_remote_user', 'Utilisateur distant') ?></th><th><?= __('col_action', 'Action') ?></th></tr></thead>
<tbody>
<?php foreach ($sshAccounts as $ssh): ?>
<tr data-ssh-id="<?= htmlspecialchars($ssh['id'] ?? '') ?>">
<td><input type="text" class="table-input ssh-name" value="<?= htmlspecialchars($ssh['name'] ?? '') ?>" placeholder="ex: Compte User"></td>
<td><input type="text" class="table-input ssh-user" value="<?= htmlspecialchars($ssh['user'] ?? '') ?>" placeholder="ex: user"></td>
<td class="text-center"><button type="button" class="btn-trash" title="<?= __('action_delete', 'Supprimer') ?>" onclick="removeAccountRow(this)">🗑️</button></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<button type="button" class="btn btn-blue" onclick="addSshRow()">+ <?= __('btn_add_ssh_account', 'Ajouter un compte SSH') ?></button>
<hr style="border:0; border-top:1px solid rgba(255,255,255,0.1); margin:30px 0;">
<h3>🪟 <?= __('winrm_accounts_title', 'Comptes Windows / WinRM') ?></h3>
<table class="table-servers-config" id="tableWinrmAccounts">
<thead><tr><th><?= __('col_friendly_name', 'Nom convivial') ?></th><th><?= __('col_domain', 'Domaine (Optionnel)') ?></th><th><?= __('col_user', 'Utilisateur') ?></th><th><?= __('col_password', 'Mot de passe') ?></th><th><?= __('col_action', 'Action') ?></th></tr></thead>
<tbody>
<?php foreach ($winrmAccounts as $win): ?>
<tr data-win-id="<?= htmlspecialchars($win['id'] ?? '') ?>" data-enc-password="<?= htmlspecialchars($win['enc_password'] ?? '') ?>">
<td><input type="text" class="table-input win-name" value="<?= htmlspecialchars($win['name'] ?? '') ?>" placeholder="ex: Admin Domaine"></td>
<td><input type="text" class="table-input win-domain" value="<?= htmlspecialchars($win['domain'] ?? '') ?>" placeholder="ex: user"></td>
<td><input type="text" class="table-input win-user" value="<?= htmlspecialchars($win['user'] ?? '') ?>" placeholder="ex: admintask"></td>
<td><input type="password" class="table-input win-pwd" placeholder="•••••••• (inchangé)"></td>
<td class="text-center"><button type="button" class="btn-trash" title="<?= __('action_delete', 'Supprimer') ?>" onclick="removeAccountRow(this)">🗑️</button></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<button type="button" class="btn btn-blue" onclick="addWinrmRow()">+ <?= __('btn_add_win_account', 'Ajouter un compte Windows') ?></button>
</div>

<div id="view-serveurs" class="view-section param-view <?= $activeTab === 'serveurs' ? '' : 'view-hidden' ?>">
<div class="stat-card">
<h1><?= __('tab_servers', 'Serveurs') ?></h1>
<div class="section-header-actions">
<button type="button" id="btnCancelServers" class="btn btn-gray" onclick="location.reload();"><?= __('btn_cancel', 'Annuler') ?></button>
<button type="button" id="btnSaveServers" class="btn btn-red">💾 <?= __('btn_save', 'Enregistrer') ?></button>
</div>
<p><?= __('servers_desc', 'Configuration et gestion des serveurs supervisés.') ?></p>
<table class="table-servers-config" id="tableServers">
<thead><tr><th><?= __('col_server_name', 'Nom du serveur') ?></th><th><?= __('col_ip_address', 'Adresse IP') ?></th><th><?= __('col_type', 'Type') ?></th><th><?= __('col_user', 'Utilisateur') ?></th><th><?= __('col_access', 'Accès') ?></th><th><?= __('col_test', 'Test') ?></th><th><?= __('col_order', 'Ordre') ?></th><th><?= __('col_delete', 'Suppr') ?></th></tr></thead>
<tbody>
<?php foreach ($servers as $s): $isLinux = strtolower($s['type'] ?? $s['os'] ?? '') === 'linux'; $sName = htmlspecialchars($s['name'] ?? ''); ?>
<tr class="server-row" data-original-name="<?= htmlspecialchars($s['name'] ?? '') ?>" data-enc-password="<?= htmlspecialchars($s['enc_password'] ?? '') ?>">
<td><input type="text" class="input-srv-name" value="<?= htmlspecialchars($s['name'] ?? '') ?>" placeholder="ex: BSSRV01"></td>
<td><input type="text" class="input-srv-ip" value="<?= htmlspecialchars($s['ip'] ?? '') ?>" placeholder="ex: 10.101.0.31"></td>
<td><select class="server-type-select input-srv-type"><option value="windows" <?= !$isLinux ? 'selected' : '' ?>>Windows</option><option value="linux" <?= $isLinux ? 'selected' : '' ?>>Linux</option></select></td>
<td><input type="text" class="input-srv-user" value="<?= htmlspecialchars($s['user'] ?? '') ?>" placeholder="ex: domain\admin"></td>
<td><?= $isLinux ? '<button type="button" class="btn btn-sm btn-blue btn-ssh-key">'.__('btn_send_key', 'Envoyer la clé').'</button>' : '<button type="button" class="btn btn-sm btn-blue btn-win-pwd">🔑 '.__('btn_pwd', 'MDP').'</button>' ?></td>
<td><button type="button" class="btn btn-sm btn-blue btn-test-conn"><?= __('btn_test', 'Tester') ?></button></td>
<td><button type="button" class="btn-order btn-move-up" title="<?= __('action_move_up', 'Monter') ?>">▲</button><button type="button" class="btn-order btn-move-down" title="<?= __('action_move_down', 'Descendre') ?>">▼</button></td>
<td><button type="button" class="btn-trash btn-delete-srv" title="<?= __('action_delete', 'Supprimer') ?>">🗑️</button></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<button type="button" id="btnAddServer" class="btn btn-blue">+ <?= __('btn_add_server', 'Ajouter un serveur') ?></button>
<div class="section-header-actions">
<button type="button" id="btnCancelServers" class="btn btn-gray" onclick="location.reload();"><?= __('btn_cancel', 'Annuler') ?></button>
<button type="button" class="btn btn-red btnSaveServers">💾 <?= __('btn_save', 'Enregistrer') ?></button>
</div>
</div>
</div>
<div id="view-pcs" class="view-section param-view <?= $activeTab === 'pcs' ? '' : 'view-hidden' ?>">
<div class="stat-card">
<h1><?= __('tab_computers', 'Ordinateurs') ?></h1>
<div class="section-header-actions">
<button type="button" class="btn btn-gray" onclick="location.reload();"><?= __('btn_cancel', 'Annuler') ?></button>
<button type="button" id="btnSavePcs" class="btn btn-red" onclick="savePcs()">💾 <?= __('btn_save', 'Enregistrer') ?></button>
</div>
<p><?= __('computers_desc', 'Ajoutez, modifiez ou supprimez les ordinateurs.') ?></p>
<table class="table-servers-config" id="tablePcs">
<thead><tr><th><?= __('col_computer', 'Ordinateur') ?></th><th><?= __('col_user', 'Utilisateur') ?></th><th><?= __('col_site', 'Site') ?></th><th><?= __('col_details', 'Détails') ?></th><th><?= __('col_mac', 'Adresse MAC') ?></th><th><?= __('col_action', 'Action') ?></th></tr></thead>
<tbody id="pcs-tbody">
<?php if (!empty($pcsList)): ?>
<?php foreach ($pcsList as $computerName => $pc): ?>
<tr class="pc-row">
<td><input type="text" class="table-input pc-input-computer" value="<?= htmlspecialchars((string)$computerName) ?>" placeholder="ex: BELAP275"></td>
<td><input type="text" class="table-input pc-input-user" value="<?= htmlspecialchars($pc['user'] ?? '') ?>" placeholder="ex: Reserve"></td>
<td><input type="text" class="table-input pc-input-site" value="<?= htmlspecialchars($pc['site'] ?? '') ?>" placeholder="ex: Siege"></td>
<td><input type="text" class="table-input pc-input-details" value="<?= htmlspecialchars($pc['detail'] ?? '') ?>" placeholder="ex: Bureau"></td>
<td><input type="text" class="table-input pc-input-mac" value="<?= htmlspecialchars($pc['mac'] ?? '') ?>" placeholder="ex: a8:2b:dd:61:67:d1"></td>
<td class="text-center"><button type="button" class="btn-order btn-move-up" title="<?= __('action_move_up', 'Monter') ?>">▲</button><button type="button" class="btn-order btn-move-down" title="<?= __('action_move_down', 'Descendre') ?>">▼</button><button type="button" class="btn-trash" title="Supprimer" onclick="removeAccountRow(this)">🗑️</button></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
<button type="button" class="btn btn-blue" onclick="addPcRow()">➕ <?= __('btn_add_pc', 'Ajouter un ordinateur') ?></button>
<div class="section-header-actions">
<button type="button" class="btn btn-gray" onclick="location.reload();"><?= __('btn_cancel', 'Annuler') ?></button>
<button type="button" id="btnSavePcs" class="btn btn-red" onclick="savePcs()">💾 <?= __('btn_save', 'Enregistrer') ?></button>
</div></div>
</div>
<div id="view-wifis" class="view-section param-view <?= $activeTab === 'wifis' ? '' : 'view-hidden' ?>">
<div class="stat-card">
<h1><?= __('tab_wifi', 'Bornes Wifi') ?></h1>
<p><?= __('wifis_desc', 'Configuration et gestion des bornes Wifi supervisées.') ?></p>
<div class="section-header-actions">
<button type="button" class="btn btn-gray" onclick="location.reload();"><?= __('btn_cancel', 'Annuler') ?></button>
<button type="button" id="btnSaveWifis" class="btn btn-red" onclick="saveDevices('wifis')">💾 <?= __('btn_save', 'Enregistrer') ?></button>
</div>
<table class="table-servers-config" id="tableWifis">
<thead><tr><th><?= __('col_device_name', 'Nom de la borne') ?></th><th><?= __('col_ip_address', 'Adresse IP') ?></th><th><?= __('col_site', 'Site') ?></th><th><?= __('col_test', 'Test') ?></th><th><?= __('col_action', 'Action') ?></th></tr></thead>
<tbody>
<?php foreach ($wifis as $w): ?>
<tr class="device-row" data-original-name="<?= htmlspecialchars($w['name'] ?? '') ?>">
<td><input type="text" class="input-device-name" value="<?= htmlspecialchars($w['name'] ?? '') ?>" placeholder="ex: WIFI-SIEGE-01"></td>
<td><input type="text" class="input-device-ip" value="<?= htmlspecialchars($w['ip'] ?? '') ?>" placeholder="ex: 10.111.0.100"></td>
<td><input type="text" class="input-device-site" value="<?= htmlspecialchars($w['site'] ?? '') ?>" placeholder="ex: Siege"></td>
<td class="text-center"><button type="button" class="btn btn-sm btn-blue btn-test-device"><?= __('btn_test', 'Tester') ?></button></td>
<td class="text-center"><button type="button" class="btn-order btn-move-up" title="<?= __('action_move_up','Monter') ?>">▲</button><button type="button" class="btn-order btn-move-down" title="<?= __('action_move_down','Descendre') ?>">▼</button><button type="button" class="btn-trash btn-delete-device" title="<?= __('action_delete', 'Supprimer') ?>">🗑️</button></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<button type="button" id="btnAddWifi" class="btn btn-blue" onclick="addDeviceRow('wifis')">+ <?= __('btn_add_device', 'Ajouter une borne') ?></button>
<div class="section-header-actions">
<button type="button" class="btn btn-gray" onclick="location.reload();"><?= __('btn_cancel', 'Annuler') ?></button>
<button type="button" id="btnSaveWifis" class="btn btn-red" onclick="saveDevices('wifis')">💾 <?= __('btn_save', 'Enregistrer') ?></button>
</div>
</div>
</div>
<div id="view-switchs" class="view-section param-view <?= $activeTab === 'switchs' ? '' : 'view-hidden' ?>">
<div class="stat-card">
<h1><?= __('tab_switchs', 'Switchs') ?></h1>
<div class="section-header-actions">
<button type="button" id="btnCancelSwitchs" class="btn btn-gray" onclick="location.reload();"><?= __('btn_cancel', 'Annuler') ?></button>
<button type="button" id="btnSaveSwitchs" class="btn btn-red" onclick="saveDevices('switchs')">💾 <?= __('btn_save', 'Enregistrer') ?></button>
</div>
<p><?= __('switchs_desc', 'Configuration et gestion des switchs supervisés.') ?></p>
<table class="table-servers-config" id="tableSwitchs">
<thead><tr><th><?= __('col_device_name', 'Nom du switch') ?></th><th><?= __('col_ip_address', 'Adresse IP') ?></th><th><?= __('col_site', 'Site') ?></th><th><?= __('col_test', 'Test') ?></th><th><?= __('col_action', 'Action') ?></th></tr></thead>
<tbody>
<?php foreach ($switchs as $s): ?>
<tr class="device-row" data-original-name="<?= htmlspecialchars($s['name'] ?? '') ?>">
<td><input type="text" class="input-device-name" value="<?= htmlspecialchars($s['name'] ?? '') ?>" placeholder="ex: SW-SIEGE-01"></td>
<td><input type="text" class="input-device-ip" value="<?= htmlspecialchars($s['ip'] ?? '') ?>" placeholder="ex: 10.111.0.124"></td>
<td><input type="text" class="input-device-site" value="<?= htmlspecialchars($s['site'] ?? '') ?>" placeholder="ex: Siege"></td>
<td class="text-center"><button type="button" class="btn btn-sm btn-blue btn-test-device"><?= __('btn_test', 'Tester') ?></button></td>
<td class="text-center"><button type="button" class="btn-order btn-move-up" title="<?= __('action_move_up','Monter') ?>">▲</button><button type="button" class="btn-order btn-move-down" title="<?= __('action_move_down','Descendre') ?>">▼</button><button type="button" class="btn-trash btn-delete-device" title="<?= __('action_delete', 'Supprimer') ?>">🗑️</button></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<button type="button" id="btnAddSwitch" class="btn btn-blue" onclick="addDeviceRow('switchs')">+ <?= __('btn_add_device', 'Ajouter un switch') ?></button>
<div class="section-header-actions">
<button type="button" class="btn btn-gray" onclick="location.reload();"><?= __('btn_cancel', 'Annuler') ?></button>
<button type="button" class="btn btn-red" onclick="saveDevices('switchs')">💾 <?= __('btn_save', 'Enregistrer') ?></button>
</div>
</div>
</div>
<div id="view-others" class="view-section param-view <?= $activeTab === 'others' ? '' : 'view-hidden' ?>">
<div class="stat-card">
<h1><?= __('tab_others', 'Autres Équipements') ?></h1>
<div class="section-header-actions">
<button type="button" id="btnCancelOthers" class="btn btn-gray" onclick="location.reload();"><?= __('btn_cancel', 'Annuler') ?></button>
<button type="button" id="btnSaveOthers" class="btn btn-red" onclick="saveDevices('others')">💾 <?= __('btn_save', 'Enregistrer') ?></button>
</div>
<p><?= __('others_desc', 'Configuration et gestion des autres équipements supervisés.') ?></p>
<table class="table-servers-config" id="tableOthers">
<thead><tr><th><?= __('col_device_name', 'Nom de l\'équipement') ?></th><th><?= __('col_ip_address', 'Adresse IP') ?></th><th><?= __('col_site', 'Site') ?></th><th><?= __('col_type', 'Type') ?></th><th><?= __('col_snmp', 'SNMP') ?></th><th><?= __('col_test', 'Test') ?></th><th><?= __('col_action', 'Action') ?></th></tr></thead>
<tbody>
<?php foreach ($others as $o): ?>
<tr class="device-row" data-original-name="<?= htmlspecialchars($o['name'] ?? '') ?>">
<td><input type="text" class="input-device-name" value="<?= htmlspecialchars($o['name'] ?? '') ?>" placeholder="ex: UPS-SIEGE-01"></td>
<td><input type="text" class="input-device-ip" value="<?= htmlspecialchars($o['ip'] ?? '') ?>" placeholder="ex: 10.101.0.200"></td>
<td><input type="text" class="input-device-site" value="<?= htmlspecialchars($o['site'] ?? '') ?>" placeholder="ex: Siege"></td>
<td><select class="input-device-type"><option value="">--</option><option value="interface" <?= ($o['type'] ?? '') === 'interface' ? 'selected' : '' ?>>Interface</option><option value="snmp" <?= ($o['type'] ?? '') === 'snmp' ? 'selected' : '' ?>>SNMP</option></select></td>
<td class="text-center">
<button type="button" class="btn-snmp-config btn-sm btn-blue <?= ($o['type'] ?? '') === 'snmp' ? '' : 'view-hidden' ?>" data-version="<?= htmlspecialchars($o['snmp_version'] ?? '1') ?>" data-community="<?= htmlspecialchars($o['community'] ?? 'public') ?>" data-oid="<?= htmlspecialchars($o['oid'] ?? '') ?>">⚙️ SNMP</button>
</td>
<td class="text-center"><button type="button" class="btn btn-sm btn-blue btn-test-device"><?= __('btn_test', 'Tester') ?></button></td>
<td class="text-center"><button type="button" class="btn-order btn-move-up" title="<?= __('action_move_up','Monter') ?>">▲</button><button type="button" class="btn-order btn-move-down" title="<?= __('action_move_down','Descendre') ?>">▼</button><button type="button" class="btn-trash btn-delete-device" title="<?= __('action_delete', 'Supprimer') ?>">🗑️</button></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<button type="button" id="btnAddOther" class="btn btn-blue" onclick="addDeviceRow('others')">+ <?= __('btn_add_device', 'Ajouter un équipement') ?></button>
<div class="section-header-actions">
<button type="button" class="btn btn-gray" onclick="location.reload();"><?= __('btn_cancel', 'Annuler') ?></button>
<button type="button" class="btn btn-red" onclick="saveDevices('others')">💾 <?= __('btn_save', 'Enregistrer') ?></button>
</div>
</div>
</div>
<div id="view-wol" class="view-section param-view <?= $activeTab === 'wol' ? '' : 'view-hidden' ?>">
<div class="stat-card">
<h1><?= __('wol_relays_title', 'Relais Wake-on-LAN') ?></h1>
<div class="section-header-actions">
<button type="button" class="btn btn-blue" id="btnOpenAddWolModal">➕ <?= __('btn_add_relay', 'Ajouter un relais') ?></button>
</div>
<p><?= __('wol_relays_desc', 'Définissez les passerelles / machines Linux locales servant de relais Wake-on-LAN pour réveiller les postes situés sur des sous-réseaux distants.') ?></p>
<table class="table-servers-config" id="tableWolRelays">
<thead><tr><th><?= __('col_relay_name_site', 'Nom du relais / Site') ?></th><th><?= __('col_gateway_ip', 'IP de la passerelle') ?></th><th><?= __('col_subnets', 'Sous-réseaux desservis (CIDR)') ?></th><th><?= __('col_remote_user', 'Utilisateur distant') ?></th><th><?= __('col_action', 'Action') ?></th></tr></thead>
<tbody>
<?php if (empty($wolRelays)): ?>
<tr id="noWolRow"><td colspan="5"><?= __('no_wol_relay', 'Aucun relais configuré (les paquets sont envoyés depuis le serveur local).') ?></td></tr>
<?php else: ?>
<?php foreach ($wolRelays as $relay): ?>
<tr data-alias="<?= htmlspecialchars($relay['alias'] ?? '') ?>">
<td><strong><?= htmlspecialchars($relay['name'] ?? '') ?></strong></td>
<td><?= htmlspecialchars($relay['ip'] ?? '') ?></td>
<td><code><?= htmlspecialchars(implode(', ', $relay['subnets'] ?? [])) ?></code></td>
<td><?= htmlspecialchars($relay['remote_user'] ?? '') ?></td>
<td class="text-center"><button type="button" class="btn btn-red btn-sm" onclick="deleteWolRelay('<?= htmlspecialchars($relay['alias'] ?? '') ?>', '<?= htmlspecialchars($relay['name'] ?? '') ?>')">🗑️</button></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
<div id="view-langue" class="view-section param-view <?= $activeTab === 'langue' ? '' : 'view-hidden' ?>">
<div class="stat-card">
<h1><?= __('lang_settings_title', 'Langue') ?></h1>
<p><?= __('lang_settings_desc', "Choisir la langue de l'interface.") ?></p>
<div class="lang-selector-vertical">
<a href="?lang=de" class="btn btn-lang <?= ($_currentLang === 'de') ? 'btn-blue' : 'btn-gray' ?>">🇩🇪 &nbsp;&nbsp;Deutsch</a>
<a href="?lang=en" class="btn btn-lang <?= ($_currentLang === 'en') ? 'btn-blue' : 'btn-gray' ?>">🇬🇧 &nbsp;&nbsp;English</a>
<a href="?lang=es" class="btn btn-lang <?= ($_currentLang === 'es') ? 'btn-blue' : 'btn-gray' ?>">🇪🇸 &nbsp;&nbsp;Español</a>
<a href="?lang=fr" class="btn btn-lang <?= ($_currentLang === 'fr') ? 'btn-blue' : 'btn-gray' ?>">🇫🇷 &nbsp;&nbsp;Français</a>
<a href="?lang=it" class="btn btn-lang <?= ($_currentLang === 'it') ? 'btn-blue' : 'btn-gray' ?>">🇮🇹 &nbsp;&nbsp;Italiano</a>
<a href="?lang=nl" class="btn btn-lang <?= ($_currentLang === 'nl') ? 'btn-blue' : 'btn-gray' ?>">🇳🇱 &nbsp;&nbsp;Nederlands</a>
<a href="?lang=pt" class="btn btn-lang <?= ($_currentLang === 'pt') ? 'btn-blue' : 'btn-gray' ?>">🇵🇹 &nbsp;&nbsp;Português</a>
</div>
</div>
</div>
<div id="view-emails" class="view-section param-view <?= $activeTab === 'emails' ? '' : 'view-hidden' ?>">
<div class="stat-card">
<h1>Configuration des emails</h1>
<p>Paramètres SMTP utilisés pour l'envoi des notifications.</p>
<div class="modal-section"><label><strong>Nom de l'expéditeur</strong></label><input type="text" id="mailSenderName" class="field-input" value="<?= htmlspecialchars($mailConfig['sender_name'], ENT_QUOTES, 'UTF-8') ?>"></div>
<div class="modal-section"><label><strong>Adresse expéditeur</strong></label><input type="email" id="mailFromEmail" class="field-input" value="<?= htmlspecialchars($mailConfig['from_email'], ENT_QUOTES, 'UTF-8') ?>"></div>
<div class="modal-section"><label><strong>Adresse Reply-To</strong></label><input type="email" id="mailReplyTo" class="field-input" value="<?= htmlspecialchars($mailConfig['reply_to'], ENT_QUOTES, 'UTF-8') ?>"></div>
<div class="modal-section"><label><strong>Adresse destinataire du test</strong></label><input type="email" id="mailTestRecipient" class="field-input" value="<?= htmlspecialchars($mailConfig['test_recipient'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></div>
<div class="modal-section"><label><strong>Mode SMTP</strong></label><select id="mailSmtpMode" class="field-input"><option value="local" <?= $mailConfig['smtp_mode'] === 'local' ? 'selected' : '' ?>>Relais local</option><option value="remote" <?= $mailConfig['smtp_mode'] === 'remote' ? 'selected' : '' ?>>Serveur SMTP distant</option></select></div>
<div class="modal-section"><label><strong>Hôte SMTP</strong></label><input type="text" id="mailSmtpHost" class="field-input" value="<?= htmlspecialchars($mailConfig['smtp_host'], ENT_QUOTES, 'UTF-8') ?>"></div>
<div class="modal-section"><label><strong>Port SMTP</strong></label><input type="number" id="mailSmtpPort" class="field-input" min="1" max="65535" value="<?= (int)$mailConfig['smtp_port'] ?>"></div>
<div class="modal-section"><label><input type="checkbox" id="mailSmtpAuth" <?= $mailConfig['smtp_auth'] ? 'checked' : '' ?>> Authentification SMTP</label></div>
<div class="modal-section" id="mailSmtpAuthFields"><label><strong>Utilisateur SMTP</strong></label><input type="text" id="mailSmtpUser" class="field-input" value="<?= htmlspecialchars($mailConfig['smtp_user'], ENT_QUOTES, 'UTF-8') ?>"></div>
<div class="modal-section" id="mailSmtpPassField"><label><strong>Mot de passe SMTP</strong></label><input type="password" id="mailSmtpPass" class="field-input" autocomplete="new-password" value="<?= htmlspecialchars($mailConfig['smtp_pass'], ENT_QUOTES, 'UTF-8') ?>"></div>
<div class="modal-section"><label><strong>Chiffrement</strong></label><select id="mailSmtpSecure" class="field-input"><option value="none" <?= $mailConfig['smtp_secure'] === 'none' ? 'selected' : '' ?>>Aucun</option><option value="tls" <?= $mailConfig['smtp_secure'] === 'tls' ? 'selected' : '' ?>>STARTTLS</option><option value="ssl" <?= $mailConfig['smtp_secure'] === 'ssl' ? 'selected' : '' ?>>SSL/TLS</option></select></div>
<button type="button" id="btnSaveMailConfig" class="btn btn-red">Enregistrer</button>
<button type="button" id="btnTestMailConfig" class="btn btn-blue">Email de test</button>
</div>
</div>
<div id="snmpModal" class="modal-overlay modal-overlay-snmp view-hidden" role="dialog" aria-modal="true">
<div class="modal-card modal-card-snmp">
<div class="modal-header">
<h2><?= __('snmp_config_title', 'Configuration SNMP') ?></h2>
<button type="button" id="btnCloseSnmpX" class="modal-close-btn" aria-label="Fermer">✕</button>
</div>
<div class="modal-section">
<label><strong><?= __('label_snmp_version', 'Version SNMP') ?></strong></label>
<select id="snmpInputVersion" class="field-select">
<option value="1">SNMP v1</option>
<option value="2c">SNMP v2c</option>
<option value="3">SNMP v3</option>
</select>
</div>
<div class="modal-section">
<label><strong><?= __('label_snmp_community', 'Community / Mot de passe') ?></strong></label>
<input type="text" id="snmpInputCommunity" class="field-input" placeholder="ex: public">
</div>
<div id="snmpV3Fields" class="view-hidden">
<div class="modal-section">
<label><strong>Nom d’utilisateur SNMPv3</strong></label>
<input type="text" id="snmpInputUsername" class="field-input" autocomplete="off">
</div>
<div class="modal-section">
<label><strong>Mot de passe de confidentialité (optionnel)</strong></label>
<input type="password" id="snmpInputPrivacyPassword" class="field-input" autocomplete="new-password">
</div>
</div>
<div class="modal-section">
<label><strong><?= __('label_snmp_oid', 'OID') ?></strong></label>
<input type="text" id="snmpInputOid" class="field-input" placeholder="ex: 1.3.6.1.2.1.1.1.1.0">
</div>
<div class="modal-grid-gap">
<button type="button" id="btnApplySnmp" class="modal-btn modal-btn-apply">Appliquer</button>
<button type="button" id="btnCloseSnmp" class="modal-btn modal-btn-cancel">Annuler</button>
</div>
</div>
</div>
<div id="wolModal" class="modal-overlay modal-overlay-wol view-hidden" role="dialog" aria-modal="true">
<div class="modal-card modal-card-wol">
<div class="modal-header">
<h2><?= __('wol_modal_title', 'Nouveau Relais Wake-on-LAN') ?></h2>
<button type="button" id="btnCloseWolModalX" class="modal-close-btn" aria-label="Fermer">✕</button>
</div>
<div class="modal-grid">
<div>
<div class="modal-section">
<label><strong><?= __('label_relay_name_site', 'Nom du relais / Site') ?></strong></label>
<input type="text" id="wolInputName" class="field-input" placeholder="ex: Relais Charleroi">
</div>
<div class="modal-section">
<label><strong><?= __('label_remote_linux_ip', 'IP de la machine Linux') ?></strong></label>
<input type="text" id="wolInputIp" class="field-input" placeholder="ex: 10.102.0.50">
</div>
<div class="modal-section">
<label><strong><?= __('label_subnets_served', 'Sous-réseaux desservis (CIDR)') ?></strong></label>
<input type="text" id="wolInputSubnets" class="field-input" placeholder="ex: 10.102.0.0/24, 10.102.10.0/24">
</div>
<div class="modal-section">
<label><strong><?= __('label_remote_linux_user', 'Utilisateur Linux') ?></strong></label>
<input type="text" id="wolInputUser" value="user" class="field-input" placeholder="ex: user">
</div>
<div class="modal-section">
<label><strong><?= __('label_remote_sudo_pass', 'Mot de passe sudo') ?></strong></label>
<input type="password" id="wolInputPassword" class="field-input" placeholder="">
</div>
</div>
<div>
<label class="modal-label-strong">Console :</label>
<div id="wolConsole" class="field-input-mono">Prêt...</div>
</div>
</div>
<div class="modal-grid-gap">
<button type="button" id="btnDeployWol" class="modal-btn modal-btn-wol-deploy">🚀 Déployer le relais</button>
<button type="button" id="btnCloseWolModal" class="modal-btn modal-btn-cancel">Annuler</button>
</div>
</div>
</div>
<div id="deleteModal" class="modal-overlay view-hidden" style="display:none;"><div class="modal-card"><div class="modal-header"><h2>Supprimer</h2><button type="button" class="modal-close-btn" onclick="document.getElementById('deleteModal').style.display='none'">✕</button></div><p>Confirmer la suppression ?</p><div class="modal-grid-gap"><button type="button" id="btnConfirmDelete" class="modal-btn modal-btn-danger">Supprimer</button><button type="button" id="btnCancelDelete" class="modal-btn modal-btn-cancel">Annuler</button></div></div></div>
<div id="sshModal" class="modal-overlay modal-overlay-wol view-hidden" style="display:none;"><div class="modal-card modal-card-wol"><div class="modal-header"><h2>Déploiement Clé SSH : <span id="sshModalTarget"></span></h2><button type="button" id="btnHeaderCloseSsh" class="modal-close-btn">✕</button></div><div class="modal-grid"><div><div class="modal-section"><label><strong>IP distante :</strong></label><input type="text" id="sshInputIp" class="field-input" readonly style="background:#2a2a2a;color:#888;cursor:not-allowed;"></div><div class="modal-section"><label><strong>Profil SSH :</strong></label><select id="sshProfileSelect" class="field-input"><option value="">-- Choisir un compte --</option><?php foreach ($sshAccounts as $ssh): ?><option value="<?= htmlspecialchars($ssh['user'] ?? '') ?>"><?= htmlspecialchars($ssh['name'] ?? $ssh['user']) ?></option><?php endforeach; ?></select></div><input type="hidden" id="sshInputLocalUser" value="<?= htmlspecialchars($localSupervisorUser) ?>"><div class="modal-section"><label><strong>Utilisateur distant :</strong></label><input type="text" id="sshInputUser" class="field-input"></div><div class="modal-section"><label><strong>Mot de passe distant :</strong></label><input type="password" id="sshInputPassword" class="field-input"></div></div><div style="display:flex;flex-direction:column;"><label class="modal-label-strong"><strong>Console :</strong></label><div id="sshConsole" class="field-input-mono" style="flex:1;min-height:220px;background:#0d1117;color:#39d353;padding:10px;border-radius:6px;overflow-y:auto;white-space:pre-wrap;font-family:monospace;font-size:12px;border:1px solid rgba(255,255,255,0.1);">Prêt...</div></div></div><div class="modal-grid-gap"><button type="button" id="btnRunSsh" class="modal-btn modal-btn-wol-deploy">🚀 Déployer la clé</button><button type="button" id="btnCloseSsh" class="modal-btn modal-btn-cancel">Fermer</button></div></div></div>
<div id="winModal" class="modal-overlay view-hidden" style="display:none;"><div class="modal-card"><div class="modal-header"><h2>Compte Windows : <span id="winModalTarget"></span></h2><button type="button" id="btnHeaderCloseWin" class="modal-close-btn">✕</button></div><div class="modal-grid"><div class="modal-section"><label>Profil enregistré :</label><select id="winAccountSelect" class="field-input"><option value="">-- Saisie manuelle --</option><?php foreach ($winrmAccounts as $win): ?><option value="<?= htmlspecialchars($win['id'] ?? '') ?>" data-user="<?= htmlspecialchars(($win['domain'] ? $win['domain'] . '\\' : '') . $win['user']) ?>" data-enc="<?= htmlspecialchars($win['enc_password'] ?? '') ?>"><?= htmlspecialchars($win['name'] ?? $win['user']) ?></option><?php endforeach; ?></select></div><div class="modal-section"><label>Utilisateur (domaine\user) :</label><input type="text" id="winInputUser" class="field-input"></div><div class="modal-section"><label>Mot de passe :</label><input type="password" id="winInputPassword" class="field-input"></div></div><div class="modal-grid-gap"><button type="button" id="btnApplyWin" class="modal-btn modal-btn-wol-deploy">Appliquer</button><button type="button" id="btnCloseWinModal" class="modal-btn modal-btn-cancel">Fermer</button></div></div></div>
<button type="button" id="backToTop" aria-label="Retour en haut"><span>↑</span></button>
<script src="/assets/darkmode.js"></script>
<script src="/assets/js/param-ui.js"></script>
<script src="/assets/js/param-servers.js?v=<?= time() ?>"></script>
</body>
</html>
