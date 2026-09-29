<?php
date_default_timezone_set('Europe/Brussels');
$configFile = '/opt/supervision/data/alert_config.json';
$netConfigFile = '/opt/supervision/data/network_alert_config.json';
$serversFile = '/opt/supervision/data/servers.json';
$stateFile = '/opt/supervision/data/last_state.json';
$channelsFile = '/opt/supervision/data/notification_channels.json';
$networkFiles = [
    'switchs' => '/opt/supervision/data/network_switchs.json',
    'aps' => '/opt/supervision/data/network_aps.json',
    'others' => '/opt/supervision/data/network_others.json'
];
$config = json_decode(@file_get_contents($configFile), true) ?: [];
$netConfig = json_decode(@file_get_contents($netConfigFile), true) ?: [];
$rawServers = json_decode(@file_get_contents($serversFile), true) ?: [];
$servers = isset($rawServers['servers']) && is_array($rawServers['servers']) ? $rawServers['servers'] : (is_array($rawServers) ? $rawServers : []);
$lastState = json_decode(@file_get_contents($stateFile), true) ?: [];
$userChannels = json_decode(@file_get_contents($channelsFile), true) ?: [];
$newState = [
    'ping' => [], 'obsolete' => [], 'disks' => [], 'uptime' => [],
    'reboot' => [], 'services' => [], 'network' => [],
    'pending' => $lastState['pending'] ?? []
];
$individualQueues = [];
$logFile = '/var/log/supervision/mailing.log';
function writeLog($message) {
    global $logFile;
    file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}
