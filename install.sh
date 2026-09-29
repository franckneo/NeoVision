#!/usr/bin/env bash

# ==============================================================================
# NeoVision - Script d'installation automatique et sécurisé
# Compatible : Ubuntu 22.04 LTS / 24.04 LTS (x86_64 / aarch64)
# Conçu pour exécution directe sous compte root
# ==============================================================================

set -eo pipefail

# Détection du répertoire source réel du script (résout le bug des chemins relatifs)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Couleurs pour la console
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Constantes de chemins
OPT_DIR="/opt/supervision"
WWW_DIR="/var/www"
LOG_DIR="/var/log/supervision"
APACHE_CONF="/etc/apache2/sites-available/supervision.conf"
SUDOERS_FILE="/etc/sudoers.d/supervision"
CRON_FILE="/etc/cron.d/neovision"

# Fonctions d'affichage
log_step() {
    echo -e "\n${BLUE}======================================================================${NC}"
    echo -e "${CYAN}[ÉTAPE $1]${NC} $2"
    echo -e "${BLUE}======================================================================${NC}"
}

log_info() {
    echo -e "${GREEN}[+]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[!]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERREUR]${NC} $1"
}

# En-tête
clear || true
echo -e "${BLUE}######################################################################${NC}"
echo -e "${CYAN}#                     INSTALLATION DE NEOVISION                      #${NC}"
echo -e "${BLUE}######################################################################${NC}"

# 0. VÉRIFICATION DES DROITS ROOT
log_step "0" "Vérification de l'environnement"
if [ "$EUID" -ne 0 ]; then
    log_error "Ce script doit impérativement être exécuté sous le compte root."
    exit 1
fi
log_info "Exécution sous compte root confirmée."
log_info "Dossier source détecté : ${SCRIPT_DIR}"

# 1. MISE À JOUR ET INSTALLATION DES DÉPENDANCES
log_step "1" "Installation des paquets requis via APT"
export DEBIAN_FRONTEND=noninteractive

log_info "Mise à jour de la liste des paquets..."
apt-get update -qq

log_info "Installation d'Apache, PHP, OpenSSH et des outils système..."
apt-get install -y -qq \
    apache2 \
    cron \
    php-ldap \
    libapache2-mod-php \
    php \
    php-cli \
    php-curl \
    php-mbstring \
    php-xml \
    php-ssh2 \
    jq \
    curl \
    fping \
    wakeonlan \
    openssh-client \
    openssl \
    sudo \
    mailutils \
    ssmtp \
    logrotate \
    ssl-cert

# 2. CRÉATION DE L'ARBORESCENCE SYSTÈME
log_step "2" "Création des répertoires de base"
mkdir -p "${OPT_DIR}/data/history"
mkdir -p "${OPT_DIR}/data/temp"
mkdir -p "${OPT_DIR}/scripts"
mkdir -p "${OPT_DIR}/wol"
mkdir -p "${LOG_DIR}"
mkdir -p "${WWW_DIR}/supervision"
mkdir -p "${WWW_DIR}/common"
mkdir -p "${WWW_DIR}/assets"
mkdir -p /root/.ssh
chmod 700 /root/.ssh
log_info "Arborescence créée avec succès."

# 3. GESTION DES CLÉS SSH POUR LES SONDES DISTANTES
log_step "3" "Configuration de la clé SSH locale (Root)"
if [ ! -f /root/.ssh/id_rsa ] && [ ! -f /root/.ssh/id_ed25519 ]; then
    log_info "Génération d'une paire de clés SSH ED25519 pour la collecte à distance..."
    ssh-keygen -t ed25519 -N "" -f /root/.ssh/id_ed25519 -C "neovision-collector@$(hostname)"
else
    log_info "Une clé SSH existe déjà dans /root/.ssh/."
fi

# 4. ACTIVATION DES MODULES APACHE
log_step "4" "Configuration des modules Apache"
a2enmod rewrite ssl headers -q
log_info "Modules rewrite, ssl et headers activés."

# 5. DÉPLOIEMENT DU BACKEND (/opt/supervision)
log_step "5" "Déploiement des scripts Backend dans ${OPT_DIR}"

# Détection automatique de la structure source (opt/supervision, opt/ ou opt-supervision)
if [ -d "${SCRIPT_DIR}/opt/supervision" ]; then
    log_info "Source trouvée : ${SCRIPT_DIR}/opt/supervision"
    cp -r "${SCRIPT_DIR}/opt/supervision/"* "${OPT_DIR}/" 2>/dev/null || true
