#!/usr/bin/env bash
# NeoVision - Installation Ubuntu 22.04 / 24.04 / 26.04, x86_64 / aarch64
# Exécuter en root.
set -eo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OPT_DIR="/opt/supervision"
WWW_DIR="/var/www"
LOG_DIR="/var/log/supervision"
SVC_USER="neovision"
SVC_HOME="/var/lib/neovision"
APACHE_CONF="/etc/apache2/sites-available/supervision.conf"
SUDOERS_FILE="/etc/sudoers.d/supervision"
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
apt-get update
apt-get install -y apache2 cron php-ldap libapache2-mod-php php php-cli php-curl php-mbstring php-xml php-ssh2 jq curl fping wakeonlan openssh-client sshpass python3-cryptography python3-venv python3-pip openssl sudo logrotate ssl-cert iputils-ping cifs-utils

log_step 2 "Création de l'utilisateur de service"
if ! id -u "${SVC_USER}" &>/dev/null; then
    useradd --system --shell /usr/sbin/nologin --home-dir "${SVC_HOME}" --create-home "${SVC_USER}"
    log_info "Utilisateur ${SVC_USER} créé."
else
    log_info "Utilisateur ${SVC_USER} déjà présent."
fi
usermod -aG www-data "${SVC_USER}"
mkdir -p "${SVC_HOME}/.ssh"
chmod 700 "${SVC_HOME}/.ssh"
chown -R "${SVC_USER}:${SVC_USER}" "${SVC_HOME}"

log_step 3 "Création des répertoires, du venv Python et de la clé de chiffrement"
mkdir -p "${OPT_DIR}"/{data/history,data/temp,scripts,wol} "${LOG_DIR}" "${WWW_DIR}"/{supervision,common,assets}
if [ ! -d "${OPT_DIR}/venv" ]; then
    python3 -m venv "${OPT_DIR}/venv"
    "${OPT_DIR}/venv/bin/pip" install --upgrade pip -q
    "${OPT_DIR}/venv/bin/pip" install -q "cryptography==50.0.2" "pywinrm==0.5.0" "requests==2.34.2" "requests_ntlm==1.3.0" "pyspnego==0.12.3" "xmltodict==1.0.4" "filelock"
    log_info "Environnement virtuel Python créé et initialisé."
fi
SECRET_KEY_FILE="${OPT_DIR}/secret.key"
if [ ! -s "${SECRET_KEY_FILE}" ]; then
    (
        umask 077
        "${OPT_DIR}/venv/bin/python3" -c "from cryptography.fernet import Fernet; print(Fernet.generate_key().decode())" > "${SECRET_KEY_FILE}"
    )
    log_info "Clé de chiffrement créée."
else
    log_info "Clé de chiffrement existante conservée."
fi
chown "${SVC_USER}:www-data" "${SECRET_KEY_FILE}"
chmod 640 "${SECRET_KEY_FILE}"

log_step 4 "Configuration de la clé SSH du compte ${SVC_USER}"
if [ ! -f "${SVC_HOME}/.ssh/id_ed25519" ]; then
    sudo -u "${SVC_USER}" ssh-keygen -t ed25519 -N "" -f "${SVC_HOME}/.ssh/id_ed25519" -C "neovision@$(hostname)"
    log_info "Clé SSH générée dans ${SVC_HOME}/.ssh."
else
    log_info "Clé SSH existante conservée."
fi
chown -R "${SVC_USER}:${SVC_USER}" "${SVC_HOME}/.ssh"
chmod 700 "${SVC_HOME}/.ssh"
chmod 600 "${SVC_HOME}/.ssh/id_ed25519"
chmod 644 "${SVC_HOME}/.ssh/id_ed25519.pub"

log_step 5 "Activation des modules Apache"
a2enmod rewrite ssl headers -q
sed -i 's/^session.gc_maxlifetime = .*/session.gc_maxlifetime = 28800/' /etc/php/*/*/php.ini 2>/dev/null || true

