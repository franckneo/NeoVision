<?php
function ldapLogin(string $username, string $password): bool
{
    $configFile = '/var/www/common/auth_config.json';
    if (!file_exists($configFile)) {
        error_log("NeoVision Auth: auth_config.json introuvable.");
        return false;
    }
    $config = json_decode(file_get_contents($configFile), true);
    if (!$config) {
        error_log("NeoVision Auth: auth_config.json invalide.");
        return false;
    }
    if (!empty($config['local_admin']['enabled'])) {
        $localUser = $config['local_admin']['username'] ?? 'admin';
        $localHash = $config['local_admin']['password_hash'] ?? '';
        if ($username === $localUser && password_verify($password, $localHash)) {
            return true;
        }
    }
    if (($config['auth_mode'] ?? 'ldap') === 'local') {
        return false;
    }
    $ldapCfg = $config['ldap'] ?? [];
    $ldap_server = $ldapCfg['server'] ?? '';
    $ldap_port   = (int)($ldapCfg['port'] ?? 389);
    $ldap_domain = $ldapCfg['domain'] ?? '';
    $ldap_dn     = $ldapCfg['base_dn'] ?? '';
    $ldap_group  = $ldapCfg['user_group'] ?? '';
    if (empty($ldap_server) || empty($ldap_domain) || empty($ldap_dn)) {
        error_log("NeoVision Auth: Configuration LDAP incomplète.");
        return false;
    }
    $ldap_conn = @ldap_connect($ldap_server, $ldap_port);
    if (!$ldap_conn) {
        return false;
    }
    ldap_set_option($ldap_conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ldap_conn, LDAP_OPT_REFERRALS, 0);
    ldap_set_option($ldap_conn, LDAP_OPT_NETWORK_TIMEOUT, 5); // Évite de bloquer indéfiniment si le DC ne répond pas
    $bindUser = str_contains($username, '@') ? $username : $username . "@" . $ldap_domain;
    if (!@ldap_bind($ldap_conn, $bindUser, $password)) {
        @ldap_close($ldap_conn);
        return false;
    }
    if (empty($ldap_group)) {
        @ldap_close($ldap_conn);
        return true;
    }
    $clean_username = ldap_escape($username, '', LDAP_ESCAPE_FILTER);
    $filter = "(&(sAMAccountName={$clean_username})(memberOf={$ldap_group}))";
    $search = @ldap_search($ldap_conn, $ldap_dn, $filter);
    if (!$search) {
        @ldap_close($ldap_conn);
        return false;
    }
    $entries = @ldap_get_entries($ldap_conn, $search);
    @ldap_close($ldap_conn);
    return ($entries && isset($entries['count']) && $entries['count'] > 0);
}