function isWithinTimeRange($startStr, $endStr) {
    if (!$startStr || !$endStr) return true;
    $now = new DateTime();
    $start = DateTime::createFromFormat('H:i', $startStr);
    $end = DateTime::createFromFormat('H:i', $endStr);
    if (!$start || !$end) return true;
    return ($start <= $end) ? ($now >= $start && $now <= $end) : ($now >= $start || $now <= $end);
}
function normalizeRecipients($recipients) {
    if (!is_array($recipients)) $recipients = array_map('trim', explode(',', (string)$recipients));
    $normalized = [];
    foreach ($recipients as $recipient) {
        $recipient = trim((string)$recipient);
        if ($recipient !== '') $normalized[] = $recipient;
    }
    return array_values(array_unique($normalized));
}
function getRecipientLang($recipient, $userChannels) {
    return $userChannels[$recipient]['lang'] ?? 'fr';
}
function getNotificationChannel($recipient, $userChannels) {
    return (($userChannels[$recipient]['channel'] ?? 'email') === 'push') ? 'push' : 'email';
}
function getPushKey($recipient, $userChannels) {
    return trim((string)($userChannels[$recipient]['push_key'] ?? ''));
}
function getPushDevice($recipient, $userChannels) {
    $device = trim((string)($userChannels[$recipient]['push_device'] ?? ''));
    return $device !== '' ? $device : 'a';
}
function cleanPushMessage($message) {
    return str_replace(['🔴', '🟢', '🚨', '🕒', '🔄', '⚠️'], '', $message);
}
function buildLocalizedMessage(string $type, array $params, string $lang): string {
    $ts = date('Y-m-d H:i:s');
    
    $T = [
        'fr' => [
            'equip' => 'Équipement', 'state' => 'État', 'ts' => 'Horodatage', 'threshold' => 'Seuil',
            'unreachable' => 'INJOIGNABLE (CRITICAL)', 'losses' => 'Pertes consécutives',
            'ping_down_title' => "🔴 [ALERTE DISPONIBILITÉ] Serveur Hors Ligne",
            'ping_up_title'   => "🟢 [RETOUR À LA NORMALE] Serveur de nouveau En Ligne",
            'ping_up_state'   => "ACCESSIBLE (UP)",
            'obs_down_title'  => "⚠️ [ALERTE DONNÉES OBSOLÈTES] Statut non mis à jour",
            'obs_down_detail' => "Détail     : Aucune métrique n'a été actualisée récemment.",
            'obs_up_title'    => "🟢 [RETOUR À LA NORMALE] Synchronisation rétablie",
            'disk_down_title' => "⚠️ [ALERTE ESPACE DISQUE] Seuil dépassé",
            'disk_up_title'   => "🟢 [RETOUR À LA NORMALE] Espace Disque",
            'disk_lbl'        => "Disque", 'disk_use' => 'Utilisation',
            'upt_down_title'  => "🕒 [ALERTE UPTIME] Redémarrage recommandé",
            'upt_up_title'    => "🟢 [RETOUR À LA NORMALE] Uptime réinitialisé",
            'upt_days'        => "jours",
            'srv_down_title'  => "🚨 [ALERTE SERVICE] %s ne fonctionne pas sur %s",
            'srv_up_title'    => "🟢 [RETOUR À LA NORMALE] %s fonctionne de nouveau sur %s",
            'reb_down_title'  => "🔄 [ALERTE REDÉMARRAGE] Reboot requis",
            'reb_reason'      => "Raison     : Mises à jour système nécessitant un redémarrage.",
            'reb_up_title'    => "🟢 [RETOUR À LA NORMALE] Redémarrage effectué",
            'net_down_title'  => "🔴 [ALERTE ÉQUIPEMENT RÉSEAU] Équipement Injoignable",
            'net_up_title'    => "🟢 [RETOUR À LA NORMALE RÉSEAU] Équipement de nouveau Joignable",
            'category'        => "Catégorie", 'ip' => "Adresse IP", 'not_specified' => "Non spécifiée"
        ],
        'en' => [
            'equip' => 'Device', 'state' => 'State', 'ts' => 'Timestamp', 'threshold' => 'Threshold',
            'unreachable' => 'UNREACHABLE (CRITICAL)', 'losses' => 'Consecutive losses',
            'ping_down_title' => "🔴 [AVAILABILITY ALERT] Server Offline",
            'ping_up_title'   => "🟢 [RECOVERY] Server Online again",
            'ping_up_state'   => "REACHABLE (UP)",
            'obs_down_title'  => "⚠️ [OUTDATED DATA ALERT] Status not updated",
            'obs_down_detail' => "Detail     : No metrics were updated recently.",
            'obs_up_title'    => "🟢 [RECOVERY] Synchronization restored",
            'disk_down_title' => "⚠️ [DISK SPACE ALERT] Threshold exceeded",
            'disk_up_title'   => "🟢 [RECOVERY] Disk Space normalized",
            'disk_lbl'        => "Disk", 'disk_use' => 'Usage',
            'upt_down_title'  => "🕒 [UPTIME ALERT] Reboot recommended",
            'upt_up_title'    => "🟢 [RECOVERY] Uptime reset",
            'upt_days'        => "days",
            'srv_down_title'  => "🚨 [SERVICE ALERT] %s is not running on %s",
            'srv_up_title'    => "🟢 [RECOVERY] %s is running again on %s",
            'reb_down_title'  => "🔄 [REBOOT ALERT] Reboot required",
            'reb_reason'      => "Reason     : System updates requiring a reboot.",
            'reb_up_title'    => "🟢 [RECOVERY] Reboot completed",
            'net_down_title'  => "🔴 [NETWORK DEVICE ALERT] Device Unreachable",
            'net_up_title'    => "🟢 [NETWORK RECOVERY] Device Reachable again",
            'category'        => "Category", 'ip' => "IP Address", 'not_specified' => "Not specified"
        ],
        'nl' => [
            'equip' => 'Apparaat', 'state' => 'Status', 'ts' => 'Tijdstip', 'threshold' => 'Drempelwaarde',
            'unreachable' => 'ONBEREIKBAAR (CRITICAL)', 'losses' => 'Opeenvolgende verliezen',
            'ping_down_title' => "🔴 [BESCHIKBAARHEIDSWAARSCHUWING] Server Offline",
            'ping_up_title'   => "🟢 [HERSTEL] Server weer Online",
            'ping_up_state'   => "BEREIKBAAR (UP)",
            'obs_down_title'  => "⚠️ [VEROUDERDE GEGEVENS] Status niet bijgewerkt",
            'obs_down_detail' => "Detail     : Er zijn onlangs geen meetwaarden bijgewerkt.",
            'obs_up_title'    => "🟢 [HERSTEL] Synchronisatie hersteld",
            'disk_down_title' => "⚠️ [SCHIJFRUIMTE WAARSCHUWING] Drempel overschreden",
            'disk_up_title'   => "🟢 [HERSTEL] Schijfruimte genormaliseerd",
            'disk_lbl'        => "Schijf", 'disk_use' => 'Gebruik',
            'upt_down_title'  => "🕒 [UPTIME WAARSCHUWING] Herstart aanbevolen",
            'upt_up_title'    => "🟢 [HERSTEL] Uptime gereset",
            'upt_days'        => "dagen",
            'srv_down_title'  => "🚨 [SERVICE WAARSCHUWING] %s werkt niet op %s",
            'srv_up_title'    => "🟢 [HERSTEL] %s werkt weer op %s",
            'reb_down_title'  => "🔄 [HERSTART WAARSCHUWING] Herstart vereist",
            'reb_reason'      => "Reden      : Systeemupdates vereisen een herstart.",
            'reb_up_title'    => "🟢 [HERSTEL] Herstart voltooid",
            'net_down_title'  => "🔴 [NETWERKAPPARAAT WAARSCHUWING] Apparaat Onbereikbaar",
            'net_up_title'    => "🟢 [NETWERKHERSTEL] Apparaat weer Bereikbaar",
            'category'        => "Categorie", 'ip' => "IP-adres", 'not_specified' => "Niet gespecificeerd"
        ],
        'de' => [
            'equip' => 'Gerät', 'state' => 'Status', 'ts' => 'Zeitstempel', 'threshold' => 'Schwellenwert',
            'unreachable' => 'NICHT ERREICHBAR (CRITICAL)', 'losses' => 'Aufeinanderfolgende Verluste',
            'ping_down_title' => "🔴 [VERFÜGBARKEITSWARNUNG] Server Offline",
            'ping_up_title'   => "🟢 [WIEDERHERSTELLUNG] Server wieder Online",
            'ping_up_state'   => "ERREICHBAR (UP)",
            'obs_down_title'  => "⚠️ [VERALTETE DATEN] Status nicht aktualisiert",
            'obs_down_detail' => "Detail     : Kürzlich wurden keine Metriken aktualisiert.",
            'obs_up_title'    => "🟢 [WIEDERHERSTELLUNG] Synchronisierung wiederhergestellt",
            'disk_down_title' => "⚠️ [FESTPLATTENWARNUNG] Schwellenwert überschritten",
            'disk_up_title'   => "🟢 [WIEDERHERSTELLUNG] Festplattenspeicher normalisiert",
            'disk_lbl'        => "Festplatte", 'disk_use' => 'Auslastung',
            'upt_down_title'  => "🕒 [UPTIME WARNUNG] Neustart empfohlen",
            'upt_up_title'    => "🟢 [WIEDERHERSTELLUNG] Uptime zurückgesetzt",
            'upt_days'        => "Tage",
            'srv_down_title'  => "🚨 [DIENSTWARNUNG] %s funktioniert nicht auf %s",
            'srv_up_title'    => "🟢 [WIEDERHERSTELLUNG] %s funktioniert wieder auf %s",
            'reb_down_title'  => "🔄 [NEUSTARTWARNUNG] Neustart erforderlich",
            'reb_reason'      => "Grund      : Systemaktualisierungen erfordern einen Neustart.",
            'reb_up_title'    => "🟢 [WIEDERHERSTELLUNG] Neustart durchgeführt",
            'net_down_title'  => "🔴 [NETZWERKGERÄTEWARNUNG] Gerät nicht erreichbar",
            'net_up_title'    => "🟢 [NETZWERK WIEDERHERSTELLUNG] Gerät wieder erreichbar",
            'category'        => "Kategorie", 'ip' => "IP-Adresse", 'not_specified' => "Nicht angegeben"
        ],
        'es' => [
            'equip' => 'Dispositivo', 'state' => 'Estado', 'ts' => 'Marca de tiempo', 'threshold' => 'Umbral',
            'unreachable' => 'INACCESIBLE (CRITICAL)', 'losses' => 'Pérdidas consecutivas',
            'ping_down_title' => "🔴 [ALERTA DE DISPONIBILIDAD] Servidor fuera de línea",
            'ping_up_title'   => "🟢 [RECUPERACIÓN] Servidor en línea de nuevo",
            'ping_up_state'   => "ACCESIBLE (UP)",
            'obs_down_title'  => "⚠️ [DATOS OBSOLETOS] Estado no actualizado",
            'obs_down_detail' => "Detalle    : No se actualizaron métricas recientemente.",
            'obs_up_title'    => "🟢 [RECUPERACIÓN] Sincronización restablecida",
            'disk_down_title' => "⚠️ [ALERTA DE DISCO] Umbral superado",
            'disk_up_title'   => "🟢 [RECUPERACIÓN] Espacio en disco normalizado",
            'disk_lbl'        => "Disco", 'disk_use' => 'Uso',
            'upt_down_title'  => "🕒 [ALERTA DE UPTIME] Se recomienda reiniciar",
            'upt_up_title'    => "🟢 [RECUPERACIÓN] Uptime reiniciado",
            'upt_days'        => "días",
            'srv_down_title'  => "🚨 [ALERTA DE SERVICIO] %s no funciona en %s",
            'srv_up_title'    => "🟢 [RECUPERACIÓN] %s vuelve a funcionar en %s",
            'reb_down_title'  => "🔄 [ALERTA DE REINICIO] Reinicio necesario",
            'reb_reason'      => "Razón      : Actualizaciones del sistema que requieren reinicio.",
            'reb_up_title'    => "🟢 [RECUPERACIÓN] Reinicio completado",
            'net_down_title'  => "🔴 [ALERTA DE RED] Dispositivo inaccesible",
            'net_up_title'    => "🟢 [RECUPERACIÓN DE RED] Dispositivo accesible de nuevo",
            'category'        => "Categoría", 'ip' => "Dirección IP", 'not_specified' => "No especificada"
        ],
        'pt' => [
            'equip' => 'Dispositivo', 'state' => 'Estado', 'ts' => 'Carimbo de data/hora', 'threshold' => 'Limite',
            'unreachable' => 'INACESSÍVEL (CRITICAL)', 'losses' => 'Perdas consecutivas',
            'ping_down_title' => "🔴 [ALERTA DE DISPONIBILIDADE] Servidor Offline",
            'ping_up_title'   => "🟢 [RECUPERAÇÃO] Servidor Online novamente",
            'ping_up_state'   => "ACESSÍVEL (UP)",
            'obs_down_title'  => "⚠️ [DADOS OBSOLETOS] Estado não atualizado",
            'obs_down_detail' => "Detalhe    : Nenhuma métrica foi atualizada recentemente.",
            'obs_up_title'    => "🟢 [RECUPERAÇÃO] Sincronização restabelecida",
            'disk_down_title' => "⚠️ [ALERTA DE DISCO] Limite excedido",
            'disk_up_title'   => "🟢 [RECUPERAÇÃO] Espaço em disco normalizado",
            'disk_lbl'        => "Disco", 'disk_use' => 'Utilização',
            'upt_down_title'  => "🕒 [ALERTA DE UPTIME] Reinicialização recomendada",
            'upt_up_title'    => "🟢 [RECUPERAÇÃO] Uptime reiniciado",
            'upt_days'        => "dias",
            'srv_down_title'  => "🚨 [ALERTA DE SERVIÇO] %s não está a funcionar em %s",
            'srv_up_title'    => "🟢 [RECUPERAÇÃO] %s voltou a funcionar em %s",
            'reb_down_title'  => "🔄 [ALERTA DE REINICIALIZAÇÃO] Reinicialização necessária",
            'reb_reason'      => "Razão      : Atualizações do sistema que requerem reinicialização.",
            'reb_up_title'    => "🟢 [RECUPERAÇÃO] Reinicialização concluída",
            'net_down_title'  => "🔴 [ALERTA DE REDE] Dispositivo inacessível",
            'net_up_title'    => "🟢 [RECUPERAÇÃO DE REDE] Dispositivo acessível novamente",
            'category'        => "Categoria", 'ip' => "Endereço IP", 'not_specified' => "Não especificado"
        ],
        'it' => [
            'equip' => 'Dispositivo', 'state' => 'Stato', 'ts' => 'Data e ora', 'threshold' => 'Soglia',
            'unreachable' => 'RAGGIUNGIBILE (CRITICAL)', 'losses' => 'Perdite consecutive',
            'ping_down_title' => "🔴 [ALLERTA DISPONIBILITÀ] Server Offline",
            'ping_up_title'   => "🟢 [RIPRISTINO] Server di nuovo Online",
            'ping_up_state'   => "RAGGIUNGIBILE (UP)",
            'obs_down_title'  => "⚠️ [DATI OBSOLETI] Stato non aggiornato",
            'obs_down_detail' => "Dettaglio  : Nessuna metrica aggiornata di recente.",
            'obs_up_title'    => "🟢 [RIPRISTINO] Sincronizzazione ripristinata",
            'disk_down_title' => "⚠️ [ALLERTA DISCO] Soglia superata",
            'disk_up_title'   => "🟢 [RIPRISTINO] Spazio disco normalizzato",
            'disk_lbl'        => "Disco", 'disk_use' => 'Utilizzo',
            'upt_down_title'  => "🕒 [ALLERTA UPTIME] Riavvio consigliato",
            'upt_up_title'    => "🟢 [RIPRISTINO] Uptime reimpostato",
            'upt_days'        => "giorni",
            'srv_down_title'  => "🚨 [ALLERTA SERVIZIO] %s non funziona su %s",
            'srv_up_title'    => "🟢 [RIPRISTINO] %s funziona di nuovo su %s",
            'reb_down_title'  => "🔄 [ALLERTA RIAVVIO] Riavvio richiesto",
            'reb_reason'      => "Motivo     : Aggiornamenti di sistema che richiedono il riavvio.",
            'reb_up_title'    => "🟢 [RIPRISTINO] Riavvio completato",
            'net_down_title'  => "🔴 [ALLERTA RETE] Dispositivo non raggiungibile",
            'net_up_title'    => "🟢 [RIPRISTINO RETE] Dispositivo di nuovo raggiungibile",
            'category'        => "Categoria", 'ip' => "Indirizzo IP", 'not_specified' => "Non specificato"
        ]
    ];
    $d = $T[$lang] ?? $T['fr'];
    switch ($type) {
        case 'ping_down':
            return "{$d['ping_down_title']}\n{$d['equip']} : {$params['name']}\n{$d['state']}       : {$d['unreachable']}\n{$d['losses']} : {$params['losses']}\n{$d['threshold']}      : {$params['maxLoss']}\n{$d['ts']} : {$ts}";
        case 'ping_up':
            return "{$d['ping_up_title']}\n{$d['equip']} : {$params['name']}\n{$d['state']}       : {$d['ping_up_state']}\n{$d['ts']} : {$ts}";
        case 'obsolete_down':
            return "{$d['obs_down_title']}\n{$d['equip']} : {$params['name']}\n{$d['obs_down_detail']}\n{$d['ts']} : {$ts}";
        case 'obsolete_up':
            return "{$d['obs_up_title']}\n{$d['equip']} : {$params['name']}\n{$d['ts']} : {$ts}";
        case 'disk_down':
            return "{$d['disk_down_title']}\n{$d['equip']} : {$params['name']}\n{$d['disk_lbl']}     : {$params['disk']}\n{$d['disk_use']}: {$params['used']}%\n{$d['ts']} : {$ts}";
        case 'disk_up':
            return "{$d['disk_up_title']}\n{$d['equip']} : {$params['name']}\n{$d['disk_lbl']}     : {$params['disk']}\n{$d['disk_use']}: {$params['used']}%\n{$d['ts']} : {$ts}";
        case 'uptime_down':
            return "{$d['upt_down_title']}\n{$d['equip']} : {$params['name']}\nUptime     : {$params['days']} {$d['upt_days']}\n{$d['ts']} : {$ts}";
        case 'uptime_up':
            return "{$d['upt_up_title']}\n{$d['equip']} : {$params['name']}\nUptime     : {$params['days']} {$d['upt_days']}\n{$d['ts']} : {$ts}";
        case 'service_down':
            $t = sprintf($d['srv_down_title'], $params['label'], $params['name']);
            return "{$t}\n{$d['ts']} : {$ts}";
        case 'service_up':
            $t = sprintf($d['srv_up_title'], $params['label'], $params['name']);
            return "{$t}\n{$d['ts']} : {$ts}";
        case 'reboot_down':
            return "{$d['reb_down_title']}\n{$d['equip']} : {$params['name']}\n{$d['reb_reason']}\n{$d['ts']} : {$ts}";
        case 'reboot_up':
            return "{$d['reb_up_title']}\n{$d['equip']} : {$params['name']}\n{$d['ts']} : {$ts}";
        case 'network_down':
            return "{$d['net_down_title']}\n{$d['category']}  : " . strtoupper($params['cat']) . "\n{$d['equip']} : {$params['name']}\n{$d['ip']} : " . ($params['ip'] ?: $d['not_specified']) . "\n{$d['state']}       : DOWN\n{$d['losses']}     : {$params['losses']}\n{$d['threshold']}      : {$params['maxLoss']}\n{$d['ts']} : {$ts}";
        case 'network_up':
            return "{$d['net_up_title']}\n{$d['category']}  : " . strtoupper($params['cat']) . "\n{$d['equip']} : {$params['name']}\n{$d['ip']} : " . ($params['ip'] ?: $d['not_specified']) . "\n{$d['state']}       : UP\n{$d['ts']} : {$ts}";
    }
    return "";
}