log_step 6 "Déploiement du backend"
if [ -d "${SCRIPT_DIR}/opt/supervision" ]; then
    cp -r "${SCRIPT_DIR}/opt/supervision/"* "${OPT_DIR}/" 2>/dev/null || true
elif [ -d "${SCRIPT_DIR}/opt-supervision" ]; then
    cp -r "${SCRIPT_DIR}/opt-supervision/"* "${OPT_DIR}/" 2>/dev/null || true
elif [ -d "${SCRIPT_DIR}/opt" ]; then
    cp -r "${SCRIPT_DIR}/opt/"* "${OPT_DIR}/" 2>/dev/null || true
else
    log_warn "Aucun répertoire source du backend trouvé."
fi
[ ! -f "${OPT_DIR}/update_lstatus.py" ] && touch "${OPT_DIR}/update_lstatus.py"

log_step 7 "Création du script de déploiement de clé SSH"
cat > "${OPT_DIR}/scripts/deploy_ssh.sh" <<'EOF'
#!/usr/bin/env bash
# Déploie la clé publique neovision vers une machine cible.
# Usage : deploy_ssh.sh <ip> <utilisateur>
# Le mot de passe de l'utilisateur cible est lu sur l'entrée standard (jamais en argument).
# Exécuté en tant que "neovision" via sudo depuis www-data.
set -eo pipefail

TARGET_IP="${1:-}"
TARGET_USER="${2:-}"
PUBKEY="/var/lib/neovision/.ssh/id_ed25519.pub"

# Validation stricte IPv4
if ! [[ "${TARGET_IP}" =~ ^((25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])\.){3}(25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])$ ]]; then
    echo "IP invalide" >&2; exit 2
fi
# Validation stricte du nom d'utilisateur (POSIX, 1 à 32 caractères)
if ! [[ "${TARGET_USER}" =~ ^[a-zA-Z_][a-zA-Z0-9_.-]{0,31}$ ]]; then
    echo "Utilisateur invalide" >&2; exit 3
fi
[ -f "${PUBKEY}" ] || { echo "Clé publique introuvable" >&2; exit 4; }

IFS= read -r -s SSHPASS || true
[ -n "${SSHPASS:-}" ] || { echo "Mot de passe manquant sur stdin" >&2; exit 5; }
export SSHPASS

sshpass -e ssh-copy-id -i "${PUBKEY}" \
    -o StrictHostKeyChecking=accept-new \
    -o ConnectTimeout=10 \
    -- "${TARGET_USER}@${TARGET_IP}"
EOF

log_step 8 "Initialisation des fichiers JSON"
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

[ -f "${OPT_DIR}/data/wol.json" ] || printf '{\n    "devices": []\n}\n' > "${OPT_DIR}/data/wol.json"
[ -f "${OPT_DIR}/data/alert_config.json" ] || echo "{}" > "${OPT_DIR}/data/alert_config.json"

if [ ! -f "${OPT_DIR}/data/update_status.json" ]; then
    cat > "${OPT_DIR}/data/update_status.json" <<'EOF'
{
    "running": false,
    "message": "idle",
    "timestamp": null,
    "finished_at": null,
    "pid": null
}
EOF
fi

log_step 9 "Déploiement de l'interface web"
if [ -d "${SCRIPT_DIR}/var-www" ]; then
    cp -r "${SCRIPT_DIR}/var-www/"* "${WWW_DIR}/"
elif [ -d "${SCRIPT_DIR}/www" ]; then
    cp -r "${SCRIPT_DIR}/www/"* "${WWW_DIR}/"
else
    log_warn "Aucun répertoire source de l'interface web trouvé."
fi
mkdir -p "${WWW_DIR}/supervision/api"
ln -sfn "${OPT_DIR}/data" "${WWW_DIR}/supervision/api/data"
ln -sfn "${OPT_DIR}/data" "${WWW_DIR}/supervision/data"

