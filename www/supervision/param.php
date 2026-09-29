<?php
require_once '/var/www/common/init.php';
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location:index.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_pcs') {
    header('Content-Type: application/json; charset=utf-8');
    $rawPcs = json_decode($_POST['pcs_data'] ?? '[]', true);
    if (!is_array($rawPcs)) {
        echo json_encode([
            'success' => false, 
            'error'   => __('error_invalid_data_format', 'Format de données invalide.')
        ]);
        exit();
    }
    $usersPcFile = '/opt/supervision/data/pc/users.json';
    $currentData = [];
    if (file_exists($usersPcFile)) {
        $currentData = json_decode(file_get_contents($usersPcFile), true) ?: [];
    }
    $newData = [];
    foreach ($rawPcs as $row) {
        $name = trim($row['computer'] ?? '');
        if ($name === '') continue;
        $existing = $currentData[$name] ?? [];
        $newData[$name] = array_merge($existing, [
            'user'   => trim($row['user'] ?? ''),
            'site'   => trim($row['site'] ?? ''),
            'detail' => trim($row['detail'] ?? ''),
            'mac'    => trim($row['mac'] ?? '')
        ]);
    }
    $deletedPcs = array_diff(array_keys($currentData), array_keys($newData));
    $wupdateDir = '/mnt/wupdate';
    $warnings = [];
    if (!empty($deletedPcs)) {
        foreach ($deletedPcs as $delPc) {
            $safeName = basename($delPc);
            $candidates = array_unique([
                "$wupdateDir/$safeName.txt",
                "$wupdateDir/" . strtoupper($safeName) . ".txt",
                "$wupdateDir/" . strtolower($safeName) . ".txt",
            ]);
            foreach ($candidates as $filePath) {
                if (file_exists($filePath)) {
                    if (!@unlink($filePath)) {
                        $warnings[] = sprintf(
                            __('warn_pc_file_not_deleted', 'Fichier %s non supprimé dans %s (droits insuffisants).'),
                            $safeName . '.txt',
                            $wupdateDir
                        );
                    }
                }
            }
        }
    }
    if (file_put_contents($usersPcFile, json_encode($newData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false) {
        $response = ['success' => true];
        if (!empty($warnings)) {
            $response['warning'] = implode("\n", $warnings);
        }
        echo json_encode($response);
    } else {
        echo json_encode([
            'success' => false, 
            'error'   => sprintf(__('error_write_file', "Erreur d'écriture sur %s"), $usersPcFile)
        ]);
    }
    exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_mail_config') {
    header('Content-Type: application/json; charset=utf-8');
    $mailConfigFile = '/opt/supervision/data/mail_config.json';
    $currentMailConfig = file_exists($mailConfigFile) ? (json_decode(file_get_contents($mailConfigFile), true) ?: []) : [];

    $newPass = $_POST['smtp_pass'] ?? '';
    $smtpPass = ($newPass !== '') ? $newPass : ($currentMailConfig['smtp_pass'] ?? '');

    $newMailConfig = [
        'from_name'   => trim($_POST['from_name'] ?? 'NeoVision Supervision'),
        'from_email'  => trim($_POST['from_email'] ?? ''),
        'method'      => in_array($_POST['method'] ?? '', ['mail', 'smtp']) ? $_POST['method'] : 'mail',
        'smtp_host'   => trim($_POST['smtp_host'] ?? ''),
        'smtp_port'   => intval($_POST['smtp_port'] ?? 587),
        'smtp_secure' => in_array($_POST['smtp_secure'] ?? '', ['none', 'tls', 'ssl']) ? $_POST['smtp_secure'] : 'none',
        'smtp_user'   => trim($_POST['smtp_user'] ?? ''),
        'smtp_pass'   => $smtpPass,
    ];

    if (!is_dir(dirname($mailConfigFile))) {
        mkdir(dirname($mailConfigFile), 0755, true);
    }

    if (file_put_contents($mailConfigFile, json_encode($newMailConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => sprintf(__('error_write_file', "Erreur d'écriture sur %s"), $mailConfigFile)]);
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_mail') {
    header('Content-Type: application/json; charset=utf-8');
    $to = trim($_POST['to'] ?? '');
    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => __('error_invalid_email', 'Adresse e-mail invalide.')]);
        exit();
    }

    $mailConfigFile = '/opt/supervision/data/mail_config.json';
    $cfg = file_exists($mailConfigFile) ? (json_decode(file_get_contents($mailConfigFile), true) ?: []) : [];

    $fromName = !empty($cfg['from_name']) ? $cfg['from_name'] : 'NeoVision Supervision';
    $fromMail = !empty($cfg['from_email']) ? $cfg['from_email'] : 'supervision@domaine.local';
    $subject = 'NeoVision - Test de notification e-mail';
    $message = "Bonjour,\n\nCeci est un message de test envoyé depuis NeoVision Supervision pour valider la configuration e-mail.\n\nDate : " . date('Y-m-d H:i:s');
    $headers = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromMail}>\r\n" .
               "Reply-To: {$fromMail}\r\n" .
               "X-Mailer: PHP/" . phpversion() . "\r\n" .
               "Content-Type: text/plain; charset=UTF-8\r\n";

    if (($cfg['method'] ?? 'mail') === 'smtp') {
        try {
            $host = $cfg['smtp_host'] ?? 'localhost';
            $port = intval($cfg['smtp_port'] ?? 587);
            $secure = $cfg['smtp_secure'] ?? 'none';
            $user = $cfg['smtp_user'] ?? '';
            $pass = $cfg['smtp_pass'] ?? '';
            $prefix = ($secure === 'ssl') ? 'ssl://' : '';
            $socket = @fsockopen($prefix . $host, $port, $errno, $errstr, 15);
            if (!$socket) {
                throw new Exception("Connexion SMTP échouée sur $host:$port ($errno: $errstr)");
            }
            $read = function() use ($socket) {
                $data = '';
                while ($str = fgets($socket, 515)) {
                    $data .= $str;
                    if (substr($str, 3, 1) === ' ') break;
                }
                return $data;
            };
            $write = function($cmd) use ($socket) {
                fputs($socket, $cmd . "\r\n");
            };
            $read();
            $write('EHLO ' . gethostname());
            $read();
            if ($secure === 'tls') {
                $write('STARTTLS');
                $read();
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $write('EHLO ' . gethostname());
                $read();
            }
            if (!empty($user) && !empty($pass)) {
                $write('AUTH LOGIN');
                $read();
                $write(base64_encode($user));
                $read();
                $write(base64_encode($pass));
                $authRes = $read();
                if (strpos($authRes, '235') === false) {
                    throw new Exception("Authentification SMTP échouée : $authRes");
                }
            }
            $write("MAIL FROM:<{$fromMail}>");
            $read();
            $write("RCPT TO:<{$to}>");
            $read();
            $write("DATA");
            $read();
            $write("Subject: {$subject}\r\n{$headers}\r\n\r\n{$message}\r\n.");
            $dataRes = $read();
            $write("QUIT");
            fclose($socket);

            if (strpos($dataRes, '250') === false) {
                throw new Exception("Échec envoi données : $dataRes");
            }
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        $sent = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $message, $headers);
        if ($sent) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => "La fonction mail() de PHP n'a pas pu délivrer l'e-mail. Vérifiez Postfix/Sendmail local."]);
        }
    }
    exit();
}
$activeTab = isset($_GET['lang']) ? 'langue' : ($_COOKIE['activeParamView'] ?? 'comptes');
$serversJsonPath = '/opt/supervision/data/servers.json';
$serversRaw = file_exists($serversJsonPath) ? (json_decode(file_get_contents($serversJsonPath), true) ?? []) : [];
$servers = isset($serversRaw['servers']) && is_array($serversRaw['servers']) ? $serversRaw['servers'] : $serversRaw;
$mailConfigJsonPath = '/opt/supervision/data/mail_config.json';
$mailConfig = file_exists($mailConfigJsonPath) ? (json_decode(file_get_contents($mailConfigJsonPath), true) ?? []) : [];
$accountsJsonPath = '/opt/supervision/data/accounts.json';
$accountsData = file_exists($accountsJsonPath) ? (json_decode(file_get_contents($accountsJsonPath), true) ?? []) : [];
$localSupervisorUser = !empty($accountsData['local_user']) ? $accountsData['local_user'] : 'root';
$sshAccounts = $accountsData['ssh_accounts'] ?? [];
$usersPcFile = '/opt/supervision/data/pc/users.json';
$pcsList = file_exists($usersPcFile) ? json_decode(file_get_contents($usersPcFile), true) : [];
if (!is_array($pcsList)) {
    $pcsList = [];
}
$winrmAccounts = $accountsData['winrm_accounts'] ?? [];
$wolJsonPath = '/opt/supervision/data/wol_relays.json';
$wolData = file_exists($wolJsonPath) ? (json_decode(file_get_contents($wolJsonPath), true) ?? []) : [];
$wolRelays = $wolData['relays'] ?? [];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($_currentLang ?? 'fr') ?>">
<head>
    <meta charset="UTF-8">
    <title><?= __('settings_page_title', 'Paramètres - Supervision') ?></title>
    <link rel="icon" href="favicon.ico">
    <script>(function(){const isDark=document.cookie.split("; ").reduce((r,v)=>{const p=v.split("=");return p[0].trim()==="darkMode"?p[1]==="enabled":r;},false);if(isDark){document.documentElement.classList.add('dark-mode');}})();</script>
    <link rel="stylesheet" href="/assets/style.css">
