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
$servers = file_exists($serversJsonPath) ? (json_decode(file_get_contents($serversJsonPath), true) ?? []) : [];
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
                    <?php foreach ($servers as $s): $isLinux = strtolower($s['type'] ?? '') === 'linux'; ?>
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
    <div id="view-mail" class="view-section param-view" style="<?= $activeTab === 'mail' ? '' : 'display:none;' ?>">
        <div class="stat-card">
            <div class="section-header-row">
                <h1 style="margin:0;"><?= __('mail_config_title', "Configuration de l'expéditeur et des e-mails") ?></h1>
                <div class="section-header-actions">
                    <button type="submit" form="form_mail" class="btn btn-primary" style="margin:0;">
                        <i class="fa fa-save"></i> <?= __('save_mail_config', "Enregistrer la configuration") ?>
                    </button>
                </div>
            </div>
            <form id="form_mail" method="POST" action="ajax_notifications.php?action=save_mail_config">
                <input type="hidden" name="active_tab" value="mail">

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="mail_sender_email"><?= __('sender_email', "Adresse e-mail de l'expéditeur") ?> :</label>
                        <input type="email" id="mail_sender_email" name="mail_sender_email" class="form-control"
                               value="<?= htmlspecialchars($mail_config['sender_email'] ?? '') ?>"
                               placeholder="ex: supervision@domaine.fr" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="mail_sender_name"><?= __('sender_name', "Nom affiché de l'expéditeur") ?> :</label>
                        <input type="text" id="mail_sender_name" name="mail_sender_name" class="form-control"
                               value="<?= htmlspecialchars($mail_config['sender_name'] ?? '') ?>"
                               placeholder="ex: NeoVision Supervision">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-12">
                        <label for="mail_method"><?= __('mail_method', "Méthode d'envoi") ?> :</label>
                        <select id="mail_method" name="mail_method" class="form-control" onchange="toggleMailMethodFields(this.value)">
                            <option value="smtp" <?= ($mail_config['method'] ?? '') === 'smtp' ? 'selected' : '' ?>>
                                <?= __('mail_method_smtp', "SMTP Authentifié / Relais standard") ?>
                            </option>
                            <option value="office365_graph" <?= ($mail_config['method'] ?? '') === 'office365_graph' ? 'selected' : '' ?>>
                                <?= __('mail_method_o365', "Microsoft 365 (Graph API OAuth2 - Recommandé)") ?>
                            </option>
                            <option value="sendmail" <?= ($mail_config['method'] ?? 'sendmail') === 'sendmail' ? 'selected' : '' ?>>
                                <?= __('mail_method_sendmail', "Sendmail local (Linux /usr/sbin/sendmail)") ?>
                            </option>
                        </select>
                    </div>
                </div>
                <div id="section_smtp" style="display: <?= ($mail_config['method'] ?? '') === 'smtp' ? 'block' : 'none' ?>;">
                    <h3 style="border-bottom: 1px solid var(--border-color); padding-bottom: 5px; margin-top: 15px;"><?= __('smtp_parameters', "Paramètres SMTP") ?></h3>
                    <div class="form-row">
                        <div class="form-group col-md-8">
                            <label for="smtp_host"><?= __('smtp_host', "Serveur SMTP") ?> :</label>
                            <input type="text" id="smtp_host" name="smtp_host" class="form-control"
                                   value="<?= htmlspecialchars($mail_config['smtp']['host'] ?? '') ?>"
                                   placeholder="ex: smtp.office365.com ou smtp.gmail.com">
                        </div>
                        <div class="form-group col-md-4">
                            <label for="smtp_port"><?= __('smtp_port', "Port") ?> :</label>
                            <input type="number" id="smtp_port" name="smtp_port" class="form-control"
                                   value="<?= htmlspecialchars($mail_config['smtp']['port'] ?? '587') ?>"
                                   placeholder="587, 465 ou 25">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label for="smtp_security"><?= __('smtp_encryption', "Chiffrement") ?> :</label>
                            <select id="smtp_security" name="smtp_security" class="form-control">
                                <option value="tls" <?= ($mail_config['smtp']['security'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS / TLS (Port 587)</option>
                                <option value="ssl" <?= ($mail_config['smtp']['security'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL / TLS Implicite (Port 465)</option>
                                <option value="none" <?= ($mail_config['smtp']['security'] ?? '') === 'none' ? 'selected' : '' ?>><?= __('none_clear', "Aucun (Texte brut - Déconseillé)") ?></option>
                            </select>
                        </div>
                        <div class="form-group col-md-4">
                            <label for="smtp_user"><?= __('smtp_username', "Nom d'utilisateur / Identifiant") ?> :</label>
                            <input type="text" id="smtp_user" name="smtp_user" class="form-control"
                                   value="<?= htmlspecialchars($mail_config['smtp']['user'] ?? '') ?>"
                                   placeholder="ex: moncompte@domaine.fr">
                        </div>
                        <div class="form-group col-md-4">
                            <label for="smtp_pass"><?= __('smtp_password', "Mot de passe SMTP / Clé API") ?> :</label>
                            <input type="password" id="smtp_pass" name="smtp_pass" class="form-control"
                                   value="<?= htmlspecialchars($mail_config['smtp']['pass'] ?? '') ?>"
                                   placeholder="••••••••••••">
                        </div>
                    </div>
                </div>
                <div id="section_office365" style="display: <?= ($mail_config['method'] ?? '') === 'office365_graph' ? 'block' : 'none' ?>;">
                    <h3 style="border-bottom: 1px solid var(--border-color); padding-bottom: 5px; margin-top: 15px;"><?= __('o365_api_parameters', "Paramètres Microsoft Graph API") ?></h3>
                    <div class="form-row">
                        <div class="form-group col-md-12">
                            <label for="o365_tenant_id"><?= __('tenant_id', "ID du Tenant (Locataire Azure)") ?> :</label>
                            <input type="text" id="o365_tenant_id" name="o365_tenant_id" class="form-control"
                                   value="<?= htmlspecialchars($mail_config['office365_graph']['tenant_id'] ?? '') ?>"
                                   placeholder="8a1b2c3d-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="o365_client_id"><?= __('client_id', "ID de l'Application (Client ID)") ?> :</label>
                            <input type="text" id="o365_client_id" name="o365_client_id" class="form-control"
                                   value="<?= htmlspecialchars($mail_config['office365_graph']['client_id'] ?? '') ?>"
                                   placeholder="12345678-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="o365_client_secret"><?= __('client_secret', "Secret de l'Application (Client Secret)") ?> :</label>
                            <input type="password" id="o365_client_secret" name="o365_client_secret" class="form-control"
                                   value="<?= htmlspecialchars($mail_config['office365_graph']['client_secret'] ?? '') ?>"
                                   placeholder="••••••••••••">
                        </div>
                    </div>
                </div>

                <div class="form-row" style="margin-top: 25px; border-top: 1px solid var(--border-color); padding-top: 15px;">
                    <div class="form-group col-md-8">
                        <label for="test_mail_recipient"><?= __('test_email_recipient', "Adresse de réception pour le test") ?> :</label>
                        <input type="email" id="test_mail_recipient" class="form-control" placeholder="admin@domaine.fr">
                    </div>
                    <div class="form-group col-md-4" style="display: flex; align-items: flex-end;">
                        <button type="button" class="btn btn-secondary" style="width: 100%; height: 38px;" onclick="testMailConnection()">
                            <i class="fa fa-paper-plane"></i> <?= __('btn_test_mail', "Tester l'envoi") ?>
                        </button>
                    </div>
                </div>
            </form>
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

<!-- MODALE WOL -->
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

<!-- MODALE SSH -->
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

<!-- MODALE WINRM -->
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

<!-- MODALE SUPPRESSION -->
<div id="deleteModal" class="modal-backdrop">
    <div class="modal-box">
        <h2 style="margin-top:0; color:#e74c3c;"><?= __('modal_delete_title', 'Confirmer la suppression') ?></h2>
        <p id="deleteModalText"></p>
        <div class="modal-actions"><button type="button" id="btnCancelDelete" class="btn btn-gray"><?= __('btn_cancel', 'Annuler') ?></button><button type="button" id="btnConfirmDelete" class="btn btn-red"><?= __('btn_delete', 'Supprimer') ?></button></div>
    </div>
</div>
<button id="backToTop" title="<?= __('btn_scroll_bottom', 'Descendre en bas') ?>"><span>↓</span></button>
<script src="/assets/darkmode.js"></script>
<script>
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
    };
});
const wolModal=document.getElementById('wolModal'),
      btnOpenAddWolModal=document.getElementById('btnOpenAddWolModal'),
      btnCloseWolModal=document.getElementById('btnCloseWolModal'),
      btnCloseWolModalX=document.getElementById('btnCloseWolModalX'),
      btnDeployWol=document.getElementById('btnDeployWol'),
      wolConsole=document.getElementById('wolConsole');

if(btnOpenAddWolModal){
    btnOpenAddWolModal.onclick=()=>{
        document.getElementById('wolInputName').value='';
        document.getElementById('wolInputIp').value='';
        document.getElementById('wolInputSubnets').value='';
        document.getElementById('wolInputPassword').value='';
        wolConsole.textContent=t('wol_console_ready', "Prêt. Remplissez les informations et cliquez sur 'Déployer le relais'.");
        btnDeployWol.disabled=false;
        wolModal.style.display='flex';
    };
}
if(btnCloseWolModal)btnCloseWolModal.onclick=()=>{wolModal.style.display='none';};
if(btnCloseWolModalX)btnCloseWolModalX.onclick=()=>{wolModal.style.display='none';};
if(btnDeployWol){
    btnDeployWol.onclick=async()=>{
        const name=document.getElementById('wolInputName').value.trim(),
              ip=document.getElementById('wolInputIp').value.trim(),
              subnets=document.getElementById('wolInputSubnets').value.trim(),
              remote_user=document.getElementById('wolInputUser').value.trim(),
              password=document.getElementById('wolInputPassword').value;
        if(!ip||!remote_user||!password){
            alert(t('alert_fill_wol_fields', "Veuillez remplir l'IP, le compte et le mot de passe distant."));
            return;
        }
        btnDeployWol.disabled=true;
        wolConsole.textContent="[1/3] " + t('log_ssh_deploy_start', "Connexion SSH et déploiement de la clé...") + "\n";
        try{
            const res=await fetch('deploy_wol_relay.php',{
                method:'POST',
                headers:{'Content-Type':'application/json'},
                body:JSON.stringify({name,ip,subnets,remote_user,password,local_user:'<?= htmlspecialchars($localSupervisorUser) ?>'})
            });
            const data=await res.json();
            if(data.success){
                wolConsole.textContent+="[✓] "+(data.message||t('msg_success', "Succès !"))+"\n";
                setTimeout(()=>{location.reload();},1500);
            }else{
                wolConsole.textContent+="[✗] " + t('msg_error', "ERREUR : ") + (data.error||t('msg_failed', "Échec"))+"\n";
                btnDeployWol.disabled=false;
            }
        }catch(e){
            wolConsole.textContent+="[✗] " + t('msg_network_error', "Erreur de communication avec le serveur.") + "\n";
            btnDeployWol.disabled=false;
        }
    };
}

function addPcRow() {
    const tbody = document.getElementById('pcs-tbody');
    const tr = document.createElement('tr');
    tr.className = 'pc-row';
    tr.innerHTML = `
        <td><input type="text" class="table-input pc-input-computer" placeholder="ex: BELAP275"></td>
        <td><input type="text" class="table-input pc-input-user" placeholder="ex: Reserve"></td>
        <td><input type="text" class="table-input pc-input-site" placeholder="ex: Siege"></td>
        <td><input type="text" class="table-input pc-input-details" placeholder="ex: Bureau"></td>
        <td><input type="text" class="table-input pc-input-mac" placeholder="ex: a8:2b:dd:61:67:d1"></td>
        <td style="text-align: center;">
            <button type="button" class="btn-trash" title="Supprimer" onclick="removeAccountRow(this)">🗑️</button>
        </td>
    `;
    tbody.appendChild(tr);
    tr.querySelector('.pc-input-computer').focus();
}

function savePcs() {
    const rows = document.querySelectorAll('#pcs-tbody .pc-row');
    const pcs = [];

    rows.forEach(row => {
        const computer = row.querySelector('.pc-input-computer').value.trim();
        const user = row.querySelector('.pc-input-user').value.trim();
        const site = row.querySelector('.pc-input-site').value.trim();
        const detail = row.querySelector('.pc-input-details').value.trim();
        const mac = row.querySelector('.pc-input-mac').value.trim();

        if (computer !== '') {
            pcs.push({ computer, user, site, detail, mac });
        }
    });

    const formData = new FormData();
    formData.append('action', 'save_pcs');
    formData.append('pcs_data', JSON.stringify(pcs));
    const btn = document.getElementById('btnSavePcs');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '⏳ <?= __("saving", "Enregistrement...") ?>';
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.error || 'Erreur lors de la sauvegarde.');
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    })
    .catch(err => {
        alert('Erreur réseau : ' + err.message);
        btn.disabled = false;
        btn.innerHTML = originalText;
    });
}