log_step 10 "Configuration de l'authentification"
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
    LDAP_SERVER=""
    LDAP_PORT="389"
    LDAP_DOMAIN=""
    LDAP_BASE_DN=""
    LDAP_USER_GROUP=""
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

log_step 11 "Configuration du VirtualHost Apache"
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
rm -f /etc/systemd/system/apache2.service.d/override.conf
rmdir /etc/systemd/system/apache2.service.d 2>/dev/null || true
systemctl daemon-reload
systemctl restart apache2

log_step 12 "Application des permissions"
chown -R root:www-data "${OPT_DIR}"
chmod 755 "${OPT_DIR}" "${OPT_DIR}/scripts"
find "${OPT_DIR}/scripts" "${OPT_DIR}/wol" -type f -name "*.sh" -exec chmod 750 {} + 2>/dev/null || true
chown root:"${SVC_USER}" "${OPT_DIR}/scripts/deploy_ssh.sh"
chmod 750 "${OPT_DIR}/scripts/deploy_ssh.sh"
[ -d "${OPT_DIR}/mailing" ] && find "${OPT_DIR}/mailing" -type d -exec chmod 750 {} + && find "${OPT_DIR}/mailing" -type f -exec chmod 640 {} +
[ -f "${OPT_DIR}/refresh_server.py" ] && chmod 755 "${OPT_DIR}/refresh_server.py" || true
[ -d "${OPT_DIR}/lib" ] && chmod 755 "${OPT_DIR}/lib" && chmod 644 "${OPT_DIR}/lib"/*.py 2>/dev/null || true
[ -f "${OPT_DIR}/update_lstatus.py" ] && chmod 750 "${OPT_DIR}/update_lstatus.py"
chown -R www-data:www-data "${OPT_DIR}/data"
find "${OPT_DIR}/data" -type d -exec chmod 2775 {} +
find "${OPT_DIR}/data" -type f -exec chmod 664 {} +
usermod -aG www-data "${SVC_USER}" 2>/dev/null || true
mkdir -p "${OPT_DIR}/history"
touch "${OPT_DIR}/history/alerts.csv"
chown -R root:www-data "${OPT_DIR}/history"
chmod 2770 "${OPT_DIR}/history"
find "${OPT_DIR}/history" -type f \( -name "*.json" -o -name "*.csv" \) -exec chmod 664 {} +
for logfile in history.log mailing.log notification.log refresh_server.log save_servers.log update_disks.log update_lstatus.log update_ping.log update_ram.log update_status.log update_ubuntu.log update_uptime.log update_windows.log; do
    [ -f "${LOG_DIR}/${logfile}" ] || touch "${LOG_DIR}/${logfile}"
done
chown -R www-data:www-data "${LOG_DIR}"
chmod 2775 "${LOG_DIR}"
chmod 664 "${LOG_DIR}"/*.log 2>/dev/null || true
chown -R www-data:www-data "${WWW_DIR}/supervision" "${WWW_DIR}/common" "${WWW_DIR}/assets" 2>/dev/null || true
chmod -R 750 "${WWW_DIR}/supervision" "${WWW_DIR}/common" "${WWW_DIR}/assets" 2>/dev/null || true
chown root:www-data "${AUTH_CONFIG}"
chmod 640 "${AUTH_CONFIG}"

log_step 13 "Configuration de Sudoers"
cat > "${SUDOERS_FILE}" <<'EOF'
# Déploiement de la clé SSH : exécuté uniquement en tant que "neovision"
www-data ALL=(neovision) NOPASSWD: /opt/supervision/scripts/deploy_ssh.sh
# Scripts de collecte / historique (root requis pour fping/ICMP, etc.)
www-data ALL=(root) NOPASSWD: /opt/supervision/scripts/collect.sh
www-data ALL=(root) NOPASSWD: /opt/supervision/scripts/history.sh
www-data ALL=(root) NOPASSWD: /usr/bin/fping
www-data ALL=(root) NOPASSWD: /usr/bin/wakeonlan
www-data ALL=(root) NOPASSWD: /opt/supervision/venv/bin/python /opt/supervision/refresh_server.py *
EOF
chmod 440 "${SUDOERS_FILE}"
visudo -cf "${SUDOERS_FILE}"

log_step 14 "Configuration de Cron (utilisateur ${SVC_USER})"
cat <<'EOF' | crontab -u "${SVC_USER}" -
MAILTO=""
* * * * * /usr/bin/php /opt/supervision/mailing/notification.php >> /var/log/supervision/notification.log 2>&1
* * * * * /bin/bash -c '/opt/supervision/venv/bin/python /opt/supervision/history/history.py & /opt/supervision/venv/bin/python /opt/supervision/update_ping.py & /opt/supervision/venv/bin/python /opt/supervision/update_switchs.py & /opt/supervision/venv/bin/python /opt/supervision/update_wifi.py & /opt/supervision/venv/bin/python /opt/supervision/update_others.py & wait; /opt/supervision/venv/bin/python /opt/supervision/build_dashboard.py' >/dev/null 2>&1
*/3 * * * * /opt/supervision/venv/bin/python /opt/supervision/update_ubuntu.py >/dev/null 2>&1
*/3 * * * * /opt/supervision/venv/bin/python /opt/supervision/update_windows.py >/dev/null 2>&1
*/5 * * * * /opt/supervision/venv/bin/python /opt/supervision/pcstatus.py >/dev/null 2>&1
*/10 * * * * /opt/supervision/venv/bin/python /opt/supervision/update_uptime.py >/dev/null 2>&1
*/15 * * * * /opt/supervision/venv/bin/python /opt/supervision/update_ram.py >/dev/null 2>&1
0 */1 * * * /opt/supervision/venv/bin/python /opt/supervision/update_status.py >/dev/null 2>&1
0 */6 * * * /opt/supervision/venv/bin/python /opt/supervision/update_disks.py >/dev/null 2>&1
1 0 * * * /opt/supervision/venv/bin/python /opt/supervision/clean_json.py >/dev/null 2>&1
1 0 1 * * /usr/bin/python3 /opt/supervision/clean_history.py >/dev/null 2>&1
EOF
if crontab -l 2>/dev/null | grep -q '/opt/supervision/'; then
    crontab -l | grep -v '/opt/supervision/' | crontab - || true
    log_info "Anciennes tâches NeoVision retirées de la crontab root."