<?php
$LANG = $LANG ?? [];
?>
<script>
if (window.location.search.includes('lang=')) {
    window.history.replaceState({}, document.title, window.location.pathname);
}
const I18N = <?= json_encode($LANG, JSON_UNESCAPED_UNICODE) ?>;
document.querySelectorAll(".param-nav-btn").forEach(btn => {
    btn.onclick = () => {
        const param = btn.dataset.param;
        document.querySelectorAll(".param-nav-btn").forEach(b => b.classList.remove("active"));
        document.querySelectorAll(".param-view").forEach(v => v.style.display = "none");
        btn.classList.add("active");
        const targetView = document.getElementById(`view-${param}`);
        if (targetView) targetView.style.display = "block";
        localStorage.setItem("activeParamView", param);
        document.cookie = `activeParamView=${param};path=/;max-age=2592000;SameSite=Lax`;
        window.scrollTo({ top: 0 });
        const mainEl = document.getElementById("main");
        if (mainEl) mainEl.scrollTop = 0;
        updateScrollButton();
        setTimeout(updateScrollButton, 60);
    };
});
function t(key, fallback) { return I18N[key] || fallback || key; }
</script>
</head>
<body class="dashboard <?= $isDark ? 'dark-mode' : '' ?>">
<div id="sidebar">
    <h2><?= __('settings_title', 'Paramètres') ?></h2>
    <div class="sidebar-servers-container">
        <button class="btn btn-blue all-disks-link param-nav-btn <?= $activeTab === 'comptes' ? 'active' : '' ?>" data-param="comptes"><?= __('tab_accounts', 'Comptes') ?></button>
        <button class="btn btn-blue all-disks-link param-nav-btn <?= $activeTab === 'serveurs' ? 'active' : '' ?>" data-param="serveurs"><?= __('tab_servers', 'Serveurs') ?></button>
	<button class="btn btn-blue all-disks-link param-nav-btn <?= $activeTab === 'pcs' ? 'active' : '' ?>" data-param="pcs"><?= __('tab_computers', 'Ordinateurs') ?></button>
	<button class="btn btn-blue all-disks-link param-nav-btn <?= $activeTab === 'wol' ? 'active' : '' ?>" data-param="wol"><?= __('tab_wol', 'Relais WOL') ?></button>
        <button class="btn btn-blue all-disks-link param-nav-btn <?= $activeTab === 'mail' ? 'active' : '' ?>" data-param="mail">📧 <?= __('tab_mail_config', 'E-mails') ?></button>
	<button class="btn btn-blue all-disks-link param-nav-btn <?= $activeTab === 'langue' ? 'active' : '' ?>" data-param="langue"><?= __('tab_language', 'Langue') ?></button>
    </div>
