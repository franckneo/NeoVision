#!/usr/bin/env bash

# NeoVision - Script d'installation automatique
# Compatible Ubuntu 22.04 / 24.04 (Root)

set -e

# Couleurs pour l'affichage
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}====================================================${NC}"
echo -e "${BLUE}             INSTALLATION DE NEOVISION              ${NC}"
echo -e "${BLUE}====================================================${NC}\n"

# 1. Vérification des droits root
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}[ERREUR] Ce script doit être exécuté en tant que root.${NC}"
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OPT_DIR="/opt/supervision"
WWW_BASE="/var/www"
LOG_DIR="/var/log/supervision"
SUDOERS_FILE="/etc/sudoers.d/supervision"
MANIFEST_FILE="/etc/neovision_manifest.txt"

# Initialisation du manifeste d'installation
touch "$MANIFEST_FILE"

# 2. Détection du port Apache / HTTPS
DEFAULT_PORT=443
PORT=$DEFAULT_PORT

echo -e "${BLUE}[*] Vérification des ports réseau...${NC}"
if ss -tuln | grep -q ":$DEFAULT_PORT "; then
    echo -e "${YELLOW}[ATTENTION] Le port $DEFAULT_PORT est déjà utilisé.${NC}"
    read -rp "Veuillez entrer un autre port d'écoute (ex: 8443) : " USER_PORT
    PORT=${USER_PORT:-8443}
else
    echo -e "${GREEN}[OK] Le port $DEFAULT_PORT est libre.${NC}"
fi

# 3. Installation des paquets système requis
echo -e "\n${BLUE}[*] Mise à jour des dépôts et installation des paquets système...${NC}"
apt-get update -y
DEBIAN_FRONTEND=noninteractive apt-get install -y \
    apache2 \
    libapache2-mod-php \
    php \
    php-cli \
    php-curl \
    php-ldap \
    php-mbstring \
    php-xml \
    python3 \
    python3-venv \
    python3-pip \
    sshpass \
    wakeonlan \
    fping \
    rsync \
    openssl

# Activer les modules Apache nécessaires
a2enmod rewrite ssl headers

# 4. Déploiement des fichiers dans /var/www
echo -e "\n${BLUE}[*] Déploiement des interfaces web dans /var/www...${NC}"
mkdir -p "$WWW_BASE/supervision" "$WWW_BASE/common" "$WWW_BASE/assets"

if [ -d "$SCRIPT_DIR/var/www/supervision" ]; then
    rsync -av "$SCRIPT_DIR/var/www/supervision/" "$WWW_BASE/supervision/"
fi
if [ -d "$SCRIPT_DIR/var/www/common" ]; then
    rsync -av "$SCRIPT_DIR/var/www/common/" "$WWW_BASE/common/"
fi
if [ -d "$SCRIPT_DIR/var/www/assets" ]; then
    rsync -av "$SCRIPT_DIR/var/www/assets/" "$WWW_BASE/assets/"
fi

chown -R www-data:www-data "$WWW_BASE/supervision" "$WWW_BASE/common" "$WWW_BASE/assets"
chmod -R 755 "$WWW_BASE/supervision" "$WWW_BASE/common" "$WWW_BASE/assets"

# 4.bis Configuration de l'authentification (Local / LDAP)
echo -e "\n${BLUE}====================================================${NC}"
echo -e "${BLUE}        CONFIGURATION DE L'AUTHENTIFICATION         ${NC}"
echo -e "${BLUE}====================================================${NC}"

echo -e "Choisissez le mode d'authentification pour NeoVision :"
echo "1) Compte local uniquement (Autonome)"
echo "2) Active Directory / LDAP (+ compte local de secours)"
read -rp "Choix [1/2] (défaut: 1) : " AUTH_CHOICE
AUTH_CHOICE=${AUTH_CHOICE:-1}

# Mot de passe administrateur local
echo -e "\n${YELLOW}[*] Définition du mot de passe pour l'administrateur local ('admin')${NC}"
while true; do
    read -rsp "Mot de passe admin : " ADMIN_PASS
    echo ""
    read -rsp "Confirmez le mot de passe : " ADMIN_PASS_CONFIRM
    echo ""
    if [ -n "$ADMIN_PASS" ] && [ "$ADMIN_PASS" = "$ADMIN_PASS_CONFIRM" ]; then
        break
    else
        echo -e "${RED}[ERREUR] Les mots de passe ne correspondent pas ou sont vides. Réessayez.${NC}"
    fi
done

# Génération du hash sécurisé bcrypt via PHP
ADMIN_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_BCRYPT);' "$ADMIN_PASS")