elif [ -d "${SCRIPT_DIR}/opt-supervision" ]; then
    log_info "Source trouvée : ${SCRIPT_DIR}/opt-supervision"
    cp -r "${SCRIPT_DIR}/opt-supervision/"* "${OPT_DIR}/" 2>/dev/null || true
elif [ -d "${SCRIPT_DIR}/opt" ]; then
    log_info "Source trouvée : ${SCRIPT_DIR}/opt"
    cp -r "${SCRIPT_DIR}/opt/"* "${OPT_DIR}/" 2>/dev/null || true
fi

# 6. INITIALISATION DES FICHIERS JSON DE CONFIGURATION
log_step "6" "Vérification et initialisation des fichiers de configuration"

# config.json
if [ ! -f "${OPT_DIR}/data/config.json" ]; then
    log_info "Création de config.json par défaut..."
    cat << 'EOF' > "${OPT_DIR}/data/config.json"
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

# servers.json
if [ ! -f "${OPT_DIR}/data/servers.json" ]; then
    log_info "Création de servers.json par défaut..."
    cat << 'EOF' > "${OPT_DIR}/data/servers.json"
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

# smtp.json
if [ ! -f "${OPT_DIR}/data/smtp.json" ]; then
    log_info "Création de smtp.json par défaut..."
    cat << 'EOF' > "${OPT_DIR}/data/smtp.json"
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

# wol.json
if [ ! -f "${OPT_DIR}/data/wol.json" ]; then
    log_info "Création de wol.json par défaut..."
    cat << 'EOF' > "${OPT_DIR}/data/wol.json"
{
    "devices": []
}
EOF
fi

# auth.json (gestion de l'authentification Locale et LDAP/AD)
if [ ! -f "${OPT_DIR}/data/auth.json" ]; then
    log_info "Création de auth.json (admin / admin par défaut)..."
    cat << 'EOF' > "${OPT_DIR}/data/auth.json"
{
    "auth_mode": "local",
    "local_admin": {
        "enabled": true,
        "username": "admin",
        "password_hash": "$2y$10$4.oP2rD85i2kKqDk5E4bEOC99u86pGkIu0d0FzZfZJ0yT2YxV2J9u"
    },
    "ldap": {
        "server": "",
        "port": 389,
        "domain": "",
        "base_dn": "",
        "user_group": ""
    }
}
EOF
fi

# 7. DÉPLOIEMENT DU CODE WEB (/var/www/)
log_step "7" "Déploiement de l'interface Web dans ${WWW_DIR}"

if [ -d "${SCRIPT_DIR}/var-www" ]; then
    log_info "Source web trouvée : ${SCRIPT_DIR}/var-www"
    cp -r "${SCRIPT_DIR}/var-www/"* "${WWW_DIR}/"
elif [ -d "${SCRIPT_DIR}/www" ]; then
    log_info "Source web trouvée : ${SCRIPT_DIR}/www"
    cp -r "${SCRIPT_DIR}/www/"* "${WWW_DIR}/"
fi

# 8. CONFIGURATION DU VIRTUALHOST APACHE
log_step "8" "Configuration du VirtualHost Apache"

# Vérifier si un template existe dans le dépôt
if [ -f "${SCRIPT_DIR}/etc-apache2/sites-available/supervision.conf" ]; then
    cp "${SCRIPT_DIR}/etc-apache2/sites-available/supervision.conf" "${APACHE_CONF}"
elif [ -f "${SCRIPT_DIR}/apache/supervision.conf" ]; then
    cp "${SCRIPT_DIR}/apache/supervision.conf" "${APACHE_CONF}"
else
    log_info "Génération d'un VirtualHost Apache complet..."
    cat << 'EOF' > "${APACHE_CONF}"
<VirtualHost *:80>
    ServerName localhost
    DocumentRoot /var/www/supervision

    # Redirection HTTPS automatique
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

    # Sécurisation des en-têtes HTTP
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-XSS-Protection "1; mode=block"

    ErrorLog ${APACHE_LOG_DIR}/supervision_ssl_error.log
    CustomLog ${APACHE_LOG_DIR}/supervision_ssl_access.log combined
</VirtualHost>
EOF
fi

# Certificat SSL snakeoil
if [ ! -f /etc/ssl/certs/ssl-cert-snakeoil.pem ]; then
    make-ssl-cert generate-default-snakeoil --force-overwrite
fi

a2dissite 000-default.conf -q 2>/dev/null || true
a2ensite supervision.conf -q
systemctl reload apache2
log_info "VirtualHost Apache activé et rechargé."

# 9. PERMISSIONS ET SÉCURITÉ DU SYSTÈME DE FICHIERS
log_step "9" "Application stricte des permissions et droits"