</div>
<div id="main">
    <div class="header-main">
        <h1 id="param-main-title"><?= __('settings_title', 'Paramètres') ?></h1>
        <div class="header-buttons">
            <a href="dashboard.php" class="btn btn-gray"><?= __('btn_dashboard', 'Dashboard') ?></a>
            <button id="toggleDarkMode" class="btn btn-gray"><?= __('btn_darkmode', 'Mode 🌙') ?></button>
            <a href="logout.php" class="btn btn-red"><?= __('btn_logout', 'Se déconnecter') ?></a>
        </div>
    </div>
    <div id="view-comptes" class="view-section param-view" style="<?= $activeTab === 'comptes' ? '' : 'display:none;' ?>">
        <div class="stat-card">
            <div class="section-header-row">
                <h1 style="margin:0;"><?= __('accounts_main_title', 'Gestion des Comptes & Accès') ?></h1>
                <div class="section-header-actions">
                    <button type="button" id="btnCancelAccounts" class="btn btn-gray" onclick="location.reload();"><?= __('btn_cancel', 'Annuler') ?></button>
                    <button type="button" id="btnSaveAccounts" class="btn btn-red"><?= __('btn_save', 'Enregistrer') ?></button>
                </div>
            </div>
            <p><?= __('accounts_desc', 'Définissez les comptes utilisés pour déployer les clés SSH et pour la connexion WinRM aux serveurs Windows.') ?></p>
            <div style="background: rgba(255,255,255,0.05); padding: 15px; border-radius: 8px; margin-bottom: 25px; border: 1px solid rgba(255,255,255,0.1);">
                <h3 style="margin-top:0;">⚙️ <?= __('local_supervisor_account', 'Compte local de supervision') ?></h3>
                <div style="display:flex; align-items:center; gap: 15px;">
                    <label for="inputLocalSupervisorUser"><strong><?= __('local_system_user_label', 'Utilisateur système local (Source SSH) :') ?></strong></label>
                    <input type="text" id="inputLocalSupervisorUser" class="table-input" style="width:200px;" value="<?= htmlspecialchars($localSupervisorUser) ?>" placeholder="ex: user ou root">
                </div>
            </div>
            <h3>🐧 <?= __('ssh_accounts_title', 'Comptes SSH (Ubuntu / Linux)') ?></h3>
            <table class="table-servers-config" id="tableSshAccounts">
                <thead><tr><th style="width:45%;"><?= __('col_friendly_name', 'Nom convivial') ?></th><th style="width:45%;"><?= __('col_remote_user', 'Utilisateur distant') ?></th><th style="width:10%; text-align:center;"><?= __('col_action', 'Action') ?></th></tr></thead>
                <tbody>
                    <?php foreach ($sshAccounts as $ssh): ?>
                    <tr data-ssh-id="<?= htmlspecialchars($ssh['id'] ?? '') ?>">
                        <td><input type="text" class="table-input ssh-name" value="<?= htmlspecialchars($ssh['name'] ?? '') ?>" placeholder="ex: Compte User"></td>
                        <td><input type="text" class="table-input ssh-user" value="<?= htmlspecialchars($ssh['user'] ?? '') ?>" placeholder="ex: root"></td>
                        <td style="text-align:center;"><button type="button" class="btn-trash" title="<?= __('action_delete', 'Supprimer') ?>" onclick="removeAccountRow(this)">🗑️</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="button" class="btn btn-blue" style="margin-top:10px;" onclick="addSshRow()">+ <?= __('btn_add_ssh_account', 'Ajouter un compte SSH') ?></button>
            <hr style="border:0; border-top:1px solid rgba(255,255,255,0.1); margin:30px 0;">
            <h3>🪟 <?= __('winrm_accounts_title', 'Comptes Windows / WinRM') ?></h3>
            <table class="table-servers-config" id="tableWinrmAccounts">
                <thead><tr><th style="width:25%;"><?= __('col_friendly_name', 'Nom convivial') ?></th><th style="width:20%;"><?= __('col_domain', 'Domaine (Optionnel)') ?></th><th style="width:25%;"><?= __('col_user', 'Utilisateur') ?></th><th style="width:20%;"><?= __('col_password', 'Mot de passe') ?></th><th style="width:10%; text-align:center;"><?= __('col_action', 'Action') ?></th></tr></thead>
                <tbody>
                    <?php foreach ($winrmAccounts as $win): ?>
                    <tr data-win-id="<?= htmlspecialchars($win['id'] ?? '') ?>" data-enc-password="<?= htmlspecialchars($win['enc_password'] ?? '') ?>">
                        <td><input type="text" class="table-input win-name" value="<?= htmlspecialchars($win['name'] ?? '') ?>" placeholder="ex: Admin Domaine"></td>
                        <td><input type="text" class="table-input win-domain" value="<?= htmlspecialchars($win['domain'] ?? '') ?>" placeholder="ex: root"></td>
                        <td><input type="text" class="table-input win-user" value="<?= htmlspecialchars($win['user'] ?? '') ?>" placeholder="ex: admintask"></td>
                        <td><input type="password" class="table-input win-pwd" placeholder="•••••••• (<?= __('pwd_unchanged', 'inchangé') ?>)"></td>
                        <td style="text-align:center;"><button type="button" class="btn-trash" title="<?= __('action_delete', 'Supprimer') ?>" onclick="removeAccountRow(this)">🗑️</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="button" class="btn btn-blue" style="margin-top:10px;" onclick="addWinrmRow()">+ <?= __('btn_add_win_account', 'Ajouter un compte Windows') ?></button>
        </div>
    </div>
    <div id="view-serveurs" class="view-section param-view" style="<?= $activeTab === 'serveurs' ? '' : 'display:none;' ?>">
        <div class="stat-card">
            <div class="section-header-row">
                <h1 style="margin:0;"><?= __('tab_servers', 'Serveurs') ?></h1>
                <div class="section-header-actions">
                    <button type="button" id="btnCancelServers" class="btn btn-gray" onclick="location.reload();"><?= __('btn_cancel', 'Annuler') ?></button>
                    <button type="button" id="btnSaveServers" class="btn btn-red"><?= __('btn_save', 'Enregistrer') ?></button>
                </div>
            </div>
            <p><?= __('servers_desc', 'Configuration et gestion des serveurs supervisés.') ?></p>
            <table class="table-servers-config" id="tableServers">
                <thead><tr><th style="width:20%;"><?= __('col_server_name', 'Nom du serveur') ?></th><th style="width:16%;"><?= __('col_ip_address', 'Adresse IP') ?></th><th style="width:12%;"><?= __('col_type', 'Type') ?></th><th style="width:20%;"><?= __('col_user', 'Utilisateur') ?></th><th style="width:12%;"><?= __('col_access', 'Accès') ?></th><th style="width:8%; text-align:center;"><?= __('col_test', 'Test') ?></th><th style="width:6%; text-align:center;"><?= __('col_order', 'Ordre') ?></th><th style="width:6%; text-align:center;"><?= __('col_delete', 'Suppr') ?></th></tr></thead>
                <tbody>
		    <?php foreach ($servers as $s): $isLinux = strtolower($s['type'] ?? $s['os'] ?? '') === 'linux'; ?>
                    <tr class="server-row" data-original-name="<?= htmlspecialchars($s['name'] ?? '') ?>" data-enc-password="<?= htmlspecialchars($s['enc_password'] ?? '') ?>">
                        <td><input type="text" class="input-srv-name" value="<?= htmlspecialchars($s['name'] ?? '') ?>" placeholder="ex: BSSRV01"></td>
                        <td><input type="text" class="input-srv-ip" value="<?= htmlspecialchars($s['ip'] ?? '') ?>" placeholder="ex: 10.101.0.31"></td>
                        <td><select class="server-type-select input-srv-type"><option value="windows" <?= !$isLinux ? 'selected' : '' ?>>Windows</option><option value="linux" <?= $isLinux ? 'selected' : '' ?>>Linux</option></select></td>
                        <td><input type="text" class="input-srv-user" value="<?= htmlspecialchars($s['user'] ?? '') ?>" placeholder="ex: domain\admin"></td>
                        <td class="col-action"><?= $isLinux ? '<button type="button" class="btn btn-sm btn-blue btn-ssh-key">'.__('btn_send_key', 'Envoyer la clé').'</button>' : '<button type="button" class="btn btn-sm btn-blue btn-win-pwd">🔑 '.__('btn_pwd', 'MDP').'</button>' ?></td>
                        <td style="text-align:center;"><button type="button" class="btn btn-sm btn-blue btn-test-conn"><?= __('btn_test', 'Tester') ?></button></td>
                        <td style="text-align:center; white-space:nowrap;"><button type="button" class="btn-order btn-move-up" title="<?= __('action_move_up', 'Monter') ?>">▲</button><button type="button" class="btn-order btn-move-down" title="<?= __('action_move_down', 'Descendre') ?>">▼</button></td>
                        <td style="text-align:center;"><button type="button" class="btn-trash btn-delete-srv" title="<?= __('action_delete', 'Supprimer') ?>">🗑️</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="button" id="btnAddServer" class="btn btn-blue" style="margin-top:15px;">+ <?= __('btn_add_server', 'Ajouter un serveur') ?></button>
        </div>
    </div>
    <div id="view-wol" class="view-section param-view" style="<?= $activeTab === 'wol' ? '' : 'display:none;' ?>">
        <div class="stat-card">
            <div class="section-header-row">
                <h1 style="margin:0;"><?= __('wol_relays_title', 'Relais Wake-on-LAN') ?></h1>
                <div class="section-header-actions">
                    <button type="button" class="btn btn-blue" id="btnOpenAddWolModal">➕ <?= __('btn_add_relay', 'Ajouter un relais') ?></button>
                </div>
            </div>
            <p><?= __('wol_relays_desc', 'Définissez les passerelles / machines Linux locales servant de relais Wake-on-LAN pour réveiller les postes situés sur des sous-réseaux distants.') ?></p>
            <table class="table-servers-config" id="tableWolRelays">
                <thead>
                    <tr>
                        <th style="width:20%;"><?= __('col_relay_name_site', 'Nom du relais / Site') ?></th>
                        <th style="width:20%;"><?= __('col_gateway_ip', 'IP de la passerelle') ?></th>
                        <th style="width:30%;"><?= __('col_subnets', 'Sous-réseaux desservis (CIDR)') ?></th>
                        <th style="width:15%;"><?= __('col_remote_user', 'Utilisateur distant') ?></th>
                        <th style="width:15%; text-align:center;"><?= __('col_action', 'Action') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($wolRelays)): ?>
                        <tr id="noWolRow"><td colspan="5" style="text-align:center; color:#888;"><?= __('no_wol_relay', 'Aucun relais configuré (les paquets sont envoyés depuis le serveur local).') ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($wolRelays as $idx => $relay): ?>
                            <tr data-alias="<?= htmlspecialchars($relay['alias'] ?? '') ?>">
                                <td><strong><?= htmlspecialchars($relay['name'] ?? '') ?></strong></td>
                                <td><?= htmlspecialchars($relay['ip'] ?? '') ?></td>
				<td><code><?= htmlspecialchars(implode(', ', $relay['subnets'] ?? [])) ?></code></td>
				<td><?= htmlspecialchars($relay['remote_user'] ?? '') ?></td>
                                <td style="text-align:center;">
                                    <button type="button" class="btn btn-red btn-sm" onclick="deleteWolRelay('<?= htmlspecialchars($relay['alias'] ?? '') ?>', '<?= htmlspecialchars($relay['name'] ?? '') ?>')">🗑️</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <!-- VUE GESTION ORDINATEURS -->
    <div id="view-pcs" class="view-section param-view" style="<?= $activeTab === 'pcs' ? '' : 'display:none;' ?>">
        <div class="stat-card">
            <div class="section-header-row">
                <h1 style="margin:0;"><?= __('tab_computers', 'Ordinateurs') ?></h1>
                <div class="section-header-actions">
                    <button type="button" class="btn btn-gray" onclick="location.reload();"><?= __('btn_cancel', 'Annuler') ?></button>
                    <button type="button" id="btnSavePcs" class="btn btn-red" onclick="savePcs()">💾 <?= __('btn_save', 'Enregistrer') ?></button>
                </div>
            </div>
            <p><?= __('computers_desc', 'Ajoutez, modifiez ou supprimez les ordinateurs.') ?></p>

            <table class="table-servers-config" id="tablePcs">
                <thead>
                    <tr>
                        <th style="width: 20%;"><?= __('col_computer', 'Ordinateur') ?></th>
                        <th style="width: 20%;"><?= __('col_user', 'Utilisateur') ?></th>
                        <th style="width: 15%;"><?= __('col_site', 'Site') ?></th>
                        <th style="width: 20%;"><?= __('col_details', 'Détails') ?></th>
                        <th style="width: 20%;"><?= __('col_mac', 'Adresse MAC') ?></th>
                        <th style="width: 5%; text-align: center;"><?= __('col_action', 'Action') ?></th>
                    </tr>
		</thead>
