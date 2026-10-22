#!/usr/bin/env bash
set -eo pipefail
PUBKEY="/var/lib/neovision/.ssh/id_ed25519.pub"
IP_RE='^([0-9]{1,3}\.){3}[0-9]{1,3}$'
USER_RE='^[a-zA-Z_][a-zA-Z0-9_.-]{0,31}$'
TARGET_IP="${1:-}"
TARGET_USER="${2:-}"

if ! [[ "$TARGET_IP" =~ $IP_RE ]]; then
    echo "Adresse IP invalide."; exit 2
fi
if ! [[ "$TARGET_USER" =~ $USER_RE ]]; then
    echo "Nom d'utilisateur invalide."; exit 2
fi
if [ ! -f "$PUBKEY" ]; then
    echo "Clé publique introuvable : $PUBKEY"; exit 3
fi

read -r PASSWORD
if [ -z "$PASSWORD" ]; then
    echo "Mot de passe manquant."; exit 2
fi

export SSHPASS="$PASSWORD"
unset PASSWORD
sshpass -e ssh-copy-id -i "$PUBKEY" \
    -o StrictHostKeyChecking=no \
    -o ConnectTimeout=10 \
    "${TARGET_USER}@${TARGET_IP}"