if [ "$AUTH_CHOICE" = "2" ]; then
    AUTH_MODE="ldap"
    echo -e "\n${YELLOW}[*] Configuration Active Directory / LDAP${NC}"
    read -rp "Serveur AD / Contrôleur de domaine (IP ou FQDN) : " LDAP_SERVER
    read -rp "Port LDAP (défaut: 389) : " LDAP_PORT
    LDAP_PORT=${LDAP_PORT:-389}
    read -rp "Nom de domaine AD (ex: mondomaine.local) : " LDAP_DOMAIN
    read -rp "Base DN (ex: DC=mondomaine,DC=local) : " LDAP_BASE_DN
    read -rp "Groupe AD autorisé (DN complet, ou laisser vide pour tous) : " LDAP_GROUP
else
    AUTH_MODE="local"
    LDAP_SERVER=""
    LDAP_PORT=389
    LDAP_DOMAIN=""
    LDAP_BASE_DN=""
    LDAP_GROUP=""
fi

# Écriture du fichier auth_config.json
AUTH_FILE="$WWW_BASE/common/auth_config.json"
cat <<EOF > "$AUTH_FILE"
{
    "auth_mode": "${AUTH_MODE}",
    "local_admin": {
        "enabled": true,
        "username": "admin",
        "password_hash": "${ADMIN_HASH}"
    },
    "ldap": {
        "server": "${LDAP_SERVER}",
        "port": ${LDAP_PORT},
        "domain": "${LDAP_DOMAIN}",
        "base_dn": "${LDAP_BASE_DN}",
        "user_group": "${LDAP_GROUP}"
    }
}
EOF

chown root:www-data "$AUTH_FILE"
chmod 640 "$AUTH_FILE"
echo -e "${GREEN}[OK] Fichier d'authentification généré : $AUTH_FILE${NC}"

# 5. Déploiement de /opt/supervision et sous-dossiers
echo -e "\n${BLUE}[*] Configuration de l'arborescence /opt/supervision...${NC}"
mkdir -p "$OPT_DIR"

if [ -d "$SCRIPT_DIR/opt/supervision" ]; then
    rsync -av --exclude '__pycache__' --exclude '*.pyc' --exclude 'venv' --exclude 'secret.key' \
        "$SCRIPT_DIR/opt/supervision/" "$OPT_DIR/"
fi

# Création des sous-dossiers requis
mkdir -p "$OPT_DIR/data/servers" "$OPT_DIR/data/wifi" "$OPT_DIR/data/others" "$OPT_DIR/data/switchs"
mkdir -p "$OPT_DIR/history/archives"
mkdir -p "$OPT_DIR/lib"
mkdir -p "$OPT_DIR/mailing"

# Initialisation des fichiers JSON d'état pour history si absents
[ ! -f "$OPT_DIR/history/state.json" ] && echo "{}" > "$OPT_DIR/history/state.json"
[ ! -f "$OPT_DIR/history/active_alerts.json" ] && echo "{}" > "$OPT_DIR/history/active_alerts.json"
[ ! -f "$OPT_DIR/history/alerts.csv" ] && touch "$OPT_DIR/history/alerts.csv"

# Droits généraux sur /opt/supervision
chown -R root:root "$OPT_DIR"
chmod 755 "$OPT_DIR"

# Droits spécifiques data (SetGID pour que www-data garde les droits d'écriture)
chown -R root:www-data "$OPT_DIR/data"
chmod -R 2775 "$OPT_DIR/data"

# Droits spécifiques history
chown -R root:www-data "$OPT_DIR/history"
chmod -R 775 "$OPT_DIR/history"

# Permissions d'exécution sur les scripts Python
find "$OPT_DIR" -maxdepth 1 -type f -name "*.py" -exec chmod 755 {} +
[ -f "$OPT_DIR/history/history.py" ] && chmod 755 "$OPT_DIR/history/history.py"

# 6. Création du lien symbolique /var/www/supervision/data -> /opt/supervision/data
echo -e "\n${BLUE}[*] Création du lien symbolique web pour data...${NC}"
if [ -d "$WWW_BASE/supervision/data" ] && [ ! -L "$WWW_BASE/supervision/data" ]; then
    rm -rf "$WWW_BASE/supervision/data"
fi
ln -sfn "$OPT_DIR/data" "$WWW_BASE/supervision/data"

# 7. Création de l'environnement Python virtuel (venv) et modules pip
echo -e "\n${BLUE}[*] Création du venv Python et installation des dépendances pip...${NC}"
if [ ! -d "$OPT_DIR/venv" ]; then
    python3 -m venv "$OPT_DIR/venv"
fi

"$OPT_DIR/venv/bin/pip" install --upgrade pip
"$OPT_DIR/venv/bin/pip" install \
    cryptography \
    requests \
    requests_ntlm \
    paramiko \
    pywinrm \
    bcrypt \
    xmltodict \
    pytz

