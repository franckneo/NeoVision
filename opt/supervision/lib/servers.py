import json
import logging
from lib.config import SERVERS_CONFIG_PATH

def get_servers():
    try:
        with open(SERVERS_CONFIG_PATH, "r") as f:
            data = json.load(f)
            if isinstance(data, dict):
                return data.get("servers", [])
            elif isinstance(data, list):
                return data
            return []
    except Exception:
        logging.exception("servers.json load failed")
        return []