async function deleteWolRelay(alias,name){
    if(!confirm(t('confirm_delete_wol_relay', `Supprimer le relais WOL "${name}" ?`).replace('${name}', name))) return;
    try{
        const res=await fetch('delete_wol_relay.php',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({alias})
        });
        const data=await res.json();
        if(data.success){
            location.reload();
        }else{
            alert(t('msg_error', 'Erreur: ') + (data.error||t('msg_cannot_delete', 'Impossible de supprimer')));
        }
    }catch(e){
        alert(t('msg_network_error', 'Erreur réseau'));
    }
}

function removeAccountRow(btn){
    const row=btn.closest('tr');
    if(row)row.remove();
}

function addSshRow(){
    const tbody=document.querySelector('#tableSshAccounts tbody'),tr=document.createElement('tr');
    tr.dataset.sshId='ssh_'+Date.now();
    tr.innerHTML=`<td><input type="text" class="table-input ssh-name" placeholder="ex: Compte User"></td><td><input type="text" class="table-input ssh-user" placeholder="ex: root"></td><td style="text-align:center;"><button type="button" class="btn-trash" title="${t('action_delete', 'Supprimer')}" onclick="removeAccountRow(this)">🗑️</button></td>`;
    tbody.appendChild(tr);
}

function addWinrmRow(){
    const tbody=document.querySelector('#tableWinrmAccounts tbody'),tr=document.createElement('tr');
    tr.dataset.winId='win_'+Date.now();
    tr.dataset.encPassword='';
    tr.innerHTML=`<td><input type="text" class="table-input win-name" placeholder="ex: Admin Local"></td><td><input type="text" class="table-input win-domain" placeholder="ex: user ou laisser vide"></td><td><input type="text" class="table-input win-user" placeholder="ex: admin"></td><td><input type="password" class="table-input win-pwd" placeholder="${t('placeholder_type_password', 'Saisir mot de passe')}"></td><td style="text-align:center;"><button type="button" class="btn-trash" title="${t('action_delete', 'Supprimer')}" onclick="removeAccountRow(this)">🗑️</button></td>`;
    tbody.appendChild(tr);
}