# 8. Clé de chiffrement secret.key
if [ ! -f "$OPT_DIR/secret.key" ]; then
    echo -e "\n${BLUE}[*] Génération d'une nouvelle clé de chiffrement secret.key...${NC}"
    "$OPT_DIR/venv/bin/python3" -c "from cryptography.fernet import Fernet; print(Fernet.generate_key().decode())" > "$OPT_DIR/secret.key"
fi
chown root:www-data "$OPT_DIR/secret.key"
chmod 640 "$OPT_DIR/secret.key"

# 9. Création et droits des logs dans /var/log/supervision
echo -e "\n${BLUE}[*] Initialisation des logs dans $LOG_DIR...${NC}"
mkdir -p "$LOG_DIR"
chown root:root "$LOG_DIR"
chmod 755 "$LOG_DIR"

LOG_FILES=(
    "history.log"
    "mailing.log"
    "refresh_server.log"
    "update_disks.log"
    "update_lstatus.log"
    "update_ping.log"
    "update_ram.log"
    "update_status.log"
    "update_ubuntu.log"
    "update_uptime.log"
    "update_windows.log"
    "wakeonlan.log"
)

for lf in "${LOG_FILES[@]}"; do
    touch "$LOG_DIR/$lf"
    chown www-data:www-data "$LOG_DIR/$lf"
    chmod 664 "$LOG_DIR/$lf"
done

# 10. Configuration Sudoers pour www-data
echo -e "\n${BLUE}[*] Configuration des autorisations sudoers...${NC}"
cat << 'EOF' > "$SUDOERS_FILE"
# NeoVision permissions pour www-data
Defaults:www-data !requiretty

# Execution des scripts Python via le venv sans mot de passe
www-data ALL=(ALL) NOPASSWD: /opt/supervision/venv/bin/python3 /opt/supervision/*.py
www-data ALL=(ALL) NOPASSWD: /opt/supervision/venv/bin/python3 /opt/supervision/history/history.py
www-data ALL=(ALL) NOPASSWD: /opt/supervision/venv/bin/python3 /opt/supervision/clean_history.py

# Outils reseau
www-data ALL=(ALL) NOPASSWD: /usr/bin/wakeonlan
www-data ALL=(ALL) NOPASSWD: /usr/bin/fping
www-data ALL=(ALL) NOPASSWD: /usr/bin/sshpass
EOF

chmod 440 "$SUDOERS_FILE"
# Validation de la syntaxe sudoers
visudo -cf "$SUDOERS_FILE"

# 11. Configuration Apache (VirtualHost)
echo -e "\n${BLUE}[*] Configuration du VirtualHost Apache...${NC}"

# Certificat auto-signe si aucun certificat n'est present
SSL_CERT="/etc/ssl/certs/neovision.crt"
SSL_KEY="/etc/ssl/private/neovision.key"

if [ ! -f "$SSL_CERT" ]; then
    echo -e "${YELLOW}[*] Génération d'un certificat SSL auto-signé...${NC}"
    openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
        -keyout "$SSL_KEY" \
        -out "$SSL_CERT" \
        -subj "/C=FR/ST=Local/L=Local/O=NeoVision/CN=neovision.local"
fi

APACHE_CONF="/etc/apache2/sites-available/neovision.conf"
cat << EOF > "$APACHE_CONF"
<VirtualHost *:$PORT>
    ServerAdmin webmaster@localhost
    DocumentRoot /var/www/supervision

    SSLEngine on
    SSLCertificateFile $SSL_CERT
    SSLCertificateKeyFile $SSL_KEY

    <Directory /var/www/supervision>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <Directory /var/www/common>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <Directory /var/www/assets>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Alias pour common et assets
    Alias /common /var/www/common
    Alias /assets /var/www/assets

    ErrorLog \${APACHE_LOG_DIR}/neovision_error.log
    CustomLog \${APACHE_LOG_DIR}/neovision_access.log combined
</VirtualHost>
EOF

# Ajouter le port dans ports.conf s'il est different de 443
if [ "$PORT" != "443" ] && ! grep -q "Listen $PORT" /etc/apache2/ports.conf; then
    echo "Listen $PORT" >> /etc/apache2/ports.conf
fi

a2ensite neovision.conf
systemctl reload apache2

echo -e "\n${GREEN}====================================================${NC}"
echo -e "${GREEN}      INSTALLATION DE NEOVISION TERMINEE !          ${NC}"
echo -e "${GREEN} Accès interface : https://$(hostname -I | awk '{print $1}'):$PORT ${NC}"
echo -e "${GREEN}====================================================${NC}"
