import json
import logging
import os
from lib.config import SERVERS_CONFIG_PATH
SSH_DIR = "/var/lib/neovision/.ssh"
SSH_KEY = f"{SSH_DIR}/id_ed25519"
SSH_KNOWN_HOSTS = f"{SSH_DIR}/known_hosts"

def get_servers():
    try:
        with open(SERVERS_CONFIG_PATH, "r") as f:
            data = json.load(f)
            return data.get("servers", []) if isinstance(data, dict) else (data if isinstance(data, list) else [])
    except Exception:
        logging.exception("servers.json load failed")
        return []

def get_local_ssh_key():
    return SSH_KEY if os.path.isfile(SSH_KEY) else ""

def get_ssh_base_options():
    return [
        "-o", "BatchMode=yes",
        "-o", "StrictHostKeyChecking=accept-new",
        "-o", f"UserKnownHostsFile={SSH_KNOWN_HOSTS}",
        "-o", "ConnectTimeout=10",
        "-i", SSH_KEY,
    ]
