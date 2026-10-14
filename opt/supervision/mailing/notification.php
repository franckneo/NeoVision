<?php
date_default_timezone_set('Europe/Brussels');
$configFile = '/opt/supervision/data/alert_config.json';
$netConfigFile = '/opt/supervision/data/network_alert_config.json';
$serversFile = '/opt/supervision/data/servers.json';
$stateFile = '/opt/supervision/data/last_state.json';
$channelsFile = '/opt/supervision/data/notification_channels.json';
$i18nFile = __DIR__ . '/i18n.php';
$networkFiles = ['switchs' => '/opt/supervision/data/network_switchs.json', 'aps' => '/opt/supervision/data/network_aps.json', 'others' => '/opt/supervision/data/network_others.json'];
$i18n = file_exists($i18nFile) ? require $i18nFile : ['titles' => [], 'subjects' => [], 'messages' => []];
$config = json_decode(@file_get_contents($configFile), true) ?: [];
$netConfig = json_decode(@file_get_contents($netConfigFile), true) ?: [];
$rawServers = json_decode(@file_get_contents($serversFile), true) ?: [];
$servers = isset($rawServers['servers']) && is_array($rawServers['servers']) ? $rawServers['servers'] : (is_array($rawServers) ? $rawServers : []);
$lastState = json_decode(@file_get_contents($stateFile), true) ?: [];
$userChannels = json_decode(@file_get_contents($channelsFile), true) ?: [];
$newState = ['ping' => [], 'obsolete' => [], 'disks' => [], 'uptime' => [], 'reboot' => [], 'services' => [], 'network' => [], 'pending' => $lastState['pending'] ?? [], 'last_sent' => $lastState['last_sent'] ?? []];
$individualQueues = [];
$logFile = '/var/log/supervision/mailing.log';
function writeLog($message) { global $logFile; file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND); }
function isWithinTimeRange($start, $end) {
    if (empty($start) || empty($end)) return true;
    $now = date('H:i');
    return ($start <= $end) ? ($now >= $start && $now <= $end) : ($now >= $start || $now <= $end);
}
function shouldSendServerAlert($alertKey, $cfgInterval, &$newState) {
    $interval = (int)$cfgInterval;
    $now = time();
    $last = $newState['last_sent'][$alertKey] ?? 0;
    if ($last === 0) { $newState['last_sent'][$alertKey] = $now; return true; }
    if ($interval > 0 && ($now - $last) >= ($interval * 60)) { $newState['last_sent'][$alertKey] = $now; return true; }
    return false;
}
function clearServerAlertKey($alertKey, &$newState) { unset($newState['last_sent'][$alertKey]); }
function getRecipientLang($recipient, $channels) {
    $r = trim(strtolower((string)$recipient));
    if (isset($channels[$r]['lang']) && !empty($channels[$r]['lang'])) return (string)$channels[$r]['lang'];
    foreach ($channels as $key => $data) { if (trim(strtolower((string)$key)) === $r) return (string)($data['lang'] ?? 'fr'); }
    return 'fr';
}
function getNotificationChannel($recipient, $channels) {
    $r = trim(strtolower((string)$recipient));
    $entry = $channels[$r] ?? null;
    if (!$entry) { foreach ($channels as $key => $data) { if (trim(strtolower((string)$key)) === $r) { $entry = $data; break; } } }
    $t = $entry['channel'] ?? $entry['type'] ?? 'mail';
    return ($t === 'email') ? 'mail' : (string)$t;
}
function getPushKey($recipient, $channels) {
    $r = trim(strtolower((string)$recipient));
    if (isset($channels[$r]['push_key'])) return (string)$channels[$r]['push_key'];
    foreach ($channels as $key => $data) { if (trim(strtolower((string)$key)) === $r) return (string)($data['push_key'] ?? ''); }
    return '';
}
function getPushDevice($recipient, $channels) {
    $r = trim(strtolower((string)$recipient));
    if (isset($channels[$r]['push_device'])) return (string)$channels[$r]['push_device'];
    foreach ($channels as $key => $data) { if (trim(strtolower((string)$key)) === $r) return (string)($data['push_device'] ?? ''); }
    return '';
}
function getAlertMessage($type, $params, $lang = 'fr') {
    global $i18n;
    $ts = date('Y-m-d H:i:s');
    $d = $i18n['messages'][$lang] ?? $i18n['messages']['fr'];
    switch ($type) {
        case 'ping_down': return "{$d['ping_down_title']}\n{$d['equip']} : {$params['name']}\n{$d['state']} : {$d['unreachable']}\n{$d['losses']} : {$params['losses']}\n{$d['threshold']} : {$params['maxLoss']}\n{$d['ts']} : {$ts}";
        case 'ping_up': return "{$d['ping_up_title']}\n{$d['equip']} : {$params['name']}\n{$d['state']} : {$d['ping_up_state']}\n{$d['ts']} : {$ts}";
        case 'obs_down': return "{$d['obs_down_title']}\n{$d['equip']} : {$params['name']}\n{$d['state']} : {$d['unreachable']}\n{$d['obs_down_detail']}\n{$d['ts']} : {$ts}";
        case 'obs_up': return "{$d['obs_up_title']}\n{$d['equip']} : {$params['name']}\n{$d['ts']} : {$ts}";
        case 'disk_down': return "{$d['disk_down_title']}\n{$d['equip']} : {$params['name']}\n{$d['disk_lbl']} : {$params['disk']}\n{$d['disk_use']} : {$params['pct']}%\n{$d['threshold']} : {$params['crit']}%\n{$d['ts']} : {$ts}";
        case 'disk_up': return "{$d['disk_up_title']}\n{$d['equip']} : {$params['name']}\n{$d['disk_lbl']} : {$params['disk']}\n{$d['disk_use']} : {$params['pct']}%\n{$d['threshold']} : {$params['crit']}%\n{$d['ts']} : {$ts}";
        case 'upt_down': return "{$d['upt_down_title']}\n{$d['equip']} : {$params['name']}\nUptime : {$params['uptime']} {$d['upt_days']}\n{$d['threshold']} : {$params['crit']} {$d['upt_days']}\n{$d['ts']} : {$ts}";
        case 'upt_up': return "{$d['upt_up_title']}\n{$d['equip']} : {$params['name']}\nUptime : {$params['uptime']} {$d['upt_days']}\n{$d['ts']} : {$ts}";
        case 'srv_down': return sprintf($d['srv_down_title'], $params['srv'], $params['name']) . "\n{$d['state']} : {$d['unreachable']}\n{$d['ts']} : {$ts}";
        case 'srv_up': return sprintf($d['srv_up_title'], $params['srv'], $params['name']) . "\n{$d['state']} : {$d['ping_up_state']}\n{$d['ts']} : {$ts}";
        case 'reb_down': return "{$d['reb_down_title']}\n{$d['equip']} : {$params['name']}\n{$d['reb_reason']}\n{$d['ts']} : {$ts}";
        case 'reb_up': return "{$d['reb_up_title']}\n{$d['equip']} : {$params['name']}\n{$d['ts']} : {$ts}";
        case 'net_down': return "{$d['net_down_title']}\n{$d['equip']} : {$params['name']}\n{$d['category']} : {$params['cat']}\n{$d['ip']} : {$params['ip']}\n{$d['state']} : {$d['unreachable']}\n{$d['ts']} : {$ts}";
        case 'net_up': return "{$d['net_up_title']}\n{$d['equip']} : {$params['name']}\n{$d['category']} : {$params['cat']}\n{$d['ip']} : {$params['ip']}\n{$d['state']} : {$d['ping_up_state']}\n{$d['ts']} : {$ts}";
        default: return "";
    }
}
function queueAlert($recipients, $type, $params, $userChannels) {
    global $individualQueues;
    if (empty($recipients)) return;
    foreach ($recipients as $recipient) {
        $recipient = trim((string)$recipient);
        if ($recipient === '') continue;
        $lang = getRecipientLang($recipient, $userChannels);
        $msg = getAlertMessage($type, $params, $lang);
        if (!empty($msg)) {
            if (!isset($individualQueues[$recipient])) $individualQueues[$recipient] = "";
            $individualQueues[$recipient] .= $msg . "\n\n" . str_repeat("-", 40) . "\n\n";
        }
    }
}
function sendPushsaferNotification($recipient, $message, $userChannels) {
    global $i18n;
    $pushKey = getPushKey($recipient, $userChannels);
    $pushDevice = getPushDevice($recipient, $userChannels);
    if ($pushKey === '') { writeLog("[ERROR] Clé Pushsafer absente pour : {$recipient}"); return false; }
    $lang = getRecipientLang($recipient, $userChannels);
    $titles = $i18n['titles'] ?? [];
    $title = $titles[$lang] ?? "NEO Supervision : Notification / Alerte d'état";
    $postData = ['k' => $pushKey, 't' => $title, 'm' => trim($message), 's' => '11', 'v' => '3', 'i' => '1'];
    if (!empty($pushDevice)) $postData['d'] = $pushDevice;
    $ch = curl_init('https://www.pushsafer.com/api');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($postData), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($curlErr) { writeLog("[ERROR] Pushsafer curl error: {$curlErr} pour {$recipient}"); return false; }
    if ($httpCode !== 200) { writeLog("[ERROR] Pushsafer HTTP {$httpCode}: {$response} pour {$recipient}"); return false; }
    $res = json_decode($response, true);
    if (isset($res['status']) && $res['status'] == 1) { writeLog("[SUCCESS] Push envoyé à : {$recipient}"); return true; }
    writeLog("[ERROR] Pushsafer réponse négative : {$response} pour {$recipient}");
    return false;
}
foreach ($servers as $s) {
    if (!isset($s['name'])) continue;
    $name = $s['name'];
    $pj = json_decode(@file_get_contents("/opt/supervision/data/{$name}_ping.json"), true) ?: [];
    $stj = json_decode(@file_get_contents("/opt/supervision/data/{$name}_status.json"), true) ?: [];
    $svj = json_decode(@file_get_contents("/opt/supervision/data/{$name}_services.json"), true) ?: [];
    $s['ping_loss'] = $pj['consecutive_losses'] ?? (($pj['status'] ?? '') === 'offline' ? 100 : 0);
    $s['services'] = $svj['services'] ?? [];
    $s['reboot_required'] = $stj['status']['reboot_required'] ?? false;
    $sCfg = $config['servers'][$name] ?? $config[$name] ?? [];
    $isGloballyEnabled = !empty($sCfg['enabled']) || !empty($sCfg['offline_enabled']);
    if (!$isGloballyEnabled) continue;
    if (!isWithinTimeRange($sCfg['time_start'] ?? '', $sCfg['time_end'] ?? '')) continue;
    $emails = $sCfg['emails'] ?? [];
    $remindInterval = isset($sCfg['interval']) && $sCfg['interval'] !== '' 
        ? (int)$sCfg['interval'] 
        : (int)($config['reminder_interval'] ?? 0);
    if (!empty($sCfg['offline_enabled'])) {
        $loss = $s['ping_loss'] ?? 0;
        $critLoss = $sCfg['ping']['crit'] ?? $sCfg['max_loss'] ?? 100;
        if ($loss >= $critLoss) {
            $newState['ping'][$name] = true;
            if (shouldSendServerAlert("ping:{$name}", $remindInterval, $newState)) {
                queueAlert($emails, 'ping_down', ['name' => $name, 'losses' => $loss, 'maxLoss' => $critLoss], $userChannels);
            }
        } else {
            if (!empty($lastState['ping'][$name])) {
                clearServerAlertKey("ping:{$name}", $newState);
                queueAlert($emails, 'ping_up', ['name' => $name], $userChannels);
            }
        }
    }
    $lastUpdate = $s['last_update'] ?? 0;
    $isObs = ($lastUpdate > 0 && (time() - $lastUpdate) > 600);
    if (!empty($sCfg['obsolete']['enabled']) && $isObs) {
        $newState['obsolete'][$name] = true;
        if (shouldSendServerAlert("obs:{$name}", $remindInterval, $newState)) queueAlert($emails, 'obs_down', ['name' => $name], $userChannels);
    } else {
        if (!empty($lastState['obsolete'][$name])) {
            clearServerAlertKey("obs:{$name}", $newState);
            queueAlert($emails, 'obs_up', ['name' => $name], $userChannels);
        }
    }
    $disks = $s['disks'] ?? [];
    foreach ($disks as $d) {
        $dName = $d['name'] ?? $d['mount'] ?? '';
        if ($dName === '') continue;
        $dCfg = $sCfg['disks'][$dName] ?? [];
        if (empty($dCfg['enabled'])) continue;
        $dCrit = $dCfg['crit'] ?? 90;
        $dPct = $d['use_pct'] ?? $d['used_percent'] ?? 0;
        $alertKey = "disk:{$name}:{$dName}";
        if ($dPct >= $dCrit) {
            $newState['disks'][$name][$dName] = true;
            if (shouldSendServerAlert($alertKey, $remindInterval, $newState)) queueAlert($emails, 'disk_down', ['name' => $name, 'disk' => $dName, 'pct' => $dPct, 'crit' => $dCrit], $userChannels);
        } else {
            if (!empty($lastState['disks'][$name][$dName])) {
                clearServerAlertKey($alertKey, $newState);
                queueAlert($emails, 'disk_up', ['name' => $name, 'disk' => $dName, 'pct' => $dPct, 'crit' => $dCrit], $userChannels);
            }
        }
    }
    $uptimeDays = (int)(($s['uptime'] ?? 0) / 86400);
    $uptCfg = $sCfg['uptime'] ?? [];
    if (!empty($uptCfg['enabled'])) {
        $uCrit = $uptCfg['crit'] ?? 60;
        $alertKey = "upt:{$name}";
        if ($uptimeDays >= $uCrit) {
            $newState['uptime'][$name] = true;
            if (shouldSendServerAlert($alertKey, $remindInterval, $newState)) queueAlert($emails, 'upt_down', ['name' => $name, 'uptime' => $uptimeDays, 'crit' => $uCrit], $userChannels);
        } else {
            if (!empty($lastState['uptime'][$name])) {
                clearServerAlertKey($alertKey, $newState);
                queueAlert($emails, 'upt_up', ['name' => $name, 'uptime' => $uptimeDays], $userChannels);
            }
        }
    }
    $services = $s['services'] ?? [];
    foreach ($services as $srvName => $srvState) {
        $srvCfg = $sCfg['services'][$srvName] ?? [];
        $isSrvEnabled = !empty($srvCfg['enabled']) || (!empty($sCfg['wsus_enabled']) && strpos($srvName, 'wsus') === 0);
        if (!$isSrvEnabled) continue;
        $isDown = ($srvState !== 0 && $srvState !== 'running' && $srvState !== 'active');
        $alertKey = "srv:{$name}:{$srvName}";
        if ($isDown) {
            $newState['services'][$name][$srvName] = true;
            if (shouldSendServerAlert($alertKey, $remindInterval, $newState)) queueAlert($emails, 'srv_down', ['name' => $name, 'srv' => $srvName], $userChannels);
        } else {
            if (!empty($lastState['services'][$name][$srvName])) {
                clearServerAlertKey($alertKey, $newState);
                queueAlert($emails, 'srv_up', ['name' => $name, 'srv' => $srvName], $userChannels);
            }
        }
    }
    if (!empty($sCfg['reboot_enabled']) || !empty($sCfg['reboot']['enabled'])) {
        $needsReboot = !empty($s['reboot_required']);
        $alertKey = "reb:{$name}";
        if ($needsReboot) {
            $newState['reboot'][$name] = true;
            if (shouldSendServerAlert($alertKey, $remindInterval, $newState)) queueAlert($emails, 'reb_down', ['name' => $name], $userChannels);
        } else {
            if (!empty($lastState['reboot'][$name])) {
                clearServerAlertKey($alertKey, $newState);
                queueAlert($emails, 'reb_up', ['name' => $name], $userChannels);
            }
        }
    }
}
foreach ($networkFiles as $netType => $nFile) {
    $rawNet = json_decode(@file_get_contents($nFile), true) ?: [];
    $netItems = [];
    if (isset($rawNet['sites']) && is_array($rawNet['sites'])) {
        foreach ($rawNet['sites'] as $siteItems) {
            if (is_array($siteItems)) foreach ($siteItems as $it) $netItems[] = $it;
        }
    } elseif (is_array($rawNet)) {
        $netItems = $rawNet;
    }
    $remindInterval = (int)($netConfig['reminder_intervals'][$netType] ?? 0);
    foreach ($netItems as $item) {
        if (!is_array($item)) continue;
        $dName = $item['name'] ?? '';
        if ($dName === '') continue;
        $dCfg = $netConfig[$netType][$dName] ?? [];
        $isSnmp = strtolower((string)($item['type'] ?? '')) === 'snmp';
        $isConfigured = !empty($dCfg['enabled']) || !empty($dCfg['ping_enabled']) || !empty($dCfg['warning_enabled']) || !empty($dCfg['critical_enabled']);
        if (!$isConfigured || !isWithinTimeRange($dCfg['time_start'] ?? '', $dCfg['time_end'] ?? '')) continue;
        $emails = $dCfg['emails'] ?? [];
        $status = strtolower((string)($item['status'] ?? 'down'));
        $isDown = ($status === 'down' || $status === 'offline' || $status === 'critical');
        $ip = $item['ip'] ?? '';
        $alertKey = "net:{$netType}:{$dName}";
        if ($isDown) {
            $newState['network'][$netType][$dName] = true;
            if (shouldSendServerAlert($alertKey, $remindInterval, $newState)) queueAlert($emails, 'net_down', ['name' => $dName, 'cat' => ucfirst($netType), 'ip' => $ip], $userChannels);
        } elseif (!empty($lastState['network'][$netType][$dName])) {
            clearServerAlertKey($alertKey, $newState);
            queueAlert($emails, 'net_up', ['name' => $dName, 'cat' => ucfirst($netType), 'ip' => $ip], $userChannels);
        }
        if ($isSnmp && !empty($dCfg['ping_enabled'])) {
            $pingKey = "net:{$netType}:{$dName}:ping";
            if ($isDown) {
                $newState['network'][$netType][$dName . ':ping'] = true;
                if (shouldSendServerAlert($pingKey, $remindInterval, $newState)) queueAlert($emails, 'net_down', ['name' => $dName, 'cat' => ucfirst($netType) . ' Ping', 'ip' => $ip], $userChannels);
            } elseif (!empty($lastState['network'][$netType][$dName . ':ping'])) {
                clearServerAlertKey($pingKey, $newState);
                queueAlert($emails, 'net_up', ['name' => $dName, 'cat' => ucfirst($netType) . ' Ping', 'ip' => $ip], $userChannels);
            }
        }
        if (!$isSnmp || !is_numeric($item['snmp'] ?? null)) continue;
        $value = (float)$item['snmp'];
        $unit = (string)($dCfg['snmp_unit'] ?? '');
        foreach (['warning' => 'warning_value', 'critical' => 'critical_value'] as $level => $valueKey) {
            $enabled = !empty($dCfg[$level . '_enabled']);
            $threshold = (float)($dCfg[$valueKey] ?? 0);
            $snmpKey = "net:{$netType}:{$dName}:snmp:{$level}";
            $isAlert = $enabled && $value >= $threshold;
            if ($isAlert) {
                $newState['network'][$netType][$dName . ':snmp:' . $level] = true;
                if (shouldSendServerAlert($snmpKey, $remindInterval, $newState)) {
                    $message = "[SNMP {$level}] {$dName}\nCatégorie : " . ucfirst($netType) . "\nIP : {$ip}\nValeur : {$value} {$unit}\nSeuil : {$threshold} {$unit}\nDate : " . date('Y-m-d H:i:s');
                    foreach ((array)$emails as $recipient) {
                        $recipient = trim((string)$recipient);
                        if ($recipient !== '') {
                            if (!isset($individualQueues[$recipient])) $individualQueues[$recipient] = '';
                            $individualQueues[$recipient] .= $message . "\n\n" . str_repeat("-", 40) . "\n\n";
                        }
                    }
                }
            } elseif (!empty($lastState['network'][$netType][$dName . ':snmp:' . $level])) {
                unset($newState['network'][$netType][$dName . ':snmp:' . $level]);
                clearServerAlertKey($snmpKey, $newState);
                $message = "[SNMP {$level} OK] {$dName}\nCatégorie : " . ucfirst($netType) . "\nIP : {$ip}\nValeur : {$value} {$unit}\nSeuil : {$threshold} {$unit}\nDate : " . date('Y-m-d H:i:s');
                foreach ((array)$emails as $recipient) {
                    $recipient = trim((string)$recipient);
                    if ($recipient !== '') {
                        if (!isset($individualQueues[$recipient])) $individualQueues[$recipient] = '';
                        $individualQueues[$recipient] .= $message . "\n\n" . str_repeat("-", 40) . "\n\n";
                    }
                }
            }
        }
    }
}
$as400StateFile = '/opt/supervision/data/as400_last_state.json';
$as400ConfigFile = '/opt/supervision/data/as400_alert_config.json';
$as400DbConfigFile = '/var/www/common/as400_db.php';
$as400AlertState = json_decode(@file_get_contents($as400StateFile), true) ?: [];
$as400Config = json_decode(@file_get_contents($as400ConfigFile), true) ?: [];