function toggleMailMethodFields() {
    const method = document.getElementById('mail_method')?.value;
    const rows = document.querySelectorAll('.smtp-field');
    rows.forEach(r => r.style.display = (method === 'smtp') ? '' : 'none');
}

document.getElementById('btnSaveMailConfig')?.addEventListener('click', function() {
    const payload = new URLSearchParams({
        action: 'save_mail_config',
        from_name: document.getElementById('mail_from_name')?.value.trim() || '',
        from_email: document.getElementById('mail_from_email')?.value.trim() || '',
        method: document.getElementById('mail_method')?.value || 'mail',
        smtp_host: document.getElementById('mail_smtp_host')?.value.trim() || '',
        smtp_port: document.getElementById('mail_smtp_port')?.value.trim() || '25',
        smtp_secure: document.getElementById('mail_smtp_secure')?.value || 'none',
        smtp_user: document.getElementById('mail_smtp_user')?.value.trim() || '',
        smtp_pass: document.getElementById('mail_smtp_pass')?.value || ''
    });

    fetch('param.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: payload.toString()
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(t('mail_saved_success', 'Configuration e-mail enregistrée avec succès.'));
            location.reload();
        } else {
            alert(t('msg_error', 'Erreur : ') + (data.error || t('msg_unknown_error', 'Erreur inconnue')));
        }
    })
    .catch(err => alert(t('msg_network_error', 'Erreur réseau') + ' : ' + err.message));
});

