#!/usr/bin/env bash
# NeoVision - Installation Ubuntu 22.04 / 24.04 / 26.04, x86_64 / aarch64
# Exécuter en root.

set -eo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OPT_DIR="/opt/supervision"
WWW_DIR="/var/www"
LOG_DIR="/var/log/supervision"
APACHE_CONF="/etc/apache2/sites-available/supervision.conf"
SUDOERS_FILE="/etc/sudoers.d/supervision"
CRON_FILE="/etc/cron.d/neovision"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; CYAN='\033[0;36m'; NC='\033[0m'

log_step() { echo -e "\n${BLUE}======================================================================${NC}\n${CYAN}[ÉTAPE $1]${NC} $2\n${BLUE}======================================================================${NC}"; }
log_info() { echo -e "${GREEN}[+]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[!]${NC} $1"; }
log_error() { echo -e "${RED}[ERREUR]${NC} $1"; }

clear || true
echo -e "${BLUE}######################################################################${NC}\n${CYAN}#                     INSTALLATION DE NEOVISION                      #${NC}\n${BLUE}######################################################################${NC}"

log_step 0 "Vérification de l'environnement"
if [ "$EUID" -ne 0 ]; then log_error "Ce script doit être exécuté en root."; exit 1; fi
log_info "Exécution sous root. Source : ${SCRIPT_DIR}"

log_step 1 "Installation des dépendances"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq apache2 cron php-ldap libapache2-mod-php php php-cli php-curl php-mbstring php-xml php-ssh2 jq curl fping wakeonlan openssh-client openssl sudo mailutils ssmtp logrotate ssl-cert

log_step 2 "Création des répertoires"
mkdir -p "${OPT_DIR}"/{data/history,data/temp,scripts,wol} "${LOG_DIR}" "${WWW_DIR}"/{supervision,common,assets} /root/.ssh
chmod 700 /root/.ssh

log_step 3 "Configuration de la clé SSH"
if [ ! -f /root/.ssh/id_rsa ] && [ ! -f /root/.ssh/id_ed25519 ]; then
    ssh-keygen -t ed25519 -N "" -f /root/.ssh/id_ed25519 -C "neovision-collector@$(hostname)"
else
    log_info "Une clé SSH existe déjà dans /root/.ssh."
fi

log_step 4 "Activation des modules Apache"
a2enmod rewrite ssl headers -q

log_step 5 "Déploiement du backend"
if [ -d "${SCRIPT_DIR}/opt/supervision" ]; then
    cp -r "${SCRIPT_DIR}/opt/supervision/"* "${OPT_DIR}/" 2>/dev/null || true
elif [ -d "${SCRIPT_DIR}/opt-supervision" ]; then
    cp -r "${SCRIPT_DIR}/opt-supervision/"* "${OPT_DIR}/" 2>/dev/null || true
elif [ -d "${SCRIPT_DIR}/opt" ]; then
    cp -r "${SCRIPT_DIR}/opt/"* "${OPT_DIR}/" 2>/dev/null || true
else
    log_warn "Aucun répertoire source du backend trouvé."
fi

log_step 6 "Initialisation des fichiers JSON"
if [ ! -f "${OPT_DIR}/data/config.json" ]; then
    cat > "${OPT_DIR}/data/config.json" <<'EOF'
{
    "app_name": "NeoVision",
    "theme": "dark",
    "language": "fr",
    "refresh_rate": 60,
    "ping_timeout": 1000,
    "retention_days": 7,
    "history_interval": 300
}
EOF
fi

if [ ! -f "${OPT_DIR}/data/servers.json" ]; then
    cat > "${OPT_DIR}/data/servers.json" <<'EOF'
{
    "servers": [
        {
            "id": "srv-local",
            "name": "Serveur Local",
            "ip": "127.0.0.1",
            "type": "linux",
            "check_mode": "local",
            "enabled": true
        }
    ]
}
EOF
fi

if [ ! -f "${OPT_DIR}/data/smtp.json" ]; then
    cat > "${OPT_DIR}/data/smtp.json" <<'EOF'
{
    "enabled": false,
    "host": "",
    "port": 587,
    "encryption": "tls",
    "username": "",
    "password": "",
    "from_email": "",
    "to_email": "",
    "alert_cpu_threshold": 90,
    "alert_ram_threshold": 90,
    "alert_disk_threshold": 90
}
EOF
fi

if [ ! -f "${OPT_DIR}/data/wol.json" ]; then
    printf '{\n    "devices": []\n}\n' > "${OPT_DIR}/data/wol.json"
fi

