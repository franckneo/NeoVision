<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once '/var/www/common/init.php';
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) { header("Location: index.php"); exit(); }
$currentLang = $_SESSION['lang'] ?? 'fr';
$serversFile = "/opt/supervision/data/servers.json";
$configFile = "/opt/supervision/data/alert_config.json";
$netConfigFile = "/opt/supervision/data/network_alert_config.json";
$channelsFile = "/opt/supervision/data/notification_channels.json";
$serversFile = "/opt/supervision/data/servers.json";
$configFile = "/opt/supervision/data/alert_config.json";
$netConfigFile = "/opt/supervision/data/network_alert_config.json";
$channelsFile = "/opt/supervision/data/notification_channels.json";
$rawServersData = json_decode(@file_get_contents($serversFile), true) ?: [];
$rawList = isset($rawServersData['servers']) ? $rawServersData['servers'] : $rawServersData;
$servers = [];
foreach ($rawList as $k => $srv) {
    if (is_array($srv) && !empty($srv['name'])) {
        $servers[$srv['name']] = $srv;
    } elseif (is_string($k)) {
        $servers[$k] = $srv;
    }
}
$config = json_decode(@file_get_contents($configFile), true) ?: [];
$netConfig = json_decode(@file_get_contents($netConfigFile), true) ?: [];
$userChannels = json_decode(@file_get_contents($channelsFile), true) ?: [];
$netCategories = ['switchs' => 'tab-switchs', 'aps' => 'tab-aps', 'others' => 'tab-others'];
function notif_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function getDiskDisplayName($diskName) {
    if (strpos($diskName, 'ubuntu--vg-ubuntu--lv') !== false || strpos($diskName, '--vg-root') !== false) return __('lbl_disk_root_lvm', 'Racine LVM');
    return (strlen($diskName) > 20) ? basename($diskName) : $diskName;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save_channels') {
        $channelsData = $_POST['channels'] ?? [];
        $newChannels = [];
        if (is_array($channelsData)) {
            foreach ($channelsData as $email => $data) {
                $email = trim((string)$email);
                if ($email === '' || !is_array($data)) continue;
                $channel = (($data['type'] ?? 'email') === 'push') ? 'push' : 'email';
                $lang = in_array($data['lang'] ?? 'fr', ['fr', 'en', 'nl', 'de', 'es', 'it', 'pt'], true) ? $data['lang'] : 'fr';
                $newChannels[$email] = [
                    'channel'     => $channel,
                    'push_key'    => $channel === 'push' ? trim((string)($data['push_key'] ?? '')) : '',
                    'push_device' => $channel === 'push' ? trim((string)($data['push_device'] ?? '')) : '',
                    'lang'        => $lang,
                ];
            }
        }
        file_put_contents($channelsFile, json_encode($newChannels, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        header('Location: mailing.php');
        exit();
    }	
    if ($action === 'toggle_global') {
        $serverName = (string)($_POST['server'] ?? '');
        $category = (string)($_POST['category'] ?? '');
        $status = filter_var($_POST['status'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($category === 'servers' && $serverName !== '') {
            if (!isset($config[$serverName]) || !is_array($config[$serverName])) $config[$serverName] = [];
            $config[$serverName]['global_enabled'] = $status;
            file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        } elseif (isset($netConfig[$category]) && $serverName !== '') {
            if (!isset($netConfig[$category][$serverName]) || !is_array($netConfig[$category][$serverName])) $netConfig[$category][$serverName] = [];
            $netConfig[$category][$serverName]['enabled'] = $status;
            file_put_contents($netConfigFile, json_encode($netConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true]);
        exit();
    }
    if (isset($_POST['as400']) && is_array($_POST['as400'])) {
        $as400Data = [];
        foreach (range(0, 7) as $level) {
            $levelData = $_POST['as400'][$level] ?? [];
            $emailsRaw = is_array($levelData) ? (string)($levelData['emails'] ?? '') : '';
            $as400Data[(string)$level] = ['enabled' => is_array($levelData) && isset($levelData['enabled']), 'emails' => array_values(array_filter(array_map('trim', explode(',', $emailsRaw)), static fn($v) => $v !== ''))];
        }
        file_put_contents('/opt/supervision/data/as400_alert_config.json', json_encode($as400Data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    if (isset($_POST['global_settings'])) {
        $config['reminder_interval'] = max(0, (int)($_POST['reminder_interval'] ?? 0));
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        if (!isset($netConfig['reminder_intervals']) || !is_array($netConfig['reminder_intervals'])) {
            $netConfig['reminder_intervals'] = [];
        }
        $netConfig['reminder_intervals']['switchs'] = max(0, (int)($_POST['reminder_interval_switchs'] ?? 0));
        $netConfig['reminder_intervals']['aps']     = max(0, (int)($_POST['reminder_interval_aps'] ?? 0));
        $netConfig['reminder_intervals']['others']  = max(0, (int)($_POST['reminder_interval_others'] ?? 0));
        file_put_contents($netConfigFile, json_encode($netConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        $as400ConfigFile = '/opt/supervision/data/as400_alert_config.json';
        $as400Raw = json_decode(@file_get_contents($as400ConfigFile), true) ?: [];
        $as400Raw['reminder_interval'] = max(0, (int)($_POST['reminder_interval_as400'] ?? 0));
        file_put_contents($as400ConfigFile, json_encode($as400Raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        header('Location: mailing.php');
        exit();
    }
    $postedServers = $_POST['servers'] ?? [];
    if (is_array($postedServers)) {
        foreach ($postedServers as $name => $data) {
            if (!is_array($data) || !isset($config[$name]) || !is_array($config[$name])) {
                if (!is_array($data) || !in_array($name, array_column($servers, 'name'), true)) continue;
                $config[$name] = [];
            }
            $emailsRaw = (string)($data['emails'] ?? '');
            $config[$name]['emails'] = array_values(array_filter(array_map('trim', explode(',', $emailsRaw)), static fn($v) => $v !== ''));
            $config[$name]['time_start'] = preg_match('/^\d{2}:\d{2}$/', (string)($data['start'] ?? '')) ? $data['start'] : '08:00';
            $config[$name]['time_end'] = preg_match('/^\d{2}:\d{2}$/', (string)($data['end'] ?? '')) ? $data['end'] : '20:00';
            if (isset($data['interval'])) {
                $val = trim((string)$data['interval']);
                if ($val === '') {
                    unset($config[$name]['interval']); // hérite du global
                } else {
                    $config[$name]['interval'] = max(0, (int)$val);
                }
            }
            $config[$name]['update_enabled'] = isset($data['update']);
            $config[$name]['reboot_enabled'] = isset($data['reboot']);
            $config[$name]['offline_enabled'] = isset($data['offline']);
            $config[$name]['ram_warn_enabled'] = isset($data['ram_warn_enabled']);
            $config[$name]['ram_warn_threshold'] = max(1, min(100, (int)($data['ram_warn_threshold'] ?? 80)));
            $config[$name]['ram_crit_enabled'] = isset($data['ram_crit_enabled']);
            $config[$name]['ram_crit_threshold'] = max(1, min(100, (int)($data['ram_crit_threshold'] ?? 90)));
            $config[$name]['ram_enabled'] = $config[$name]['ram_warn_enabled'] || $config[$name]['ram_crit_enabled'];
            $config[$name]['ram_threshold'] = $config[$name]['ram_crit_threshold'];
            $config[$name]['uptime_warn_enabled'] = isset($data['uptime_warn_enabled']);
            $config[$name]['uptime_warn_threshold'] = max(1, (int)($data['uptime_warn_threshold'] ?? 15));
            $config[$name]['uptime_crit_enabled'] = isset($data['uptime_crit_enabled']);
            $config[$name]['uptime_crit_threshold'] = max(1, (int)($data['uptime_crit_threshold'] ?? 30));
            $config[$name]['uptime_enabled'] = $config[$name]['uptime_warn_enabled'] || $config[$name]['uptime_crit_enabled'];
            $config[$name]['uptime_threshold'] = $config[$name]['uptime_crit_threshold'];
            foreach (['outlook', 'powerbi', 'stagenow', 'wsus', 'kea_dhcp', 'squid', 'webmin'] as $service) {
                $key = $service . '_enabled';
                if (isset($data[$service])) $config[$name][$key] = true; else unset($config[$name][$key]);
            }
            $postedDisks = (isset($data['disks']) && is_array($data['disks'])) ? $data['disks'] : [];
            $existingDisks = (isset($config[$name]['disks']) && is_array($config[$name]['disks'])) ? $config[$name]['disks'] : [];
            $newDisks = [];
            foreach ($postedDisks as $diskData) {
                if (!is_array($diskData)) continue;
                $diskName = trim((string)($diskData['key'] ?? ''));
                if ($diskName === '' || strlen($diskName) > 255) continue;
                $oldThreshold = isset($existingDisks[$diskName]['threshold']) ? $existingDisks[$diskName]['threshold'] : 90;
                $warnEnabled = isset($diskData['warn_enabled']);
                $warnThreshold = max(1, min(100, (int)($diskData['warn_threshold'] ?? ($oldThreshold - 10))));
                $critEnabled = isset($diskData['crit_enabled']);
                $critThreshold = max(1, min(100, (int)($diskData['crit_threshold'] ?? $oldThreshold)));
                $newDisks[$diskName] = ['warn_enabled' => $warnEnabled, 'warn_threshold' => $warnThreshold, 'crit_enabled' => $critEnabled, 'crit_threshold' => $critThreshold, 'enabled' => ($warnEnabled || $critEnabled), 'threshold' => $critThreshold];
            }
            $config[$name]['disks'] = $newDisks;
        }
    }
    if (isset($_POST['net']) && is_array($_POST['net'])) {
        foreach ($_POST['net'] as $type => $devices) {
            if (!is_array($devices)) continue;
            if (!isset($netConfig[$type]) || !is_array($netConfig[$type])) $netConfig[$type] = [];
            foreach ($devices as $devName => $data) {
                if (!is_array($data)) continue;
                $emailsRaw = (string)($data['emails'] ?? '');
                $emailsArr = array_values(array_filter(array_map('trim', explode(',', $emailsRaw)), static fn($v) => $v !== ''));
                if (!isset($netConfig[$type][$devName]) || !is_array($netConfig[$type][$devName])) $netConfig[$type][$devName] = [];
                $netConfig[$type][$devName]['emails'] = $emailsArr;
		if ($type === 'aps') {
		    if (!isset($netConfig['wifis'][$devName]) || !is_array($netConfig['wifis'][$devName])) $netConfig['wifis'][$devName] = [];
		    $netConfig['wifis'][$devName] = array_merge($netConfig['wifis'][$devName], $netConfig['aps'][$devName]);
		}
                $netConfig[$type][$devName]['time_start'] = $data['start'] ?? '08:00';
                $netConfig[$type][$devName]['time_end'] = $data['end'] ?? '20:00';
                $netConfig[$type][$devName]['ping_enabled'] = isset($data['ping']);
                $netConfig[$type][$devName]['max_loss'] = max(0, min(100, (int)($data['max_loss'] ?? 0)));
		if ($type === 'others') {
		    $netConfig[$type][$devName]['warning_enabled'] = isset($data['warning_enabled']);
		    $netConfig[$type][$devName]['warning_value'] = (float)($data['warning_value'] ?? 0);
		    $netConfig[$type][$devName]['critical_enabled'] = isset($data['critical_enabled']);
		    $netConfig[$type][$devName]['critical_value'] = (float)($data['critical_value'] ?? 0);
		    $unit = trim((string)($data['snmp_unit'] ?? ''));
		    $netConfig[$type][$devName]['snmp_unit'] = mb_substr($unit, 0, 20);
		}
                if (array_key_exists('temp_warn', $data)) {
                    $netConfig[$type][$devName]['temp_warn_enabled'] = isset($data['temp_warn_enabled']);
                    $netConfig[$type][$devName]['temp_warn'] = max(0, min(100, (int)$data['temp_warn']));
                }
                if (array_key_exists('temp_crit', $data)) {
                    $netConfig[$type][$devName]['temp_crit_enabled'] = isset($data['temp_crit_enabled']);
                    $netConfig[$type][$devName]['temp_crit'] = max(0, min(100, (int)$data['temp_crit']));
                }
            }
        }
    }
    foreach (array_merge($netCategories, ['wifis' => 'tab-wifis']) as $type => $tabId) {
        $jsonFile = $type === 'aps' ? '/opt/supervision/data/wifi.json' : "/opt/supervision/data/network_{$type}.json";
        if (!file_exists($jsonFile)) continue;
        $rawNetData = json_decode(file_get_contents($jsonFile), true) ?: [];
        $validDevices = [];
        if (isset($rawNetData['sites'])) {
            foreach ($rawNetData['sites'] as $devs) {
                foreach ($devs as $d) { if (!empty($d['name'])) $validDevices[] = $d['name']; }
            }
        } else {
            foreach ($rawNetData as $d) { if (!empty($d['name'])) $validDevices[] = $d['name']; }
        }
        if (isset($netConfig[$type]) && is_array($netConfig[$type])) {
            foreach (array_keys($netConfig[$type]) as $savedDevName) {
                if (!in_array($savedDevName, $validDevices, true)) unset($netConfig[$type][$savedDevName]);
            }
        }
    }
    file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    file_put_contents($netConfigFile, json_encode($netConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    header('Location: mailing.php');
    exit();
}
$as400ConfigFile = '/opt/supervision/data/as400_alert_config.json';
$as400Config = json_decode(@file_get_contents($as400ConfigFile), true) ?: [];
$allEmailsFound = [];
foreach ($config as $serverConfig) {
    if (!is_array($serverConfig)) continue;
    foreach (($serverConfig['emails'] ?? []) as $email) {
        $email = trim((string)$email);
        if ($email !== '') $allEmailsFound[] = $email;
    }
}
foreach ($netConfig as $categoryConfig) {
    if (!is_array($categoryConfig)) continue;
    foreach ($categoryConfig as $deviceConfig) {
        if (!is_array($deviceConfig)) continue;
        foreach (($deviceConfig['emails'] ?? []) as $email) {
            $email = trim((string)$email);
            if ($email !== '') $allEmailsFound[] = $email;
        }
    }
}
foreach ($as400Config as $levelConfig) {
    if (!is_array($levelConfig)) continue;
    foreach (($levelConfig['emails'] ?? []) as $email) {
        $email = trim((string)$email);
        if ($email !== '') $allEmailsFound[] = $email;
    }
}
$allEmailsFound = array_values(array_unique($allEmailsFound));
sort($allEmailsFound);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang) ?>">
<head>
<meta charset="UTF-8">
<title><?= __('mailing_page_title', 'Configuration Alertes') ?></title>
<link rel="stylesheet" href="/assets/style.css?v=5">
</head>
<body class="mailing-page <?= !empty($isDark) ? 'dark-mode' : '' ?>">
<div class="header">
    <h1><?= __('mailing_main_title', 'Configuration des alertes') ?></h1>
    <div class="header-buttons">
        <a href="dashboard.php" class="btn btn-gray"><?= __('btn_back_home', 'Retour à l\'accueil') ?></a>
        <a href="history.php" class="btn btn-gray"><?= __('btn_history', 'Historique') ?></a>
        <button type="button" class="btn btn-gray" id="toggleDarkMode"><?= __('btn_dark_mode_simple', 'Mode') ?></button>
        <a href="logout.php" class="btn btn-red"><?= __('btn_logout', 'Se déconnecter') ?></a>
    </div>
</div>
<div class="nav-tabs">
   <div class="tab-btn-container">
    <button class="tab-btn active" onclick="switchTab('tab-servers')"><?= __('tab_servers', 'Serveurs') ?></button>
    <button class="tab-btn" onclick="switchTab('tab-switchs')"><?= __('tab_switches', 'Switchs') ?></button>
    <button class="tab-btn" onclick="switchTab('tab-aps')"><?= __('tab_aps', 'Bornes Wi-Fi') ?></button>
    <button class="tab-btn" onclick="switchTab('tab-others')"><?= __('tab_others', 'Autres') ?></button>
    <button class="tab-btn" onclick="switchTab('tab-as400')">AS400</button></div>
    <button type="button" class="btn btn-blue" onclick="openModal('modal_channels')"><?= __('btn_notif_channels', '🔔 Type de notif') ?></button>
</div>
<div class="search-container">
    <div class="search-input-wrapper">
        <input type="text" id="searchInput" placeholder="<?= __('search_equipment_placeholder', '🔍 Rechercher un équipement, une IP, un e-mail...') ?>" oninput="handleSearchInput()">
        <span id="clearSearchBtn" onclick="clearSearch()">&times;</span>
    </div>
    <span id="rowCount">0 <?= __('lbl_results_suffix', 'résultat(s)') ?></span>
    <button type="button" class="btn btn-gray btn-settings-right" onclick="openModal('modal_server_settings')" title="Paramètres">⚙</button>
</div>
<form id="alertForm" method="post" action="mailing.php">
<div id="tab-servers" class="tab-content active">
<table>
<thead>
<tr>
    <th><?= __('col_server', 'Serveur') ?></th></th><th><?= __('col_ip', 'IP') ?><th><?= __('col_type', 'Type') ?></th><th><?= __('col_alerts_enabled', 'Alertes Activées') ?></th><th><?= __('col_notification', 'Notification') ?></th><th><?= __('col_time_range', 'Plage Horaire') ?></th><th class="text-center"><?= __('col_notifications_toggle', 'Notifications') ?></th>
</tr>
</thead>
<tbody>
<?php foreach($servers as $s):
    if(!isset($s['name'])) continue;
    $name = $s['name'];
    $serverIp = $s['ip'] ?? '-';
    $sType = strtolower($s['type'] ?? 'linux');
    $c = $config[$name] ?? [];
    $isGlobalActive = $c['global_enabled'] ?? true;
    $diskJsonFile = "/opt/supervision/data/{$name}_disks.json";
    $detectedDisks = [];
    if (file_exists($diskJsonFile)) {
        $diskDataRaw = json_decode(@file_get_contents($diskJsonFile), true) ?: [];
        $detectedDisks = $diskDataRaw[0]['disks'] ?? [];
    }
    $activeBadges = [];
    if(!empty($c['offline_enabled'])) $activeBadges[] = __('badge_offline', 'Offline');
    if(!empty($c['disks']) && is_array($c['disks'])) {
        foreach($c['disks'] as $dName => $dConf) {
            if(!empty($dConf['enabled'])) $activeBadges[] = __('badge_disk', 'Disque') . ' ' . getDiskDisplayName($dName) . ' (' . ($dConf['threshold'] ?? 90) . '%)';
        }
    }
    if(!empty($c['ram_enabled'])) $activeBadges[] = 'RAM (' . ($c['ram_threshold'] ?? 90) . '%)';
    if(!empty($c['uptime_enabled'])) $activeBadges[] = 'Uptime (' . ($c['uptime_threshold'] ?? 30) . __('lbl_day_unit_short', 'j') . ')';
    if(!empty($c['update_enabled'])) $activeBadges[] = __('badge_updates', 'Updates');
    if(!empty($c['reboot_enabled'])) $activeBadges[] = __('badge_reboot', 'Reboot');
    if($sType === 'linux' && !empty($c['kea_dhcp_enabled'])) $activeBadges[] = "Kea-DHCP";
    if($sType === 'linux' && !empty($c['squid_enabled'])) $activeBadges[] = "Proxy Squid";
    if($sType === 'linux' && !empty($c['webmin_enabled'])) $activeBadges[] = "Webmin";
    if($sType === 'windows' && !empty($c['powerbi_enabled'])) $activeBadges[] = "Power BI";
    if($sType === 'windows' && !empty($c['outlook_enabled'])) $activeBadges[] = "Outlook";
    if($sType === 'windows' && !empty($c['stagenow_enabled'])) $activeBadges[] = "StageNow";
    if($sType === 'windows' && !empty($c['wsus_enabled'])) $activeBadges[] = "WSUS";
    $serverEmails = (array)($c['emails'] ?? []);
    $mailEmails = [];
    $pushEmails = [];
    foreach ($serverEmails as $email) {
        if (($userChannels[$email]['channel'] ?? 'email') === 'push') $pushEmails[] = $email; else $mailEmails[] = $email;
    }
?>
    <tr class="clickable-row" onclick="openModal('modal_<?=$name?>')">
        <td><strong><?= htmlspecialchars($name) ?></strong></td>
        <td><?= htmlspecialchars($serverIp) ?></td>
        <td><?= htmlspecialchars($sType) ?></td>
        <td>
            <?php if(empty($activeBadges)): ?><span class="no-alert"><?= __('lbl_none', 'Aucune') ?></span><?php else: ?>
                <?php foreach($activeBadges as $b): ?><span class="badge-alert"><?= htmlspecialchars($b) ?></span><?php endforeach; ?>
            <?php endif; ?>
        </td>
        <td>
            <?php if (!empty($mailEmails)): ?><div><span class="badge-alert"><?= __('badge_mail_type', 'MAIL') ?></span> <span><?= htmlspecialchars(implode(", ", $mailEmails)) ?></span></div><?php endif; ?>
            <?php if (!empty($pushEmails)): ?><div class="notif-line"><span class="badge-alert"><?= __('badge_push_type', 'PUSH') ?></span> <span><?= htmlspecialchars(implode(", ", $pushEmails)) ?></span></div><?php endif; ?>
            <?php if (empty($mailEmails) && empty($pushEmails)): ?><span class="no-alert"><?= __('lbl_none', 'Aucune') ?></span><?php endif; ?>
        </td>
        <td><?= htmlspecialchars($c['time_start'] ?? '08:00') ?> - <?= htmlspecialchars($c['time_end'] ?? '20:00') ?></td>
        <td onclick="event.stopPropagation();" class="text-center">
            <label class="switch-container">
                <input type="checkbox" onchange="toggleGlobal('<?=$name?>', 'servers', this.checked)" <?= $isGlobalActive ? 'checked' : '' ?>>
                <span class="slider"><span class="icon-v">✓</span><span class="icon-x">✕</span></span>
            </label>
        </td>
    </tr>
    <div id="modal_<?=$name?>" class="modal-overlay">
        <div class="modal-container" onclick="event.stopPropagation();">
            <div class="modal-header">
                <h2><?= __('modal_config_title', 'Configuration :') ?> <?=htmlspecialchars($name)?> (<?=strtoupper($sType)?>)</h2>
                <button type="button" class="btn btn-gray" onclick="closeModal('modal_<?=$name?>')"><?= __('btn_close', 'Fermer') ?></button>
            </div>
            <div class="form-section section-top">
                <h3><?= __('section_notif_schedules', 'Notification & Horaires') ?></h3>
                <div><label class="label-bold"><?= __('lbl_emails_csv', 'Emails (séparés par virgule)') ?></label><input type="text" class="input-full" name="servers[<?=notif_h($name)?>][emails]" value="<?=htmlspecialchars(implode(", ", $c['emails'] ?? []))?>"></div>
                <div class="time-row">
                    <div class="time-field"><label><?= __('lbl_start_time', 'Heure de début') ?></label><input type="time" lang="<?= htmlspecialchars($currentLang) ?>" name="servers[<?=notif_h($name)?>][start]" value="<?=$c['time_start'] ?? '08:00'?>"></div>
                    <div class="time-field"><label><?= __('lbl_end_time', 'Heure de fin') ?></label><input type="time" lang="<?= htmlspecialchars($currentLang) ?>" name="servers[<?=notif_h($name)?>][end]" value="<?=$c['time_end'] ?? '20:00'?>"></div>
		    <div>
			<label class="label-bold"><?= __('lbl_reminder_interval', 'Intervalle de rappel (min)') ?></label>
		        <input type="number" min="0" class="input-full" name="servers[<?=notif_h($name)?>][interval]" value="<?=htmlspecialchars((string)($c['interval'] ?? ''))?>" placeholder="Défaut global : <?=(int)($config['reminder_interval'] ?? 0)?> min">
		</div>
                </div>
            </div>
            <div class="grid-3-cols">
                <div class="form-section">
                    <h3><?= __('section_avail_system', 'Disponibilité & Système') ?></h3>
                    <div class="form-group"><label><?= __('badge_offline', 'Offline') ?></label><input type="checkbox" name="servers[<?=notif_h($name)?>][offline]" <?=!empty($c['offline_enabled'])?'checked':''?>></div>
                    <div class="form-group"><label><?= __('badge_updates', 'Updates') ?></label><input type="checkbox" name="servers[<?=notif_h($name)?>][update]" <?=!empty($c['update_enabled'])?'checked':''?>></div>
                    <div class="form-group"><label><?= __('badge_reboot', 'Reboot') ?></label><input type="checkbox" name="servers[<?=notif_h($name)?>][reboot]" <?=!empty($c['reboot_enabled'])?'checked':''?>></div>
                </div>
                <div class="form-section">
                    <h3><?= __('section_thresholds', 'Seuils') ?></h3>
                    <?php if(!empty($detectedDisks)): ?>
                        <?php foreach($detectedDisks as $diskIndex => $diskEntry):
                            $diskName = is_array($diskEntry) && isset($diskEntry['name']) ? (string)$diskEntry['name'] : (string)$diskIndex;
                            $displayName = htmlspecialchars(getDiskDisplayName($diskName), ENT_QUOTES, 'UTF-8');
                            $diskConf = $c['disks'][$diskName] ?? [];
                            $defaultVal = intval($diskConf['threshold'] ?? 90);
                            $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $diskName);
                        ?>
                            <div class="form-group threshold-group">
                                <label><strong><?= __('badge_disk', 'Disque') ?> <?=$displayName?> (%)</strong></label>
                                <input type="hidden" name="servers[<?=notif_h($name)?>][disks][<?=$safeKey?>][key]" value="<?=notif_h($diskName)?>">
                                <div class="threshold-row">
                                    <div class="threshold-item">
					<span class="badge-warn"><?= __('lbl_warn_prefix', 'Warn:') ?></span>
                                        <input type="checkbox" name="servers[<?=notif_h($name)?>][disks][<?=$safeKey?>][warn_enabled]" <?=!empty($diskConf['warn_enabled']) ? 'checked' : ''?>>
                                        <input type="number" name="servers[<?=notif_h($name)?>][disks][<?=$safeKey?>][warn_threshold]" value="<?=intval($diskConf['warn_threshold'] ?? ($defaultVal - 10))?>" min="1" max="100" class="input-number-sm">%
                                    </div>
                                    <div class="threshold-item">
                                        <span class="badge-crit"><?= __('lbl_crit_prefix', 'Crit:') ?></span>
                                        <input type="checkbox" name="servers[<?=notif_h($name)?>][disks][<?=$safeKey?>][crit_enabled]" <?=!empty($diskConf['crit_enabled']) ? 'checked' : ''?>>
                                        <input type="number" name="servers[<?=notif_h($name)?>][disks][<?=$safeKey?>][crit_threshold]" value="<?=intval($diskConf['crit_threshold'] ?? $defaultVal)?>" min="1" max="100" class="input-number-sm">%
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <div class="form-group threshold-group">
                        <label><strong>RAM (%)</strong></label>
                        <div class="threshold-row">
                            <div class="threshold-item">
                                <span class="badge-warn"><?= __('lbl_warn_prefix', 'Warn:') ?></span>
                                <input type="checkbox" name="servers[<?=notif_h($name)?>][ram_warn_enabled]" <?=!empty($c['ram_warn_enabled']) ? 'checked' : ''?>>
                                <input type="number" name="servers[<?=notif_h($name)?>][ram_warn_threshold]" value="<?=intval($c['ram_warn_threshold'] ?? 80)?>" min="1" max="100" class="input-number-sm">%
                            </div>
                            <div class="threshold-item">
                                <span class="badge-crit"><?= __('lbl_crit_prefix', 'Crit:') ?></span>
                                <input type="checkbox" name="servers[<?=notif_h($name)?>][ram_crit_enabled]" <?=!empty($c['ram_crit_enabled']) || (!empty($c['ram_enabled']) && empty($c['ram_warn_enabled'])) ? 'checked' : ''?>>
                                <input type="number" name="servers[<?=notif_h($name)?>][ram_crit_threshold]" value="<?=intval($c['ram_crit_threshold'] ?? ($c['ram_threshold'] ?? 90))?>" min="1" max="100" class="input-number-sm">%
                            </div>
                        </div>
                    </div>
                    <div class="form-group threshold-group">
                        <label><strong><?= __('col_uptime', 'Uptime') ?> (<?= __('lbl_days_unit', 'Jours') ?>)</strong></label>
                        <div class="threshold-row">
                            <div class="threshold-item">
                                <span class="badge-warn"><?= __('lbl_warn_prefix', 'Warn:') ?></span>
                                <input type="checkbox" name="servers[<?=notif_h($name)?>][uptime_warn_enabled]" <?=!empty($c['uptime_warn_enabled']) ? 'checked' : ''?>>
                                <input type="number" name="servers[<?=notif_h($name)?>][uptime_warn_threshold]" value="<?=intval($c['uptime_warn_threshold'] ?? 15)?>" min="1" max="365" class="input-number-sm"><?= __('lbl_day_unit_short', 'j') ?>
                            </div>
                            <div class="threshold-item">
                                <span class="badge-crit"><?= __('lbl_crit_prefix', 'Crit:') ?></span>
                                <input type="checkbox" name="servers[<?=notif_h($name)?>][uptime_crit_enabled]" <?=!empty($c['uptime_crit_enabled']) || (!empty($c['uptime_enabled']) && empty($c['uptime_warn_enabled'])) ? 'checked' : ''?>>
                                <input type="number" name="servers[<?=notif_h($name)?>][uptime_crit_threshold]" value="<?=intval($c['uptime_crit_threshold'] ?? ($c['uptime_threshold'] ?? 30))?>" min="1" max="365" class="input-number-sm"><?= __('lbl_day_unit_short', 'j') ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="form-section">
                    <h3><?= __('section_services', 'Services') ?></h3>
                    <?php if ($sType === 'linux'): ?>
                        <div class="form-group"><label>Kea-DHCP</label><input type="checkbox" name="servers[<?=notif_h($name)?>][kea_dhcp]" <?=!empty($c['kea_dhcp_enabled'])?'checked':''?>></div>
                        <div class="form-group"><label>Squid</label><input type="checkbox" name="servers[<?=notif_h($name)?>][squid]" <?=!empty($c['squid_enabled'])?'checked':''?>></div>
                        <div class="form-group"><label>Webmin</label><input type="checkbox" name="servers[<?=notif_h($name)?>][webmin]" <?=!empty($c['webmin_enabled'])?'checked':''?>></div>
                    <?php endif; ?>
                    <?php if ($sType === 'windows'): ?>
                        <div class="form-group"><label>Power BI Gateway</label><input type="checkbox" name="servers[<?=notif_h($name)?>][powerbi]" <?=!empty($c['powerbi_enabled'])?'checked':''?>></div>
                        <div class="form-group"><label>Outlook</label><input type="checkbox" name="servers[<?=notif_h($name)?>][outlook]" <?=!empty($c['outlook_enabled'])?'checked':''?>></div>
                        <div class="form-group"><label>StageNow</label><input type="checkbox" name="servers[<?=notif_h($name)?>][stagenow]" <?=!empty($c['stagenow_enabled'])?'checked':''?>></div>
                        <div class="form-group"><label>WSUS Service & IIS</label><input type="checkbox" name="servers[<?=notif_h($name)?>][wsus]" <?=!empty($c['wsus_enabled'])?'checked':''?>></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-blue"><?= __('btn_save', 'Enregistrer') ?></button></div>
        </div>
    </div>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php foreach ($netCategories as $type => $tabId):
    $jsonFile = "/opt/supervision/data/network_{$type}.json";
    $rawNetData = file_exists($jsonFile) ? json_decode(file_get_contents($jsonFile), true) : [];
    $devicesList = [];
    if (isset($rawNetData['sites'])) {
        foreach ($rawNetData['sites'] as $siteName => $devs) {
            foreach ($devs as $d) { $d['site'] = $siteName; $devicesList[] = $d; }
        }
    } else { $devicesList = $rawNetData; }
?>
<div id="<?=$tabId?>" class="tab-content">
<table>
<thead>
<tr>
    <th><?= __('col_equipment', 'Équipement') ?></th><th><?= __('col_ip', 'IP') ?></th><th><?= __('col_site', 'Site') ?></th><th><?= __('col_tolerances', 'Tolérances') ?></th><th><?= __('col_notification_emails', 'Emails de Notification') ?></th><th><?= __('col_time_range', 'Plage Horaire') ?></th><th class="text-center"><?= __('col_notifications_toggle', 'Notifications') ?></th>
</tr>
</thead>
<tbody>
<?php foreach($devicesList as $dev):
    $devName = $dev['name'] ?? '';
    if (!$devName) continue;
    $ip = $dev['ip'] ?? '-';
    $site = $dev['site'] ?? '-';
    $isTemp = (($dev['type'] ?? '') === 'temperature');
    $isSnmpOther = ($type === 'others' && strtolower((string)($dev['type'] ?? '')) === 'snmp');
    $nc = $netConfig[$type][$devName] ?? [];
    $warningEnabled = !empty($nc['warning_enabled']);
    $warningValue = $nc['warning_value'] ?? 0;
    $criticalEnabled = !empty($nc['critical_enabled']);
    $criticalValue = $nc['critical_value'] ?? 0;
    $snmpUnit = $nc['snmp_unit'] ?? '';
    $isGlobalActive = $nc['enabled'] ?? true;
    $isPingActive = isset($nc['ping_enabled']) ? !empty($nc['ping_enabled']) : true;
    $maxLoss = $nc['max_loss'] ?? 0;
    $isWarnActive = isset($nc['temp_warn_enabled']) ? !empty($nc['temp_warn_enabled']) : ($nc['temp_enabled'] ?? true);
    $isCritActive = isset($nc['temp_crit_enabled']) ? !empty($nc['temp_crit_enabled']) : ($nc['temp_enabled'] ?? true);
    $tempWarn = $nc['temp_warn'] ?? 25;
    $tempCrit = $nc['temp_crit'] ?? 30;
    $deviceEmails = (array)($nc['emails'] ?? []);
    $mailEmails = [];
    $pushEmails = [];
    foreach ($deviceEmails as $email) {
        $email = trim((string)$email);
        if ($email === '') continue;
        if (($userChannels[$email]['channel'] ?? 'email') === 'push') $pushEmails[] = $email; else $mailEmails[] = $email;
    }
    $modalId = "modal_net_{$type}_" . md5($devName);
    $escapedDevName = htmlspecialchars($devName);
    $lossLabel = ($maxLoss > 1) ? __('lbl_packet_losses_plural', 'pertes') : __('lbl_packet_loss_single', 'perte');
?>
    <tr class="clickable-row" onclick="openModal('<?=$modalId?>')">
        <td><strong><?=$escapedDevName?></strong></td>
        <td><?=htmlspecialchars($ip)?></td>
        <td><?=htmlspecialchars($site)?></td>
	    <td>
            <?php if($isSnmpOther): ?>
                <?php if($isPingActive): ?><span class="badge-alert">Ping (<?=$maxLoss?> <?=$lossLabel?>)</span><?php endif; ?>
                <?php if($warningEnabled): ?><span class="badge-alert">Warning (<?=htmlspecialchars((string)$warningValue)?> <?=htmlspecialchars((string)$snmpUnit)?>)</span><?php endif; ?>
                <?php if($criticalEnabled): ?><span class="badge-alert">Critical (<?=htmlspecialchars((string)$criticalValue)?> <?=htmlspecialchars((string)$snmpUnit)?>)</span><?php endif; ?>
                <?php if(!$isPingActive && !$warningEnabled && !$criticalEnabled): ?><span class="no-alert"><?= __('lbl_none', 'Aucune') ?></span><?php endif; ?>
            <?php else: ?>
                <?php if(!$isPingActive && (!$isTemp || (!$isWarnActive && !$isCritActive))): ?>
                    <span class="no-alert"><?= __('lbl_none', 'Aucune') ?></span>
                <?php else: ?>
                    <?php if($isPingActive): ?><span class="badge-alert">Ping (<?=$maxLoss?> <?=$lossLabel?>)</span><?php endif; ?>
                    <?php if($isTemp && $isWarnActive): ?><span class="badge-alert">Temp Warn (&ge; <?=$tempWarn?>°C)</span><?php endif; ?>
                    <?php if($isTemp && $isCritActive): ?><span class="badge-alert">Temp Crit (&ge; <?=$tempCrit?>°C)</span><?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
            </td>
        <td>
            <?php if (!empty($mailEmails)): ?><div><span class="badge-alert"><?= __('badge_mail_type', 'MAIL') ?></span> <span><?= htmlspecialchars(implode(", ", $mailEmails)) ?></span></div><?php endif; ?>
            <?php if (!empty($pushEmails)): ?><div class="notif-line"><span class="badge-alert"><?= __('badge_push_type', 'PUSH') ?></span> <span><?= htmlspecialchars(implode(", ", $pushEmails)) ?></span></div><?php endif; ?>
            <?php if (empty($mailEmails) && empty($pushEmails)): ?><span class="no-alert"><?= __('lbl_none', 'Aucune') ?></span><?php endif; ?>
        </td>
        <td><?= htmlspecialchars($nc['time_start'] ?? '08:00') ?> - <?= htmlspecialchars($nc['time_end'] ?? '20:00') ?></td>
        <td onclick="event.stopPropagation();" class="text-center">
            <label class="switch-container">
                <input type="checkbox" onchange="toggleGlobal('<?=$escapedDevName?>', '<?=$type?>', this.checked)" <?= $isGlobalActive ? 'checked' : '' ?>>
                <span class="slider"><span class="icon-v">✓</span><span class="icon-x">✕</span></span>
            </label>
        </td>
    </tr>
    <div id="<?=$modalId?>" class="modal-overlay">
        <div class="modal-container" onclick="event.stopPropagation();">
            <div class="modal-header">
                <h2><?= __('modal_config_title', 'Configuration :') ?> <?=$escapedDevName?> (<?=htmlspecialchars($site)?>)</h2>
                <button type="button" class="btn btn-gray" onclick="closeModal('<?=$modalId?>')"><?= __('btn_close', 'Fermer') ?></button>
            </div>
            <div class="form-section section-top">
                <h3><?= __('section_notif_schedules', 'Notification & Horaires') ?></h3>
                <div><label class="label-bold"><?= __('lbl_emails_csv', 'Emails (séparés par virgule)') ?></label><input type="text" class="input-full" name="net[<?=$type?>][<?=$escapedDevName?>][emails]" value="<?=htmlspecialchars(implode(", ", $deviceEmails))?>"></div>
                <div class="time-row">
                    <div class="time-field"><label><?= __('lbl_start_time', 'Heure de début') ?></label><input type="time" lang="<?= htmlspecialchars($currentLang) ?>" name="net[<?=$type?>][<?=$escapedDevName?>][start]" value="<?=$nc['time_start'] ?? '08:00'?>"></div>
                    <div class="time-field"><label><?= __('lbl_end_time', 'Heure de fin') ?></label><input type="time" lang="<?= htmlspecialchars($currentLang) ?>" name="net[<?=$type?>][<?=$escapedDevName?>][end]" value="<?=$nc['time_end'] ?? '20:00'?>"></div>
                </div>
            </div>
            <div class="form-section">
                <h3><?= __('col_tolerances', 'Tolérances') ?></h3>
                <div class="form-group"><label><?= __('lbl_ping_enable', 'Ping (Activer)') ?></label><input type="checkbox" name="net[<?=$type?>][<?=$escapedDevName?>][ping]" <?=$isPingActive?'checked':''?>></div>
                <div class="form-group"><label><?= __('lbl_max_packet_loss', 'Perte max (paquets)') ?></label><input type="number" name="net[<?=$type?>][<?=$escapedDevName?>][max_loss]" value="<?=$maxLoss?>" min="0" max="100" class="input-number-sm"></div>
		<?php if($isSnmpOther): ?>
		    <div class="form-group threshold-group">
		        <label><strong>SNMP (<?=htmlspecialchars((string)($dev['snmp'] ?? ''))?>)</strong></label>
		        <div class="threshold-row">
		            <div class="threshold-item">
		                <span class="badge-warn">Warning:</span>
		                <input type="checkbox" name="net[<?=$type?>][<?=$escapedDevName?>][warning_enabled]" <?=$warningEnabled?'checked':''?>>
		                <input type="number" step="any" name="net[<?=$type?>][<?=$escapedDevName?>][warning_value]" value="<?=htmlspecialchars((string)$warningValue)?>" class="input-number-sm">
		            </div>
		            <div class="threshold-item">
		                <span class="badge-crit">Critical:</span>
		                <input type="checkbox" name="net[<?=$type?>][<?=$escapedDevName?>][critical_enabled]" <?=$criticalEnabled?'checked':''?>>
		                <input type="number" step="any" name="net[<?=$type?>][<?=$escapedDevName?>][critical_value]" value="<?=htmlspecialchars((string)$criticalValue)?>" class="input-number-sm">
		            </div>
		            <div class="threshold-item">
		                <label>Unité</label>
		                <input type="text" name="net[<?=$type?>][<?=$escapedDevName?>][snmp_unit]" value="<?=htmlspecialchars($snmpUnit)?>" maxlength="20" class="input-number-sm">
		            </div>
		        </div>
		    </div>
		<?php endif; ?>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-blue"><?= __('btn_save', 'Enregistrer') ?></button></div>
        </div>
    </div>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endforeach; ?>
<div id="tab-as400" class="tab-content">
<div class="form-section">
<h3>Alertes Syslog AS400</h3>
<p>Les niveaux sont cumulatifs : activer un niveau active aussi les niveaux plus graves.</p>
<?php $levelNames = ['Emergency', 'Alert', 'Critical', 'Error', 'Warning', 'Notice', 'Informational', 'Debug']; foreach (range(0, 7) as $level): $levelConfig = $as400Config[(string)$level] ?? []; ?>
<div class="as400-level-row">
<label><input type="checkbox" name="as400[<?=$level?>][enabled]" value="1" <?=!empty($levelConfig['enabled'])?'checked':''?>> Niveau <?=$level?> - <?=notif_h($levelNames[$level])?></label>
<input type="text" class="input-full" name="as400[<?=$level?>][emails]" value="<?=notif_h(implode(', ', (array)($levelConfig['emails'] ?? [])))?>" placeholder="Emails ou noms, séparés par des virgules">
</div>
<?php endforeach; ?>
<button type="submit" class="btn btn-blue">Enregistrer</button>
</div>
</div>
</form>
<div id="modal_channels" class="modal-overlay">
    <div class="modal-container modal-channels-container" onclick="event.stopPropagation();">
        <form method="POST" action="mailing.php">
            <input type="hidden" name="csrf_token" value="<?=notif_h($csrfToken ?? '')?>">
            <input type="hidden" name="action" value="save_channels">
            <div class="modal-header">
                <h2><?= __('modal_channels_title', 'Gestion des Canaux de Notification') ?></h2>
                <button type="button" class="btn btn-gray" onclick="closeModal('modal_channels')"><?= __('btn_close', 'Fermer') ?></button>
            </div>
            <div class="modal-channels-body">
                <?php if (empty($allEmailsFound)): ?>
		    <p><?= __('msg_no_emails_configured', 'Aucune adresse e-mail configurée dans les équipements.') ?></p>
		                <?php else: ?>
                    <?php foreach ($allEmailsFound as $idx => $email):
                        $currentType    = $userChannels[$email]['channel'] ?? 'email';
                        $currentKey     = $userChannels[$email]['push_key'] ?? '';
                        $currentDevice  = $userChannels[$email]['push_device'] ?? '';
                        $currentLangRcpt = $userChannels[$email]['lang'] ?? ($currentLang ?? 'fr');
                        $isPush         = ($currentType === 'push');
                    ?>
                        <div class="modal-dest-row">
                            <div class="dest-info-col">
                                <strong><?=notif_h($email)?></strong>
                                <div class="dest-lang-row">
                                    <small class="dest-help-text"><?= __('lbl_recipient_lang', 'Langue :') ?></small>
                                    <select name="channels[<?=notif_h($email)?>][lang]">
                                        <?php foreach (['fr' => 'Français 🇫🇷', 'en' => 'English 🇬🇧', 'de' => 'Deutsch 🇩🇪', 'es' => 'Español 🇪🇸', 'it' => 'Italiano 🇮🇹', 'nl' => 'Nederlands 🇳🇱', 'pt' => 'Português 🇵🇹'] as $code => $label): ?>
                                            <option value="<?=notif_h($code)?>" <?=($currentLangRcpt === $code ? 'selected' : '')?>><?=notif_h($label)?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div id="key_box_<?=$idx?>" class="key-input-container<?=$isPush ? ' visible' : ''?>">
                                    <div>
                                        <small class="dest-help-text"><?= __('lbl_pushsafer_key', 'Clé privée Pushsafer :') ?></small>
                                        <input type="text" name="channels[<?=notif_h($email)?>][push_key]" value="<?=notif_h($currentKey)?>" placeholder="Ex: a1b2c3d4e5f6..." class="input-full-width">
                                    </div>
                                    <div>
                                        <small class="dest-help-text"><?= __('lbl_push_device_optional', 'Device / Group ID (Optionnel - laisser vide si tous) :') ?></small>
                                        <input type="text" name="channels[<?=notif_h($email)?>][push_device]" value="<?=notif_h($currentDevice)?>" placeholder="Ex: 12345 ou gs100" class="input-full-width">
                                    </div>
                                </div>
                            </div>
                            <div>
                                <input type="hidden" id="channel_type_<?=$idx?>" name="channels[<?=notif_h($email)?>][type]" value="<?=notif_h($currentType)?>">
                                <div class="toggle-switch-channel" onclick="toggleUserChannel('<?=$idx?>')">
                                    <span id="lbl_mail_<?=$idx?>" class="<?=$isPush ? '' : 'active-mail'?>"><?= __('badge_mail_type', 'MAIL') ?></span>
                                    <span id="lbl_push_<?=$idx?>" class="<?=$isPush ? 'active-push' : ''?>"><?= __('badge_push_type', 'PUSH') ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-blue"><?= __('btn_save_channels', 'Enregistrer les canaux') ?></button></div>
        </form>
    </div>
</div>
<div id="modal_server_settings" class="modal-overlay">
    <div class="modal-container" onclick="event.stopPropagation();">
        <form method="POST" action="mailing.php">
            <input type="hidden" name="global_settings" value="1">
            <div class="modal-header">
                <h2><?= __('modal_server_settings_title', 'Paramètres des rappels d\'alertes') ?></h2>
                <button type="button" class="btn btn-gray" onclick="closeModal('modal_server_settings')"><?= __('btn_close', 'Fermer') ?></button>
            </div>
            <div class="reminder-settings-list">
                <div class="reminder-row">
                    <label>🖥️ <?= __('tab_servers', 'Serveurs') ?> :</label>
		    <div class="reminder-input-group">
			<input type="number" min="0" name="reminder_interval" value="<?= (int)($config['reminder_interval'] ?? 0) ?>"><span>min</span>
                </div></div>
                <div class="reminder-row">
                    <label>🔀 <?= __('tab_switches', 'Switchs') ?> :</label>
                    <div class="reminder-input-group"> 
                        <input type="number" min="0" name="reminder_interval" value="<?= (int)($config['reminder_interval'] ?? 0) ?>"><span>min</span>
                </div></div>
                <div class="reminder-row">
                    <label>📡 <?= __('tab_aps', 'Bornes Wi-Fi') ?> :</label>
                    <div class="reminder-input-group"> 
                        <input type="number" min="0" name="reminder_interval" value="<?= (int)($config['reminder_interval'] ?? 0) ?>"><span>min</span>
                </div></div>
                <div class="reminder-row">
                    <label>⚙️ <?= __('tab_others', 'Autres') ?> :</label>
                    <div class="reminder-input-group"> 
                        <input type="number" min="0" name="reminder_interval" value="<?= (int)($config['reminder_interval'] ?? 0) ?>"><span>min</span>
                </div></div>
                <div class="reminder-row">
                    <label>📋 AS400 (Syslog) :</label>
                    <div class="reminder-input-group"> 
                        <input type="number" min="0" name="reminder_interval" value="<?= (int)($config['reminder_interval'] ?? 0) ?>"><span>min</span>
                </div></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-blue"><?= __('btn_save', 'Enregistrer') ?></button></div>
        </form>
    </div>
</div>
<script src="/assets/darkmode.js"></script>
<script>const MAILING_I18N={resultsSuffix:<?=json_encode(__('lbl_results_suffix','résultat(s)'))?>,errorToggleGlobal:<?=json_encode(__('err_toggle_global_failed','Erreur lors de la mise à jour du statut global.'))?>};</script>
<script src="/assets/js/mailing.js"></script>
</body>
</html>