function testMailConfig() {
    const recipient = document.getElementById('mail_test_recipient')?.value.trim();
    if (!recipient) {
        alert(t('mail_test_recipient_required', 'Veuillez saisir une adresse e-mail destinataire.'));
        return;
    }

    const btn = document.getElementById('btnTestMail');
    const origHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> ' + t('mail_testing', 'Envoi en cours...');
    }

    const payload = new URLSearchParams({
        action: 'test_mail',
        to: recipient
    });

    fetch('param.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: payload.toString()
    })
    .then(r => r.json())
    .then(data => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
        if (data.success) {
            alert(t('mail_test_success', 'E-mail de test envoyé avec succès !'));
        } else {
            alert(t('msg_error', 'Erreur : ') + (data.error || t('msg_unknown_error', 'Erreur inconnue')));
        }
    })
    .catch(err => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
        alert(t('msg_network_error', 'Erreur réseau') + ' : ' + err.message);
    });
}

document.getElementById('btnTestMail')?.addEventListener('click', testMailConfig);

document.getElementById('btnSaveAccounts')?.addEventListener('click',function(){
    const localUser=document.getElementById('inputLocalSupervisorUser').value.trim(),
          sshAccounts=[],
          winrmAccounts=[];
    document.querySelectorAll('#tableSshAccounts tbody tr').forEach(row=>{
        const id=row.dataset.sshId||'',
              name=row.querySelector('.ssh-name').value.trim(),
              user=row.querySelector('.ssh-user').value.trim();
        if(user)sshAccounts.push({id,name,user});
    });
    document.querySelectorAll('#tableWinrmAccounts tbody tr').forEach(row=>{
        const id=row.dataset.winId||'',
              name=row.querySelector('.win-name').value.trim(),
              domain=row.querySelector('.win-domain').value.trim(),
              user=row.querySelector('.win-user').value.trim(),
              newPwd=row.querySelector('.win-pwd').value,
              encPwd=row.dataset.encPassword||'';
        if(user)winrmAccounts.push({id,name,domain,user,new_password:newPwd,enc_password:encPwd});
    });
    fetch('ajax_accounts.php',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({action:'save_all',local_user:localUser,ssh_accounts:sshAccounts,winrm_accounts:winrmAccounts})
    }).then(r=>r.json()).then(data=>{
        if(data.success){
            alert(t('alert_accounts_saved', 'Comptes enregistrés avec succès !'));
            location.reload();
        }else{
            alert(t('msg_error', 'Erreur : ') + (data.error||t('msg_cannot_save', "Impossible d'enregistrer")));
        }
    }).catch(err=>alert(t('msg_network_error', 'Erreur réseau.')));
});