log_step 7 "Déploiement de l'interface web"
if [ -d "${SCRIPT_DIR}/var-www" ]; then
    cp -r "${SCRIPT_DIR}/var-www/"* "${WWW_DIR}/"
elif [ -d "${SCRIPT_DIR}/www" ]; then
    cp -r "${SCRIPT_DIR}/www/"* "${WWW_DIR}/"
else
    log_warn "Aucun répertoire source de l'interface web trouvé."
fi

log_step 8 "Configuration de l'authentification"
AUTH_CONFIG="${WWW_DIR}/common/auth_config.json"
echo "Mode d'authentification :"
echo "  1) Compte local"
echo "  2) Annuaire LDAP / Active Directory"
while true; do
    read -r -p "Choix [1-2] : " AUTH_CHOICE
    case "${AUTH_CHOICE}" in 1|2) break ;; *) echo "Choix invalide. Saisir 1 ou 2." ;; esac
done

read -r -p "Nom du compte administrateur local [admin] : " LOCAL_USERNAME
LOCAL_USERNAME="${LOCAL_USERNAME:-admin}"
while true; do
    read -r -s -p "Mot de passe du compte local : " LOCAL_PASSWORD
    echo
    [ -n "${LOCAL_PASSWORD}" ] && break
    echo "Le mot de passe ne peut pas être vide."
done
LOCAL_HASH="$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "${LOCAL_PASSWORD}")"
unset LOCAL_PASSWORD

if [ "${AUTH_CHOICE}" = "2" ]; then
    read -r -p "Serveur LDAP/AD (nom DNS ou IP) : " LDAP_SERVER
    read -r -p "Port LDAP [389] : " LDAP_PORT
    LDAP_PORT="${LDAP_PORT:-389}"
    read -r -p "Domaine AD (ex. exemple.local) : " LDAP_DOMAIN
    read -r -p "Base DN (ex. DC=exemple,DC=local) : " LDAP_BASE_DN
    read -r -p "Groupe AD autorisé (DN complet, vide = aucun filtre) : " LDAP_USER_GROUP
    AUTH_MODE="ldap"
else
    AUTH_MODE="local"
    LDAP_SERVER=""; LDAP_PORT="389"; LDAP_DOMAIN=""; LDAP_BASE_DN=""; LDAP_USER_GROUP=""
fi

if ! [[ "${LDAP_PORT}" =~ ^[0-9]+$ ]] || [ "${LDAP_PORT}" -lt 1 ] || [ "${LDAP_PORT}" -gt 65535 ]; then
    log_error "Port LDAP invalide."
    exit 1
fi

export AUTH_MODE LOCAL_USERNAME LOCAL_HASH LDAP_SERVER LDAP_PORT LDAP_DOMAIN LDAP_BASE_DN LDAP_USER_GROUP
php <<'PHP' > "${AUTH_CONFIG}"
<?php
$config = [
    'auth_mode' => getenv('AUTH_MODE'),
    'local_admin' => [
        'enabled' => getenv('AUTH_MODE') === 'local',
        'username' => getenv('LOCAL_USERNAME'),
        'password_hash' => getenv('LOCAL_HASH'),
    ],
    'ldap' => [
        'server' => getenv('LDAP_SERVER'),
        'port' => (int) getenv('LDAP_PORT'),
        'domain' => getenv('LDAP_DOMAIN'),
        'base_dn' => getenv('LDAP_BASE_DN'),
        'user_group' => getenv('LDAP_USER_GROUP'),
    ],
];
echo json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
PHP
unset AUTH_MODE LOCAL_HASH LDAP_SERVER LDAP_PORT LDAP_DOMAIN LDAP_BASE_DN LDAP_USER_GROUP
chmod 640 "${AUTH_CONFIG}"

log_step 9 "Configuration du VirtualHost Apache"
if [ -f "${SCRIPT_DIR}/etc-apache2/sites-available/supervision.conf" ]; then
    cp "${SCRIPT_DIR}/etc-apache2/sites-available/supervision.conf" "${APACHE_CONF}"
elif [ -f "${SCRIPT_DIR}/apache/supervision.conf" ]; then
    cp "${SCRIPT_DIR}/apache/supervision.conf" "${APACHE_CONF}"
else
    cat > "${APACHE_CONF}" <<'EOF'
<VirtualHost *:80>
    ServerName localhost
    DocumentRoot /var/www/supervision
    RewriteEngine On
    RewriteCond %{HTTPS} off
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
    ErrorLog ${APACHE_LOG_DIR}/supervision_error.log
    CustomLog ${APACHE_LOG_DIR}/supervision_access.log combined
</VirtualHost>