if (is_array($as400Config) && is_file($as400DbConfigFile)) {
    try {
        $as400Db = require $as400DbConfigFile;
        if (isset($as400Db['host'], $as400Db['user'], $as400Db['pass'], $as400Db['name'])) {
            $pdo = new PDO("mysql:host={$as400Db['host']};dbname={$as400Db['name']};charset=utf8mb4", $as400Db['user'], $as400Db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $levelsMap = ['critical' => 2, 'warning' => 3, 'notice' => 5];
            $highestLevel = null;
            foreach (['notice', 'warning', 'critical'] as $lvl) { if (!empty($as400Config[$lvl]['enabled'])) $highestLevel = $lvl; }
            if ($highestLevel !== null) {
                $maxPri = $levelsMap[$highestLevel] ?? 2;
                $lastId = (int)($as400AlertState['last_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT id, receivedat, priority, message, fromhost FROM systemevents WHERE id > ? AND priority <= ? ORDER BY id ASC LIMIT 50");
                $stmt->execute([$lastId, $maxPri]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $as400Interval = (int)($as400Config['reminder_interval'] ?? 0);
                foreach ($rows as $r) {
                    $lvlName = ($r['priority'] <= 2) ? 'critical' : (($r['priority'] <= 3) ? 'warning' : 'notice');
                    $asRecipients = $as400Config[$lvlName]['emails'] ?? [];
                    $alertKey = "as400:{$lvlName}:" . ($r['fromhost'] ?? 'host');
                    $shouldSend = ($as400Interval === 0) || shouldSendServerAlert($alertKey, $as400Interval, $newState);
                    if (!empty($asRecipients) && $shouldSend) {
                        foreach ($asRecipients as $rcpt) {
                            $rcpt = trim((string)$rcpt);
                            if ($rcpt === '') continue;
                            $m = "[AS400] Alerte Syslog ({$lvlName})\nDate : {$r['receivedat']}\nHost : {$r['fromhost']}\nMessage : {$r['message']}";
                            if (!isset($individualQueues[$rcpt])) $individualQueues[$rcpt] = "";
                            $individualQueues[$rcpt] .= $m . "\n\n" . str_repeat("-", 40) . "\n\n";
                        }
                    }
                    if ((int)$r['id'] > $lastId) $lastId = (int)$r['id'];
                }
                $as400AlertState['last_id'] = $lastId;
                file_put_contents($as400StateFile, json_encode($as400AlertState, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            }
        }
    } catch (Exception $e) { writeLog('[ERROR] Traitement des alertes AS400 : ' . $e->getMessage()); }
}
$result = file_put_contents($stateFile, json_encode($newState, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
if ($result === false) writeLog("[ERROR] Impossible d'écrire dans {$stateFile}");
else writeLog("[SUCCESS] last_state.json mis à jour");
$subjects = $i18n['subjects'] ?? ['fr' => "Alerte Supervision Neo"];
foreach ($individualQueues as $recipient => $fullMessage) {
    if (empty(trim($fullMessage))) continue;
    if (getNotificationChannel($recipient, $userChannels) === 'push') {
        sendPushsaferNotification($recipient, $fullMessage, $userChannels);
        continue;
    }
    $lang = getRecipientLang($recipient, $userChannels);
    $subject = $subjects[$lang] ?? $subjects['fr'];
    $headers = "From: =?UTF-8?B?" . base64_encode($senderName) . "?= <{$fromEmail}>\r\n" .
               "Reply-To: {$replyTo}\r\n" .
               "X-Mailer: PHP/" . phpversion() . "\r\n" .
               "Content-Type: text/plain; charset=utf-8\r\n" .
               "Content-Transfer-Encoding: 8bit\r\n";
    $recipient = trim($recipient);
    writeLog("[DEBUG] Destinataire : " . var_export($recipient, true) . " [Lang: {$lang}]");
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) { writeLog("[ERROR] Adresse email invalide : " . var_export($recipient, true)); continue; }
    if (mail($recipient, $subject, $fullMessage, $headers)) writeLog("[SUCCESS] Mail envoyé à : {$recipient}");
    else writeLog("[ERROR] Échec de l'envoi à : {$recipient}");
}