document.getElementById('btnAddServer').onclick=()=>{
    const tbody=document.querySelector('#tableServers tbody'),tr=document.createElement('tr');
    tr.className='server-row';
    tr.dataset.originalName='';
    tr.dataset.encPassword='';
    tr.innerHTML=`<td><input type="text" class="input-srv-name" placeholder="ex: BSSRV03"></td><td><input type="text" class="input-srv-ip" placeholder="ex: 10.101.0.33"></td><td><select class="server-type-select input-srv-type"><option value="windows" selected>Windows</option><option value="linux">Linux</option></select></td><td><input type="text" class="input-srv-user" placeholder="ex: root\\\\admin"></td><td class="col-action"><button type="button" class="btn btn-sm btn-blue btn-win-pwd">🔑 ${t('btn_pwd', 'MDP')}</button></td><td style="text-align:center;"><button type="button" class="btn btn-sm btn-blue btn-test-conn">${t('btn_test', 'Tester')}</button></td><td style="text-align:center; white-space:nowrap;"><button type="button" class="btn-order btn-move-up" title="${t('action_move_up', 'Monter')}">▲</button><button type="button" class="btn-order btn-move-down" title="${t('action_move_down', 'Descendre')}">▼</button></td><td style="text-align:center;"><button type="button" class="btn-trash btn-delete-srv" title="${t('action_delete', 'Supprimer')}">🗑️</button></td>`;
    tbody.appendChild(tr);
};

document.addEventListener('change',e=>{
    if(e.target.classList.contains('server-type-select')){
        const row=e.target.closest('tr'),colAction=row.querySelector('.col-action');
        if(e.target.value==='linux'){
            colAction.innerHTML=`<button type="button" class="btn btn-sm btn-blue btn-ssh-key">${t('btn_send_key', 'Envoyer la clé')}</button>`;
        }else{
            colAction.innerHTML=`<button type="button" class="btn btn-sm btn-blue btn-win-pwd">🔑 ${t('btn_pwd', 'MDP')}</button>`;
        }
    }
});