<tbody id="pcs-tbody">
    <?php if (!empty($pcsList)): ?>
        <?php foreach ($pcsList as $computerName => $pc): ?>
            <tr class="pc-row">
                <td>
                    <input type="text" class="table-input pc-input-computer" value="<?= htmlspecialchars((string)$computerName) ?>" placeholder="ex: BELAP275">
                </td>
                <td>
                    <input type="text" class="table-input pc-input-user" value="<?= htmlspecialchars($pc['user'] ?? '') ?>" placeholder="ex: Reserve">
                </td>
                <td>
                    <input type="text" class="table-input pc-input-site" value="<?= htmlspecialchars($pc['site'] ?? '') ?>" placeholder="ex: Siege">
                </td>
                <td>
                    <input type="text" class="table-input pc-input-details" value="<?= htmlspecialchars($pc['detail'] ?? '') ?>" placeholder="ex: Bureau">
                </td>
                <td>
                    <input type="text" class="table-input pc-input-mac" value="<?= htmlspecialchars($pc['mac'] ?? '') ?>" placeholder="ex: a8:2b:dd:61:67:d1">
                </td>
                <td style="text-align: center;">
                    <button type="button" class="btn-trash" title="Supprimer" onclick="removeAccountRow(this)">🗑️</button>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
</tbody>
	    </table>
	    <button type="button" class="btn btn-blue" onclick="addPcRow()">➕ <?= __('btn_add_pc', 'Ajouter un ordinateur') ?></button>
        </div>
    </div>
    <div id="view-mail" class="view-section param-view <?= $activeTab === 'mail' ? '' : 'is-hidden' ?>">
        <div class="stat-card">
            <div class="section-header-row">
                <h1 class="mail-header-title"><?= __('mail_config_title', "Configuration de l'expéditeur et des e-mails") ?></h1>
                <div class="section-header-actions">
                    <button type="button" class="btn btn-primary" id="btnSaveMailConfig">
                        <i class="fa fa-save"></i> <?= __('save', 'Enregistrer') ?>
                    </button>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="mail_from_email"><?= __('sender_email', "Adresse e-mail de l'expéditeur") ?> :</label>
                    <input type="email" id="mail_from_email" name="mail_from_email" class="form-control"
                           value="<?= htmlspecialchars($mail_config['from_email'] ?? $mail_config['sender_email'] ?? '') ?>"
                           placeholder="ex: supervision@domaine.fr" required>
                </div>
                <div class="form-group col-md-6">
                    <label for="mail_from_name"><?= __('sender_name', "Nom affiché de l'expéditeur") ?> :</label>
                    <input type="text" id="mail_from_name" name="mail_from_name" class="form-control"
                           value="<?= htmlspecialchars($mail_config['from_name'] ?? $mail_config['sender_name'] ?? 'NeoVision') ?>"
                           placeholder="ex: NeoVision Supervision">
                </div>
            </div>

            <div class="form-group">
                <label for="mail_method"><?= __('mail_method', "Méthode d'envoi") ?> :</label>
                <select id="mail_method" name="mail_method" class="form-control">
                    <option value="mail" <?= ($mail_config['method'] ?? 'mail') === 'mail' ? 'selected' : '' ?>><?= __('mail_method_php', 'Fonction PHP mail() standard') ?></option>
                    <option value="smtp" <?= ($mail_config['method'] ?? '') === 'smtp' ? 'selected' : '' ?>><?= __('mail_method_smtp', 'Serveur SMTP personnalisé') ?></option>
                    <option value="office365" <?= ($mail_config['method'] ?? '') === 'office365' ? 'selected' : '' ?>><?= __('mail_method_o365', 'Microsoft 365 / Office 365 (OAuth2 Graph)') ?></option>
                </select>
            </div>

            <div id="section_smtp" class="<?= ($mail_config['method'] ?? '') === 'smtp' ? '' : 'is-hidden' ?>">
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="mail_smtp_host"><?= __('smtp_host', 'Hôte SMTP') ?> :</label>
                        <input type="text" id="mail_smtp_host" name="mail_smtp_host" class="form-control"
                               value="<?= htmlspecialchars($mail_config['smtp_host'] ?? '') ?>" placeholder="ex: smtp.office365.com">
                    </div>
                    <div class="form-group col-md-3">
                        <label for="mail_smtp_port"><?= __('smtp_port', 'Port SMTP') ?> :</label>
                        <input type="number" id="mail_smtp_port" name="mail_smtp_port" class="form-control"
                               value="<?= htmlspecialchars($mail_config['smtp_port'] ?? '587') ?>" placeholder="587">
                    </div>
                    <div class="form-group col-md-3">
                        <label for="mail_smtp_secure"><?= __('smtp_security', 'Sécurité') ?> :</label>
                        <select id="mail_smtp_secure" name="mail_smtp_secure" class="form-control">
                            <option value="tls" <?= ($mail_config['smtp_secure'] ?? $mail_config['smtp_security'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS (STARTTLS / 587)</option>
                            <option value="ssl" <?= ($mail_config['smtp_secure'] ?? $mail_config['smtp_security'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL (Port 465)</option>
                            <option value="none" <?= ($mail_config['smtp_secure'] ?? $mail_config['smtp_security'] ?? '') === 'none' ? 'selected' : '' ?>><?= __('smtp_sec_none', 'Aucune / Port 25') ?></option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="mail_smtp_user"><?= __('smtp_user', 'Utilisateur SMTP / Compte') ?> :</label>
                        <input type="text" id="mail_smtp_user" name="mail_smtp_user" class="form-control"
                               value="<?= htmlspecialchars($mail_config['smtp_user'] ?? '') ?>" placeholder="ex: user@domaine.fr">
                    </div>
                    <div class="form-group col-md-6">
                        <label for="mail_smtp_pass"><?= __('smtp_pass', 'Mot de passe SMTP / App Password') ?> :</label>
                        <input type="password" id="mail_smtp_pass" name="mail_smtp_pass" class="form-control"
                               value="<?= htmlspecialchars($mail_config['smtp_pass'] ?? '') ?>" placeholder="Mot de passe">
                    </div>
                </div>
            </div>

            <div id="section_office365" class="<?= ($mail_config['method'] ?? '') === 'office365' ? '' : 'is-hidden' ?>">
                <div class="alert alert-info">
                    <i class="fa fa-info-circle"></i> <?= __('o365_notice', "L'envoi direct via Microsoft 365 utilisera l'adresse expéditeur ci-dessus avec les connecteurs configurés de votre environnement.") ?>
                </div>
            </div>

            <hr class="mail-divider">

            <div>
                <label for="mail_test_recipient"><strong><i class="fa fa-paper-plane"></i> <?= __('test_mail_title', "Tester la configuration d'envoi") ?> :</strong></label>
                <div class="form-row mail-test-box">
                    <div class="form-group col-md-8 mail-test-group">
                        <input type="email" id="mail_test_recipient" class="form-control" placeholder="<?= __('test_mail_placeholder', 'Entrez une adresse e-mail pour le test (ex: votre-email@domaine.fr)') ?>">
                    </div>
                    <div class="form-group col-md-4 mail-test-group">
                        <button type="button" class="btn btn-secondary btn-block" id="btnTestMail">
                            <i class="fa fa-paper-plane"></i> <?= __('test_send_btn', "Envoyer un e-mail de test") ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div id="view-langue" class="view-section param-view" style="<?= $activeTab === 'langue' ? '' : 'display:none;' ?>">
        <div class="stat-card">
            <h1><?= __('lang_settings_title', 'Langue') ?></h1>
            <p><?= __('lang_settings_desc', "Choisir la langue de l'interface.") ?></p>

            <div class="lang-selector-vertical">
                <a href="?lang=de" class="btn btn-lang <?= ($_currentLang==='de')?'btn-blue':'btn-gray' ?>">🇩🇪 Deutsch</a>
                <a href="?lang=en" class="btn btn-lang <?= ($_currentLang==='en')?'btn-blue':'btn-gray' ?>">🇬🇧 English</a>
                <a href="?lang=es" class="btn btn-lang <?= ($_currentLang==='es')?'btn-blue':'btn-gray' ?>">🇪🇸 Español</a>
                <a href="?lang=fr" class="btn btn-lang <?= ($_currentLang==='fr')?'btn-blue':'btn-gray' ?>">🇫🇷 Français</a>
                <a href="?lang=it" class="btn btn-lang <?= ($_currentLang==='it')?'btn-blue':'btn-gray' ?>">🇮🇹 Italiano</a>
                <a href="?lang=nl" class="btn btn-lang <?= ($_currentLang==='nl')?'btn-blue':'btn-gray' ?>">🇳🇱 Nederlands</a>
                <a href="?lang=pt" class="btn btn-lang <?= ($_currentLang==='pt')?'btn-blue':'btn-gray' ?>">🇵🇹 Português</a>
            </div>
        </div>
    </div>
</div>
<div id="wolModal" class="ssh-modal-backdrop" style="display:none;">
    <div class="ssh-terminal-box ssh-modal-wide">
        <div class="ssh-terminal-header">
            <span>➕ <?= __('modal_new_wol_relay', 'Nouveau Relais Wake-on-LAN') ?></span>
            <span id="btnCloseWolModalX" style="cursor:pointer; font-size:16px;">✖</span>
        </div>
        <div class="ssh-terminal-body">
            <div class="ssh-modal-columns">
                <div class="ssh-col-form">
                    <div class="ssh-form-group">
                        <label><?= __('label_relay_name_site', 'Nom du relais / Site :') ?></label>
                        <input type="text" id="wolInputName" placeholder="ex: Relais Charleroi">
                    </div>
                    <div class="ssh-form-group">
                        <label><?= __('label_remote_linux_ip', 'IP de la machine Linux distante :') ?></label>
                        <input type="text" id="wolInputIp" placeholder="ex: 10.102.0.50">
                    </div>
                    <div class="ssh-form-group">
                        <label><?= __('label_subnets_served', 'Sous-réseaux desservis (séparés par virgule) :') ?></label>
                        <input type="text" id="wolInputSubnets" placeholder="ex: 10.102.0.0/24, 10.102.10.0/24">
                    </div>
                    <div class="ssh-form-group">
                        <label><?= __('label_remote_linux_user', 'Compte Linux distant :') ?></label>
                        <input type="text" id="wolInputUser" value="root" placeholder="ex: user ou root">
                    </div>
                    <div class="ssh-form-group">
                        <label><?= __('label_remote_sudo_pass', 'Mot de passe sudo distant :') ?></label>
                        <input type="password" id="wolInputPassword" placeholder="<?= __('placeholder_sudo_pass', 'Nécessaire pour injecter la clé et wakeonlan') ?>">
                    </div>
                </div>
                <div class="ssh-col-console">
                    <label class="ssh-col-title" style="font-weight:600; margin-bottom:8px; display:block;"><?= __('console_logs_title', 'Sortie console / Logs :') ?></label>
                    <div class="ssh-console-output" id="wolConsole"><?= __('wol_console_ready', "Prêt. Remplissez les informations et cliquez sur 'Déployer le relais'.") ?></div>
                </div>
            </div>
            <div class="ssh-terminal-actions actions-left">
                <button type="button" class="btn btn-green" id="btnDeployWol">🚀 <?= __('btn_deploy_relay', 'Déployer le relais') ?></button>
                <button type="button" id="btnCloseWolModal" class="btn btn-gray"><?= __('btn_close', 'Fermer') ?></button>
            </div>
        </div>
    </div>
</div>
<div id="sshModal" class="ssh-modal-backdrop" style="display:none;">
    <div class="ssh-terminal-box">
        <div class="ssh-terminal-header"><span>🐧 <?= __('modal_ssh_deploy_title', 'Déploiement Clé SSH :') ?> <strong id="sshModalTarget"></strong></span><span id="btnHeaderCloseSsh" style="cursor:pointer;">✖</span></div>
        <div class="ssh-terminal-body">
            <div class="ssh-form-group"><label><?= __('label_target_ip', 'Adresse IP cible :') ?></label><input type="text" id="sshInputIp" readonly></div>
            <div class="ssh-form-group"><label><?= __('label_local_source_user', 'Compte local source :') ?></label><input type="text" id="sshInputLocalUser" value="<?= htmlspecialchars($localSupervisorUser) ?>" placeholder="ex: user ou root"></div>
            <div class="ssh-form-group"><label><?= __('label_saved_ssh_profile', 'Profil SSH préenregistré :') ?></label><select id="sshProfileSelect" style="width:100%; padding:8px; border-radius:4px; border:1px solid rgba(255,255,255,0.2); background:#1e222d; color:#fff;"><option value="">-- <?= __('select_manual_entry', 'Saisie manuelle') ?> --</option><?php foreach ($sshAccounts as $sa): ?><option value="<?= htmlspecialchars($sa['user']) ?>"><?= htmlspecialchars($sa['name']) ?> (<?= htmlspecialchars($sa['user']) ?>)</option><?php endforeach; ?></select></div>
            <div class="ssh-form-group"><label><?= __('label_remote_user', 'Utilisateur distant :') ?></label><input type="text" id="sshInputUser" placeholder="ex: user ou root"></div>
            <div class="ssh-form-group"><label><?= __('label_remote_ssh_pass', 'Mot de passe distant :') ?></label><input type="password" id="sshInputPassword" placeholder="<?= __('placeholder_ssh_pass', 'Mot de passe de connexion SSH') ?>"></div>
            <div class="ssh-terminal-actions"><button type="button" id="btnCloseSsh" class="btn btn-gray"><?= __('btn_close', 'Fermer') ?></button><button type="button" id="btnRunSsh" class="btn btn-green">🚀 <?= __('btn_deploy_key', 'Déployer la clé') ?></button></div>
            <div class="ssh-console-output" id="sshConsole"><?= __('msg_waiting', 'En attente...') ?></div>
        </div>
    </div>
</div>
<div id="winModal" class="ssh-modal-backdrop" style="display:none;">
    <div class="ssh-terminal-box">
        <div class="ssh-terminal-header"><span>🪟 <?= __('modal_win_account_title', 'Associer un compte Windows :') ?> <strong id="winModalTarget"></strong></span><span id="btnHeaderCloseWin" style="cursor:pointer;">✖</span></div>
        <div class="ssh-terminal-body">
            <div class="ssh-form-group"><label><?= __('label_choose_existing_account', 'Choisir un compte existant :') ?></label><select id="winAccountSelect" style="width:100%; padding:8px; border-radius:4px; border:1px solid rgba(255,255,255,0.2); background:#1e222d; color:#fff;"><option value="">-- <?= __('select_manual_entry', 'Saisie manuelle') ?> --</option><?php foreach ($winrmAccounts as $wa): $fullUser = ($wa['domain'] ? $wa['domain'].'\\' : '') . $wa['user']; ?><option value="<?= htmlspecialchars($wa['id']) ?>" data-user="<?= htmlspecialchars($fullUser) ?>" data-enc="<?= htmlspecialchars($wa['enc_password'] ?? '') ?>"><?= htmlspecialchars($wa['name']) ?> (<?= htmlspecialchars($fullUser) ?>)</option><?php endforeach; ?></select></div>
            <div class="ssh-form-group"><label><?= __('label_win_user', 'Utilisateur (ex: DOMAINE\admin ou admin) :') ?></label><input type="text" id="winInputUser"></div>
            <div class="ssh-form-group"><label><?= __('col_password', 'Mot de passe :') ?></label><input type="password" id="winInputPassword" placeholder="<?= __('placeholder_leave_empty', 'Laisser vide si inchangé') ?>"></div>
            <div class="ssh-terminal-actions"><button type="button" id="btnCloseWin" class="btn btn-gray"><?= __('btn_close', 'Fermer') ?></button><button type="button" id="btnApplyWin" class="btn btn-green"><?= __('btn_apply', 'Appliquer') ?></button></div>
        </div>
    </div>
</div>
<div id="deleteModal" class="modal-backdrop">
    <div class="modal-box">
        <h2 style="margin-top:0; color:#e74c3c;"><?= __('modal_delete_title', 'Confirmer la suppression') ?></h2>
        <p id="deleteModalText"></p>
        <div class="modal-actions"><button type="button" id="btnCancelDelete" class="btn btn-gray"><?= __('btn_cancel', 'Annuler') ?></button><button type="button" id="btnConfirmDelete" class="btn btn-red"><?= __('btn_delete', 'Supprimer') ?></button></div>
    </div>
</div>
<button id="backToTop" title="<?= __('btn_scroll_bottom', 'Descendre en bas') ?>"><span>↓</span></button>
<script src="/assets/darkmode.js"></script>
<script src="/assets/js/param.js"></script>
</body>
</html>