fi

log_step 15 "Configuration de Logrotate"
cat > /etc/logrotate.d/neovision <<'EOF'
/var/log/supervision/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0664 www-data www-data
}
EOF
chmod 644 /etc/logrotate.d/neovision

log_step 16 "Première collecte"
if [ -f "${OPT_DIR}/scripts/collect.sh" ]; then
    sudo -u "${SVC_USER}" bash "${OPT_DIR}/scripts/collect.sh" || log_warn "La première collecte a retourné un code d'avertissement."
fi

IP_SRV="$(hostname -I | awk '{print $1}')"
log_step 17 "Installation terminée"
echo -e "\n${GREEN}======================================================================${NC}\n${GREEN}               NEOVISION EST PRÊT À L'EMPLOI !                       ${NC}\n${GREEN}======================================================================${NC}"
echo -e " Interface Web : ${CYAN}https://${IP_SRV}/${NC}"
if [ "${AUTH_CHOICE}" = "1" ]; then
    echo -e " Compte local : ${YELLOW}${LOCAL_USERNAME}${NC} (mot de passe choisi pendant l'installation)"
else
    echo -e " Authentification : ${YELLOW}LDAP / Active Directory${NC}"
    echo -e " Compte local de secours : ${YELLOW}${LOCAL_USERNAME}${NC} (désactivé)"
fi
echo " Clé SSH publique pour agents distants (compte ${SVC_USER}) :"
cat "${SVC_HOME}/.ssh/id_ed25519.pub"
echo -e "${GREEN}======================================================================${NC}\n"