document.addEventListener('click',e=>{
    const upBtn=e.target.closest('.btn-move-up'),downBtn=e.target.closest('.btn-move-down');
    if(upBtn){
        const row=upBtn.closest('tr'),prev=row.previousElementSibling;
        if(prev)row.parentNode.insertBefore(row,prev);
    }else if(downBtn){
        const row=downBtn.closest('tr'),next=row.nextElementSibling;
        if(next)row.parentNode.insertBefore(next,row);
    }
});

document.getElementById('btnSaveServers').onclick=async()=>{
    const rows=document.querySelectorAll('#tableServers tbody tr.server-row'),servers=[];
    rows.forEach(r=>{
        const name=r.querySelector('.input-srv-name').value.trim(),
              ip=r.querySelector('.input-srv-ip').value.trim(),
              type=r.querySelector('.input-srv-type').value,
              user=r.querySelector('.input-srv-user').value.trim(),
              encPassword=r.dataset.encPassword||null;
        if(name){
            const srv={name,ip,type,user};
            if(type==='windows'&&encPassword){srv.enc_password=encPassword;}
            servers.push(srv);
        }
    });
    try{
        const res=await fetch('save_servers.php',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({servers})
        });
        const data=await res.json();
        if(data.success){
            alert(t('alert_servers_saved', 'Configuration enregistrée avec succès !'));
            window.location.reload();
        }else{
            alert(t('msg_error', 'Erreur : ') + (data.error||t('msg_cannot_save', "Impossible d'enregistrer")));
        }
    }catch(err){
        alert(t('msg_network_error_save', "Erreur réseau lors de l'enregistrement"));
    }
};

let targetServerToDelete=null,targetRowToDelete=null;
const modal=document.getElementById("deleteModal"),modalText=document.getElementById("deleteModalText");

document.addEventListener('click',e=>{
    const btn=e.target.closest('.btn-delete-srv');
    if(btn){
        targetRowToDelete=btn.closest('tr');
        targetServerToDelete=targetRowToDelete.dataset.originalName||targetRowToDelete.querySelector('.input-srv-name').value.trim();
        if(!targetServerToDelete){targetRowToDelete.remove();return;}
        modalText.innerHTML=t('modal_delete_srv_confirm', "Êtes-vous sûr de vouloir supprimer le serveur <strong>{srv}</strong> ?<br><br>Cette action supprimera définitivement le serveur et l'historique associé (RAM, disques, statuts, services).").replace('{srv}', targetServerToDelete);
        modal.style.display='flex';
    }
});

document.getElementById("btnCancelDelete").onclick=()=>{modal.style.display='none';targetServerToDelete=null;targetRowToDelete=null;};

document.getElementById("btnConfirmDelete").onclick=async()=>{
    if(!targetServerToDelete)return;
    try{
        const res=await fetch('delete_server.php',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({name:targetServerToDelete})
        });
        const data=await res.json();
        if(data.success){
            if(targetRowToDelete)targetRowToDelete.remove();
            modal.style.display='none';
        }else{
            alert(t('msg_error', 'Erreur: ') + (data.error||t('msg_cannot_delete', 'Impossible de supprimer')));
        }
    }catch(err){
        alert(t('msg_network_error_delete', 'Erreur réseau lors de la suppression'));
    }
    modal.style.display='none';
};

const winModal=document.getElementById("winModal"),
      winAccountSelect=document.getElementById("winAccountSelect"),
      winUserInput=document.getElementById("winInputUser"),
      winPassInput=document.getElementById("winInputPassword"),
      winModalTarget=document.getElementById("winModalTarget");
let currentWinRow=null;

document.addEventListener('click',e=>{
    const btn=e.target.closest('.btn-win-pwd');
    if(btn){
        currentWinRow=btn.closest('tr');
        const name=currentWinRow.querySelector('.input-srv-name').value.trim(),
              user=currentWinRow.querySelector('.input-srv-user').value.trim();
        winModalTarget.textContent=name||t('label_win_server_default', 'Serveur Windows');
        winUserInput.value=user;
        winPassInput.value='';
        winAccountSelect.value='';
        Array.from(winAccountSelect.options).forEach(opt=>{
            if(opt.dataset.user===user)winAccountSelect.value=opt.value;
        });
        winModal.style.display='flex';
    }
});