function queueEvent(&$queues, $recipients, $userChannels, $type, $params) {
    foreach (normalizeRecipients($recipients) as $recipient) {
        $lang = getRecipientLang($recipient, $userChannels);
        $msg = buildLocalizedMessage($type, $params, $lang);
        if (!isset($queues[$recipient])) $queues[$recipient] = '';
        $queues[$recipient] .= $msg . "\n----------------------------------------\n\n";
    }
}

function notifyStateChange(&$queues, &$newState, $statePath, $currentState, $previousState, $inTime, $recipients, $userChannels, $alertType, $recoveryType, $params) {
    $parts = explode('.', $statePath);
    $pending = &$newState['pending'];
    $pendingValue = $pending;
    foreach ($parts as $part) {
        if (!is_array($pendingValue) || !array_key_exists($part, $pendingValue)) {
            $pendingValue = null;
            break;
        }
        $pendingValue = $pendingValue[$part];
    }
    $changed = ($currentState !== $previousState);
    if ($currentState && $changed && !$inTime) {
        $ref = &$pending;
        foreach ($parts as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) $ref[$part] = [];
            $ref = &$ref[$part];
        }
        $ref = true;
        return;
    }
    if (!$currentState && $pendingValue !== null) {
        $ref = &$pending;
        foreach ($parts as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref = null;
                break;
            }
            $ref = &$ref[$part];
        }
        if (is_array($ref)) unset($ref[$parts[count($parts) - 1]]);
        return;
    }
    if (!$inTime) return;
    if ($changed || $pendingValue === true) {
        queueEvent($queues, $recipients, $userChannels, $currentState ? $alertType : $recoveryType, $params);
        $ref = &$pending;
        foreach ($parts as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref = null;
                break;
            }
            $ref = &$ref[$part];
        }
        if (is_array($ref)) unset($ref[$parts[count($parts) - 1]]);
    }
}
function sendPushsaferNotification($recipient, $message, $userChannels) {
    $pushKey = getPushKey($recipient, $userChannels);
    $pushDevice = getPushDevice($recipient, $userChannels);
    if ($pushKey === '') {
        writeLog("[ERROR] Clé Pushsafer absente pour : {$recipient}");
        return false;
    }
    $lang = getRecipientLang($recipient, $userChannels);
    $titles = [
        'fr' => "Supervision Neo : Notification / Alerte d'état",
        'en' => "Neo Supervision: State Notification / Alert",
        'nl' => "Neo Toezicht: Statusmelding / Waarschuwing",
        'de' => "Neo Überwachung: Statusbenachrichtigung / Warnung",
        'es' => "Supervisión Neo: Notificación / Alerta de estado",
        'pt' => "Supervisão Neo: Notificação / Alerta de estado",
        'it' => "Supervisione Neo: Notifica / Allerta di stato"
    ];
    $title = $titles[$lang] ?? $titles['fr'];
    $payload = http_build_query([
        'k' => $pushKey,
        'd' => $pushDevice,
        't' => $title,
        'm' => cleanPushMessage($message)
    ], '', '&', PHP_QUERY_RFC3986);
    $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($payload) . "\r\n", 'content' => $payload, 'timeout' => 15, 'ignore_errors' => true]]);
    $response = @file_get_contents('https://www.pushsafer.com/api', false, $context);
    if ($response === false) {
        writeLog("[ERROR] Échec HTTP Pushsafer pour : {$recipient}");
        return false;
    }
    $result = json_decode($response, true);
    if (is_array($result) && isset($result['status'])) {
        if ((string)$result['status'] === '1' || $result['status'] === 1 || $result['status'] === true) {
            writeLog("[SUCCESS] Push envoyé à : {$recipient} [Cible: {$pushDevice}]");
            return true;
        }
        $error = $result['error'] ?? $result['message'] ?? 'Réponse Pushsafer en échec';
        writeLog("[ERROR] Pushsafer pour {$recipient} : " . (string)$error);
        return false;
    }
    writeLog("[ERROR] Réponse Pushsafer invalide pour : {$recipient}");
    return false;
}
foreach ($servers as $srv) {
    $name = $srv['name'] ?? null;
    $sType = strtolower($srv['type'] ?? 'linux');
    if (!$name) continue;
    $cfg = $config[$name] ?? null;
    if (!$cfg || empty($cfg['global_enabled'])) continue;
    $emails = $cfg['emails'] ?? [];
    $inTime = isWithinTimeRange($cfg['time_start'] ?? '00:00', $cfg['time_end'] ?? '23:59');
    $currStatus = 'UP';
    $oldStatus = $lastState['ping'][$name] ?? 'UP';
    if (!empty($cfg['offline_enabled'])) {
        $pingFile = "/opt/supervision/data/{$name}_ping.json";
        $consecutiveLosses = 0;
        $maxLoss = max(0, intval($cfg['max_loss'] ?? 1));
        if (file_exists($pingFile)) {
            $pData = json_decode(@file_get_contents($pingFile), true) ?: [];
            $consecutiveLosses = max(0, intval($pData['consecutive_losses'] ?? 0));
            $currStatus = ($consecutiveLosses > $maxLoss) ? 'DOWN' : 'UP';
        }
        notifyStateChange($individualQueues, $newState, "ping.$name", $currStatus === 'DOWN', $oldStatus === 'DOWN', $inTime, $emails, $userChannels, 'ping_down', 'ping_up', [
            'name' => $name, 'losses' => $consecutiveLosses, 'maxLoss' => $maxLoss
        ]);
        $newState['ping'][$name] = $currStatus;
    }
    $isObsolete = false;
    if ($currStatus !== 'DOWN') {
        $now = time();
        $checks = ["/opt/supervision/data/{$name}_status.json" => 4500, "/opt/supervision/data/{$name}_disks.json" => 23400, "/opt/supervision/data/{$name}_ram.json" => 1200, "/opt/supervision/data/{$name}_uptime.json" => 900, "/opt/supervision/data/{$name}_services.json" => 1200];
        foreach ($checks as $filePath => $maxAge) {
            if (file_exists($filePath) && ($now - filemtime($filePath)) <= $maxAge) {
                $isObsolete = false;
                break;
            }
            $isObsolete = true;
        }
    }
    $wasObsolete = $lastState['obsolete'][$name] ?? false;
    notifyStateChange($individualQueues, $newState, "obsolete.$name", $isObsolete, $wasObsolete, $inTime, $emails, $userChannels, 'obsolete_down', 'obsolete_up', [
        'name' => $name
    ]);
    $newState['obsolete'][$name] = $isObsolete;
    if (!empty($cfg['disks']) && is_array($cfg['disks'])) {
        $diskFile = "/opt/supervision/data/{$name}_disks.json";
        if (file_exists($diskFile)) {
            $diskData = json_decode(@file_get_contents($diskFile), true);
            $latestDisks = $diskData[0]['disks'] ?? [];
            foreach ($cfg['disks'] as $diskName => $dConf) {
                $warnEnabled = !empty($dConf['warn_enabled']);
                $critEnabled = !empty($dConf['crit_enabled']);
                if (!$warnEnabled && !$critEnabled) continue;
                $warnThreshold = (int)($dConf['warn_threshold'] ?? 80);
                $critThreshold = (int)($dConf['crit_threshold'] ?? 90);
                $usedPercent = isset($latestDisks[$diskName]['used_percent']) ? (int)str_replace('%', '', $latestDisks[$diskName]['used_percent']) : 0;
                $isDiskAlert = ($critEnabled && $usedPercent >= $critThreshold) || ($warnEnabled && $usedPercent >= $warnThreshold);
                $wasDiskAlert = $lastState['disks'][$name][$diskName] ?? false;
                notifyStateChange($individualQueues, $newState, "disks.$name.$diskName", $isDiskAlert, $wasDiskAlert, $inTime, $emails, $userChannels, 'disk_down', 'disk_up', [
                    'name' => $name, 'disk' => $diskName, 'used' => $usedPercent
                ]);
                $newState['disks'][$name][$diskName] = $isDiskAlert;
            }
        }
    }
    if (!empty($cfg['uptime_enabled'])) {
        $uptFile = "/opt/supervision/data/{$name}_uptime.json";
        if (file_exists($uptFile)) {
            $uData = json_decode(@file_get_contents($uptFile), true) ?: [];
            $days = (int)($uData['uptime_days'] ?? 0);
            $max = (int)($cfg['uptime_threshold'] ?? 30);
            $isAlert = $days >= $max;
            $wasAlert = $lastState['uptime'][$name] ?? false;
            notifyStateChange($individualQueues, $newState, "uptime.$name", $isAlert, $wasAlert, $inTime, $emails, $userChannels, 'uptime_down', 'uptime_up', [
                'name' => $name, 'days' => $days
            ]);
            $newState['uptime'][$name] = $isAlert;
        }
    }
    $servicesFile = "/opt/supervision/data/{$name}_services.json";
    if (file_exists($servicesFile)) {
        $servicesData = json_decode(@file_get_contents($servicesFile), true) ?: [];
        $srvServices = $servicesData['services'] ?? [];
        if ($sType === 'windows') {
            $serviceDefinitions = [];
            if (!empty($cfg['powerbi_enabled'])) $serviceDefinitions['powerbi'] = ['powerbi_gateway', 'Power BI Gateway'];
            if (!empty($cfg['wsus_enabled'])) $serviceDefinitions['wsus'] = ['wsus_service', 'WSUS'];
            if (!empty($cfg['outlook_enabled'])) $serviceDefinitions['outlook'] = ['outlook', 'Outlook'];
            if (!empty($cfg['stagenow_enabled'])) $serviceDefinitions['stagenow'] = ['stagenow', 'StageNow'];
            foreach ($serviceDefinitions as $serviceKey => [$serviceName, $label]) {
                if ($serviceKey === 'wsus') {
                    $serviceStatus = $srvServices['wsus_service'] ?? 'unknown';
                    $poolStatus = $srvServices['wsus_apppool'] ?? 'unknown';
                    $serviceStatus = is_array($serviceStatus) ? ($serviceStatus['status'] ?? 'unknown') : $serviceStatus;
                    $poolStatus = is_array($poolStatus) ? ($poolStatus['status'] ?? 'unknown') : $poolStatus;
                    $isAlert = !in_array(strtolower((string)$serviceStatus), ['running', 'active'], true) || !in_array(strtolower((string)$poolStatus), ['running', 'active'], true);
                } else {
                    $status = $srvServices[$serviceName] ?? 'unknown';
                    $status = is_array($status) ? ($status['status'] ?? 'unknown') : $status;
                    $isAlert = !in_array(strtolower((string)$status), ['running', 'active', 'ok'], true);
                }
                $wasAlert = $lastState['services'][$name][$serviceKey] ?? false;
                notifyStateChange($individualQueues, $newState, "services.$name.$serviceKey", $isAlert, $wasAlert, $inTime, $emails, $userChannels, 'service_down', 'service_up', [
                    'name' => $name, 'label' => $label
                ]);
                $newState['services'][$name][$serviceKey] = $isAlert;
            }
        }
    }
    if (!empty($cfg['reboot_enabled'])) {
        $stFile = "/opt/supervision/data/{$name}_status.json";
        if (file_exists($stFile)) {
            $sData = json_decode(@file_get_contents($stFile), true) ?: [];
            $needsReboot = !empty($sData['status']['reboot_required']);
            $wasReboot = $lastState['reboot'][$name] ?? false;
            notifyStateChange($individualQueues, $newState, "reboot.$name", $needsReboot, $wasReboot, $inTime, $emails, $userChannels, 'reboot_down', 'reboot_up', [
                'name' => $name
            ]);
            $newState['reboot'][$name] = $needsReboot;
        }
    }
}
foreach ($networkFiles as $category => $filePath) {
    if (!file_exists($filePath)) continue;
    $netData = json_decode(@file_get_contents($filePath), true) ?: [];
    $equipments = [];
    if (isset($netData['sites']) && is_array($netData['sites'])) {
        foreach ($netData['sites'] as $eqList) foreach ($eqList as $eq) $equipments[] = $eq;
    } elseif (is_array($netData)) {
        foreach ($netData as $val) {
            if (is_array($val) && isset($val[0]['name'])) foreach ($val as $eq) $equipments[] = $eq;
            elseif (is_array($val) && isset($val['name'])) $equipments[] = $val;
        }
    }
    foreach ($equipments as $eq) {
        $name = $eq['name'] ?? null;
        if (!$name) continue;
        $cfg = $netConfig[$category][$name] ?? [];
        $emails = $cfg['emails'] ?? [];
        $inTime = isWithinTimeRange($cfg['time_start'] ?? '00:00', $cfg['time_end'] ?? '23:59');
        $currStatus = 'UP';
        if (isset($eq['status'])) $currStatus = in_array(strtolower($eq['status']), ['up', 'online'], true) ? 'UP' : 'DOWN';
        elseif (array_key_exists('down_since', $eq)) $currStatus = $eq['down_since'] === null ? 'UP' : 'DOWN';
        $failuresCount = max(0, intval($eq['failures_count'] ?? $eq['failure_count'] ?? $eq['consecutive_losses'] ?? 0));
        $maxLoss = max(0, intval($cfg['max_loss'] ?? $cfg['max_loss_allowed'] ?? $eq['max_loss_allowed'] ?? 0));
        $criticalStatus = $currStatus === 'DOWN' && $failuresCount > $maxLoss;
        $oldCriticalStatus = ($lastState['network'][$category][$name] ?? 'UP') === 'DOWN';
        notifyStateChange($individualQueues, $newState, "network.$category.$name", $criticalStatus, $oldCriticalStatus, $inTime, $emails, $userChannels, 'network_down', 'network_up', [
            'name' => $name, 'cat' => $category, 'ip' => $eq['ip'] ?? '', 'losses' => $failuresCount, 'maxLoss' => $maxLoss
        ]);
        $newState['network'][$category][$name] = $criticalStatus ? 'DOWN' : 'UP';
    }
}
$result = file_put_contents($stateFile, json_encode($newState, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
if ($result === false) writeLog("[ERROR] Impossible d'écrire dans {$stateFile}");
else writeLog("[SUCCESS] last_state.json mis à jour");
$subjects = [
    'fr' => "Alerte Supervision Neo",
    'en' => "Neo Supervision Alert",
    'nl' => "Neo Toezicht Waarschuwing",
    'de' => "Neo Überwachung Warnung",
    'es' => "Alerta Supervisión Neo",
    'pt' => "Alerta Supervisão Neo",
    'it' => "Allerta Supervisione Neo"
];

foreach ($individualQueues as $recipient => $fullMessage) {
    if (empty(trim($fullMessage))) continue;
    if (getNotificationChannel($recipient, $userChannels) === 'push') {
        sendPushsaferNotification($recipient, $fullMessage, $userChannels);
        continue;
    }
    $mailConfigFile = "/opt/supervision/data/mail_config.json";
    $mailConfig = file_exists($mailConfigFile) ? (json_decode(@file_get_contents($mailConfigFile), true) ?: []) : [];
    $fromEmail = !empty($mailConfig['from_email']) ? trim($mailConfig['from_email']) : 'supervision@localhost';
    $senderName = !empty($mailConfig['sender_name']) ? trim($mailConfig['sender_name']) : 'Neo Supervision';
    $replyTo = !empty($mailConfig['reply_to']) ? trim($mailConfig['reply_to']) : $fromEmail;
    $lang = getRecipientLang($recipient, $userChannels);
    $subject = $subjects[$lang] ?? $subjects['fr'];
    $headers = "From: =?UTF-8?B?" . base64_encode($senderName) . "?= <{$fromEmail}>\r\n" .
               "Reply-To: {$replyTo}\r\n" .
               "X-Mailer: PHP/" . phpversion() . "\r\n" .
               "Content-Type: text/plain; charset=utf-8\r\n" .
               "Content-Transfer-Encoding: 8bit\r\n";
    $recipient = trim($recipient);
    writeLog("[DEBUG] Destinataire : " . var_export($recipient, true) . " [Lang: {$lang}]");
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        writeLog("[ERROR] Adresse email invalide : " . var_export($recipient, true));
        continue;
    }
    if (mail($recipient, $subject, $fullMessage, $headers)) writeLog("[SUCCESS] Mail envoyé à : {$recipient}");
    else writeLog("[ERROR] Échec de l'envoi à : {$recipient}");
}