# Exécutables backend
find "${OPT_DIR}/scripts" -type f -name "*.sh" -exec chmod +x {} + 2>/dev/null || true
find "${OPT_DIR}/wol" -type f -name "*.sh" -exec chmod +x {} + 2>/dev/null || true

# Droits /opt/supervision
chown -R root:www-data "${OPT_DIR}"
chmod 750 "${OPT_DIR}"
chmod -R 770 "${OPT_DIR}/data"
chmod -R 750 "${OPT_DIR}/scripts"
[ -d "${OPT_DIR}/wol" ] && chmod -R 750 "${OPT_DIR}/wol"

# Droits Logs
chown -R root:www-data "${LOG_DIR}"
chmod 770 "${LOG_DIR}"
find "${LOG_DIR}" -type f -exec chmod 660 {} + 2>/dev/null || true

# Droits Web
chown -R www-data:www-data "${WWW_DIR}/supervision" "${WWW_DIR}/common" "${WWW_DIR}/assets" 2>/dev/null || true
chmod -R 750 "${WWW_DIR}/supervision" "${WWW_DIR}/common" "${WWW_DIR}/assets" 2>/dev/null || true
log_info "Permissions appliquées."

# 10. CONFIGURATION SUDOERS (ACCÈS CIBLÉS POUR WWW-DATA)
log_step "10" "Configuration du fichier Sudoers (${SUDOERS_FILE})"
cat << 'EOF' > "${SUDOERS_FILE}"
# NeoVision - Autorisations d'exécution ciblées pour www-data
www-data ALL=(ALL) NOPASSWD: /opt/supervision/scripts/collect.sh
www-data ALL=(ALL) NOPASSWD: /opt/supervision/scripts/history.sh
www-data ALL=(ALL) NOPASSWD: /usr/bin/fping
www-data ALL=(ALL) NOPASSWD: /usr/bin/wakeonlan
EOF
chmod 440 "${SUDOERS_FILE}"
visudo -cf "${SUDOERS_FILE}"
log_info "Règles Sudoers validées et appliquées."

# 11. CONFIGURATION DU CRON (TÂCHES PLANIFIÉES)
log_step "11" "Configuration de la planification Cron (${CRON_FILE})"
cat << 'EOF' > "${CRON_FILE}"
# NeoVision - Collecte des métriques et historisation
*/1 * * * * root /opt/supervision/scripts/collect.sh >/dev/null 2>&1
*/5 * * * * root /opt/supervision/scripts/history.sh >/dev/null 2>&1
EOF
chmod 644 "${CRON_FILE}"
log_info "Cron configuré : collecte (1 min) / historique (5 min)."

# 12. CONFIGURATION DE LOGROTATE
log_step "12" "Configuration de la rotation des logs"
cat << 'EOF' > /etc/logrotate.d/neovision
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
log_info "Rotation des logs configurée (14 jours de rétention)."

# 13. PREMIÈRE EXÉCUTION DE LA COLLECTE
log_step "13" "Exécution initiale de la collecte"
if [ -f "${OPT_DIR}/scripts/collect.sh" ]; then
    bash "${OPT_DIR}/scripts/collect.sh" || log_warn "La première passe de collecte a retourné un code d'avertissement."
    log_info "Collecte initiale exécutée."
fi

# 14. RÉCAPITULATIF FINAL
IP_SRV=$(hostname -I | awk '{print $1}')
log_step "14" "Installation terminée avec succès"

echo -e "\n${GREEN}======================================================================${NC}"
echo -e "${GREEN}               NEOVISION EST PRÊT À L'EMPLOI !                       ${NC}"
echo -e "${GREEN}======================================================================${NC}"
echo -e " Interface Web   : ${CYAN}https://${IP_SRV}/${NC}"
echo -e " Répertoire Web  : ${BLUE}${WWW_DIR}/supervision${NC}"
echo -e " Données & Conf  : ${BLUE}${OPT_DIR}/data${NC}"
echo -e " Logs système    : ${BLUE}${LOG_DIR}${NC}"
echo -e " Identifiants    : ${YELLOW}admin${NC} / ${YELLOW}admin${NC} (à modifier dès la première connexion)"
echo -e " Clé SSH publique pour agents distants :"
if [ -f /root/.ssh/id_ed25519.pub ]; then
    cat /root/.ssh/id_ed25519.pub
elif [ -f /root/.ssh/id_rsa.pub ]; then
    cat /root/.ssh/id_rsa.pub
fi
echo -e "${GREEN}======================================================================${NC}\n"