winAccountSelect.onchange=()=>{
    const opt=winAccountSelect.selectedOptions[0];
    if(opt&&opt.value){
        winUserInput.value=opt.dataset.user;
        winPassInput.value='';
    }
};

const closeWinModal=()=>{winModal.style.display='none';currentWinRow=null;};
document.getElementById('btnCloseWin').onclick=closeWinModal;
document.getElementById('btnHeaderCloseWin').onclick=closeWinModal;

document.getElementById('btnApplyWin').onclick=async()=>{
    if(!currentWinRow)return;
    const selectedOpt=winAccountSelect.selectedOptions[0];
    if(selectedOpt&&selectedOpt.value){
        currentWinRow.querySelector('.input-srv-user').value=selectedOpt.dataset.user;
        currentWinRow.dataset.encPassword=selectedOpt.dataset.enc;
        closeWinModal();
        return;
    }
    const user=winUserInput.value.trim(),pwd=winPassInput.value;
    if(user)currentWinRow.querySelector('.input-srv-user').value=user;
    if(pwd){
        try{
            const res=await fetch('ajax_accounts.php',{
                method:'POST',
                headers:{'Content-Type':'application/json'},
                body:JSON.stringify({action:'encrypt_pwd',password:pwd})
            });
            const data=await res.json();
            if(data.success){
                currentWinRow.dataset.encPassword=data.enc_password;
                alert(t('alert_pwd_encrypted', 'Mot de passe chiffré et mis à jour pour ce serveur.'));
            }else{
                alert(t('msg_error', 'Erreur : ') + data.error);
            }
        }catch(err){
            alert(t('msg_network_error', 'Erreur réseau'));
        }
    }
    closeWinModal();
};

const sshModal=document.getElementById("sshModal"),
      sshConsole=document.getElementById("sshConsole"),
      sshTargetSpan=document.getElementById("sshModalTarget"),
      sshIpInput=document.getElementById("sshInputIp"),
      sshUserInput=document.getElementById("sshInputUser"),
      sshPassInput=document.getElementById("sshInputPassword"),
      sshLocalUserInput=document.getElementById("sshInputLocalUser"),
      sshProfileSelect=document.getElementById("sshProfileSelect"),
      btnRunSsh=document.getElementById("btnRunSsh");
let currentSshRow=null;

document.addEventListener('click',e=>{
    const btn=e.target.closest('.btn-ssh-key');
    if(btn){
        currentSshRow=btn.closest('tr');
        const ip=currentSshRow.querySelector('.input-srv-ip').value.trim(),
              user=currentSshRow.querySelector('.input-srv-user').value.trim(),
              srvName=currentSshRow.querySelector('.input-srv-name').value.trim();
        sshTargetSpan.textContent=srvName||ip;
        sshIpInput.value=ip;
        sshUserInput.value=user;
        sshProfileSelect.value=user||'';
        sshPassInput.value='';
        sshConsole.textContent=t('ssh_console_ready', "Prêt. Entrez le mot de passe distant et cliquez sur 'Déployer la clé'.");
        sshModal.style.display='flex';
    }
});

sshProfileSelect.onchange=()=>{
    if(sshProfileSelect.value){sshUserInput.value=sshProfileSelect.value;}
};

const closeSshModal=()=>{sshModal.style.display='none';currentSshRow=null;};
document.getElementById('btnCloseSsh').onclick=closeSshModal;
document.getElementById('btnHeaderCloseSsh').onclick=closeSshModal;

btnRunSsh.onclick=async()=>{
    const ip=sshIpInput.value.trim(),
          local_user=sshLocalUserInput.value.trim(),
          user=sshUserInput.value.trim(),
          password=sshPassInput.value;
    if(!password){
        alert(t('alert_fill_ssh_password', 'Veuillez saisir le mot de passe distant.'));
        return;
    }
    btnRunSsh.disabled=true;
    sshConsole.textContent="[1/3] " + t('log_ssh_step1', "Vérification/génération de la clé locale ({user})...").replace('{user}', local_user) + "\n";
    try{
        const res=await fetch('deploy_ssh_key.php',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({ip,local_user,user,password})
        });
        const data=await res.json();
        if(data.success){
            sshConsole.textContent+="[2/3] " + t('log_ssh_step2', "Connexion à {ip} via {user}...").replace('{ip}', ip).replace('{user}', user) + "\n";
            sshConsole.textContent+="[3/3] " + t('log_ssh_step3', "Clé publique injectée avec succès !") + "\n\n";
            sshConsole.textContent+="[✓] "+data.message;
	    if(currentSshRow)currentSshRow.querySelector('.input-srv-user').value=user;
	    }else{
            sshConsole.textContent += (data.output ? "\n" + data.output : "") + "\n[✗] " + t('msg_error', "ERREUR : ") + (data.error||t('log_ssh_failed', "Échec du déploiement"));
        }
    }catch(err){
        sshConsole.textContent+="[✗] " + t('msg_network_error', "Erreur de communication avec le serveur.");
    }
    btnRunSsh.disabled=false;
};

