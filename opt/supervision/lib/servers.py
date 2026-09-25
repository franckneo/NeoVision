import os
import json
import logging
from lib.config import SERVERS_CONFIG_PATH, ACCOUNTS_CONFIG_PATH

def get_default_local_user():
    try:
        if os.path.exists(ACCOUNTS_CONFIG_PATH):
            with open(ACCOUNTS_CONFIG_PATH, "r") as f:
                data = json.load(f)
                return data.get("local_user", "root")
    except Exception:
        logging.exception("accounts.json load failed")
    return "root"

def get_servers():
    try:
        with open(SERVERS_CONFIG_PATH, "r") as f:
            return json.load(f)
    except Exception:
        logging.exception("servers.json load failed")
        return []