<VirtualHost *:443>
    ServerName localhost
    DocumentRoot /var/www/supervision
    <Directory /var/www/supervision>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    Alias /common /var/www/common
    <Directory /var/www/common>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>
    Alias /assets /var/www/assets
    <Directory /var/www/assets>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>
    SSLEngine on
    SSLCertificateFile /etc/ssl/certs/ssl-cert-snakeoil.pem
    SSLCertificateKeyFile /etc/ssl/private/ssl-cert-snakeoil.key
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-XSS-Protection "1; mode=block"
    ErrorLog ${APACHE_LOG_DIR}/supervision_ssl_error.log
    CustomLog ${APACHE_LOG_DIR}/supervision_ssl_access.log combined
</VirtualHost>
EOF
fi

if [ ! -f /etc/ssl/certs/ssl-cert-snakeoil.pem ]; then
    make-ssl-cert generate-default-snakeoil --force-overwrite
fi
a2dissite 000-default.conf -q 2>/dev/null || true
a2ensite supervision.conf -q
systemctl reload apache2

log_step 10 "Application des permissions"
find "${OPT_DIR}/scripts" "${OPT_DIR}/wol" -type f -name "*.sh" -exec chmod +x {} + 2>/dev/null || true
chown -R root:www-data "${OPT_DIR}" "${LOG_DIR}"
chmod 750 "${OPT_DIR}" "${OPT_DIR}/scripts"
chmod -R 770 "${OPT_DIR}/data"
[ -d "${OPT_DIR}/wol" ] && chmod -R 750 "${OPT_DIR}/wol"
chmod 770 "${LOG_DIR}"
find "${LOG_DIR}" -type f -exec chmod 660 {} + 2>/dev/null || true
chown -R www-data:www-data "${WWW_DIR}/supervision" "${WWW_DIR}/common" "${WWW_DIR}/assets" 2>/dev/null || true
chmod -R 750 "${WWW_DIR}/supervision" "${WWW_DIR}/common" "${WWW_DIR}/assets" 2>/dev/null || true
chown root:www-data "${AUTH_CONFIG}"
chmod 640 "${AUTH_CONFIG}"

log_step 11 "Configuration de Sudoers"
cat > "${SUDOERS_FILE}" <<'EOF'
www-data ALL=(ALL) NOPASSWD: /opt/supervision/scripts/collect.sh
www-data ALL=(ALL) NOPASSWD: /opt/supervision/scripts/history.sh
www-data ALL=(ALL) NOPASSWD: /usr/bin/fping
www-data ALL=(ALL) NOPASSWD: /usr/bin/wakeonlan
EOF
chmod 440 "${SUDOERS_FILE}"
visudo -cf "${SUDOERS_FILE}"

log_step 12 "Configuration de Cron"
cat > "${CRON_FILE}" <<'EOF'
*/1 * * * * root /opt/supervision/scripts/collect.sh >/dev/null 2>&1
*/5 * * * * root /opt/supervision/scripts/history.sh >/dev/null 2>&1
EOF
chmod 644 "${CRON_FILE}"

log_step 13 "Configuration de Logrotate"
cat > /etc/logrotate.d/neovision <<'EOF'
/var/log/supervision/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0660 root www-data
}
EOF
chmod 644 /etc/logrotate.d/neovision

log_step 14 "Première collecte"
if [ -f "${OPT_DIR}/scripts/collect.sh" ]; then
    bash "${OPT_DIR}/scripts/collect.sh" || log_warn "La première collecte a retourné un code d'avertissement."
fi

IP_SRV="$(hostname -I | awk '{print $1}')"
log_step 15 "Installation terminée"
echo -e "\n${GREEN}======================================================================${NC}\n${GREEN}               NEOVISION EST PRÊT À L'EMPLOI !                       ${NC}\n${GREEN}======================================================================${NC}"
echo -e " Interface Web : ${CYAN}https://${IP_SRV}/${NC}"
if [ "${AUTH_CHOICE}" = "1" ]; then
    echo -e " Compte local : ${YELLOW}${LOCAL_USERNAME}${NC} (mot de passe choisi pendant l'installation)"
else
    echo -e " Authentification : ${YELLOW}LDAP / Active Directory${NC}"
    echo -e " Compte local de secours : ${YELLOW}${LOCAL_USERNAME}${NC} (désactivé)"
fi
echo " Clé SSH publique pour agents distants :"
if [ -f /root/.ssh/id_ed25519.pub ]; then cat /root/.ssh/id_ed25519.pub
elif [ -f /root/.ssh/id_rsa.pub ]; then cat /root/.ssh/id_rsa.pub
fi
echo -e "${GREEN}======================================================================${NC}\n"