document.addEventListener('click',async e=>{
    const btn=e.target.closest('.btn-test-conn');
    if(btn){
        const row=btn.closest('tr'),
              name=row.querySelector('.input-srv-name').value.trim(),
              ip=row.querySelector('.input-srv-ip').value.trim(),
              type=row.querySelector('.input-srv-type').value,
              user=row.querySelector('.input-srv-user').value.trim();
        if(!ip){
            alert(t('alert_fill_ip', "Veuillez saisir une adresse IP."));
            return;
        }
        const origText=btn.textContent;
        btn.disabled=true;
        btn.classList.add('testing');
        btn.textContent="⏳ " + t('btn_testing', "Test...");
        try{
            const res=await fetch('test_connection.php',{
                method:'POST',
                headers:{'Content-Type':'application/json'},
                body:JSON.stringify({name,ip,type,user})
            });
            const data=await res.json();
            if(data.success){
                alert("✅ "+data.message);
            }else{
                alert("❌ "+data.error);
            }
        }catch(err){
            alert("❌ " + t('msg_test_network_error', "Erreur réseau lors du test"));
        }
        btn.disabled=false;
        btn.classList.remove('testing');
        btn.textContent=origText;
    }
});
const backToTopBtn = document.getElementById("backToTop");
const backToTopSpan = backToTopBtn ? backToTopBtn.querySelector("span") : null;
function updateScrollButton() {
    if (!backToTopBtn) return;
    const mainEl = document.getElementById("main");
    const scrollY = window.scrollY || (mainEl ? mainEl.scrollTop : 0);
    const scrollHeight = Math.max(
        document.documentElement.scrollHeight,
        document.body.scrollHeight,
        mainEl ? mainEl.scrollHeight : 0
    );
    const clientHeight = window.innerHeight;
    const canScroll = (scrollHeight - clientHeight) > 80;
    if (!canScroll) {
        backToTopBtn.style.display = "none";
        return;
    }
    backToTopBtn.style.display = "flex";
    if (scrollY > 150) {
        if (backToTopSpan) backToTopSpan.textContent = "↑";
        backToTopBtn.title = "<?= __('btn_back_to_top', 'Retour en haut') ?>";
    } else {
        if (backToTopSpan) backToTopSpan.textContent = "↓";
        backToTopBtn.title = "<?= __('btn_scroll_bottom', 'Descendre en bas') ?>";
    }
}
if (backToTopBtn) {
    window.addEventListener("scroll", updateScrollButton, { passive: true });
    const mainEl = document.getElementById("main");
    if (mainEl) {
        mainEl.addEventListener("scroll", updateScrollButton, { passive: true });
    }
    window.addEventListener("resize", updateScrollButton);
    backToTopBtn.addEventListener("click", () => {
        const scrollY = window.scrollY || (mainEl ? mainEl.scrollTop : 0);
        if (scrollY > 150) {
            window.scrollTo({ top: 0, behavior: "smooth" });
            if (mainEl) mainEl.scrollTo({ top: 0, behavior: "smooth" });
        } else {
            const targetHeight = Math.max(document.documentElement.scrollHeight, mainEl ? mainEl.scrollHeight : 0);
            window.scrollTo({ top: targetHeight, behavior: "smooth" });
            if (mainEl) mainEl.scrollTo({ top: targetHeight, behavior: "smooth" });
        }
    });
    if (window.ResizeObserver) {
        const ro = new ResizeObserver(() => updateScrollButton());
        if (mainEl) ro.observe(mainEl);
        ro.observe(document.body);
    }
    updateScrollButton();
    window.addEventListener("load", updateScrollButton);
    setTimeout(updateScrollButton, 100);
}
</script>
</body>
</html>
