#!/usr/bin/env bash
set -euo pipefail
if [ "$(id -u)" -ne 0 ]; then
    echo -e "\033[0;31m[ERREUR]\033[0m Ce script doit être exécuté en tant que root."
    exit 1
fi

echo -e "\033[0;33m====================================================\033[0m"
echo -e "\033[0;33m          Désinstallation de NeoVision             \033[0m"
echo -e "\033[0;33m====================================================\033[0m"
read -rp "Êtes-vous sûr de vouloir supprimer NeoVision et ses données ? (o/N) : " CONFIRM
if [[ ! "$CONFIRM" =~ ^[oOyY]$ ]]; then
    echo "Désinstallation annulée."
    exit 0
fi

echo -e "\n\033[0;34m[*] Nettoyage Apache...\033[0m"
if [ -f "/etc/apache2/sites-available/neovision.conf" ]; then
    a2dissite neovision.conf >/dev/null 2>&1 || true
    rm -f /etc/apache2/sites-available/neovision.conf
fi
rm -rf /etc/ssl/neovision

rm -f /etc/sudoers.d/neovision

echo -e "\033[0;34m[*] Suppression des fichiers applicatifs et logs...\033[0m"
rm -rf /opt/supervision
rm -rf /var/www/supervision
rm -rf /var/www/common
rm -rf /var/www/assets
rm -rf /var/log/supervision

STATE_FILE="/etc/neovision-installed-packages.list"
if [ -f "$STATE_FILE" ]; then
    PACKAGES=()
    while IFS= read -r line; do
        [[ -n "$line" ]] && PACKAGES+=("$line")
    done < "$STATE_FILE"
    if [ ${#PACKAGES[@]} -gt 0 ]; then
        echo -e "\n\033[0;34m[*] Les paquets suivants ont été installés par NeoVision : ${PACKAGES[*]}\033[0m"
        read -rp "Voulez-vous désinstaller ces paquets système ? (o/N) : " REMOVE_PKGS
        if [[ "$REMOVE_PKGS" =~ ^[oOyY]$ ]]; then
            apt-get remove --purge -y "${PACKAGES[@]}"
            apt-get autoremove -y
        fi
    fi
    rm -f "$STATE_FILE"
fi

systemctl restart apache2 || true

echo -e "\n\033[0;32m[OK] NeoVision a été entièrement désinstallé du système.\033[0m"
