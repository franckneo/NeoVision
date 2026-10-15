import json
import logging
from lib.config import SERVERS_CONFIG_PATH
ACCOUNTS_CONFIG_PATH="/opt/supervision/data/accounts.json"
def get_servers():
    try:
        with open(SERVERS_CONFIG_PATH,"r") as f:
            data=json.load(f)
            return data.get("servers",[]) if isinstance(data,dict) else (data if isinstance(data,list) else [])
    except Exception:
        logging.exception("servers.json load failed")
        return []
def get_default_local_user():
    try:
        with open(ACCOUNTS_CONFIG_PATH,"r") as f:
            return json.load(f).get("local_user","")
    except Exception:
        return ""
