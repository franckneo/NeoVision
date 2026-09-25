#!/usr/bin/env python3
import json
import subprocess
import os
import time
import logging
from datetime import datetime
from concurrent.futures import ThreadPoolExecutor, as_completed
from filelock import FileLock, Timeout
SERVERS_FILE = "/opt/supervision/data/servers.json"
CONFIG_FILE = "/opt/supervision/data/alert_config.json"
DATA_DIR = "/opt/supervision/data"
LOCK_FILE = f"{DATA_DIR}/update_ping.lock"
LOG_FILE = "/var/log/supervision/update_ping.log"
MAX_WORKERS = 20
logging.basicConfig(
    filename=LOG_FILE,
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s"
)

def load_json(path, default):
    try:
        with open(path, "r") as f:
            return json.load(f)
    except Exception as e:
        logging.warning(f"Unable to load {path}: {e}")
        return default

def load_servers():
    return load_json(SERVERS_FILE, [])

def load_config():
    return load_json(CONFIG_FILE, {})

def atomic_write_json(path, data):
    tmp = f"{path}.tmp"
    with open(tmp, "w") as f:
        json.dump(
            data,
            f,
            indent=2,
            ensure_ascii=False
        )
    os.replace(tmp, path)

def ping_once(ip):
    try:
        result = subprocess.run(
            ["ping", "-c", "1", "-W", "2", ip],
            capture_output=True,
            timeout=5
        )
        return result.returncode == 0
    except Exception:
        return False

def ping_ip_with_confirmation(ip, retry_delay_sec=2):
    if ping_once(ip):
        return True
    time.sleep(retry_delay_sec)
    if ping_once(ip):
        return True
    return False

def ping_server(server, config):
    name = server.get("name")
    ip = server.get("ip")
    if not name or not ip:
        logging.warning(f"Invalid server entry: {server}")
        return
    server_config = config.get(name, {})
    try:
        max_loss = int(server_config.get("max_loss", 1))
    except (TypeError, ValueError):
        max_loss = 1
    max_loss = max(1, max_loss)
    path = f"{DATA_DIR}/{name}_ping.json"
    previous = load_json(path, {})
    previous_losses = previous.get("consecutive_losses", 0)
    try:
        measurement_ok = ping_ip_with_confirmation(
            ip,
            retry_delay_sec=2
        )
    except Exception as e:
        measurement_ok = False
        logging.error(
            f"Ping script exception for {name} ({ip}): {e}"
        )
    if measurement_ok:
        consecutive_losses = 0
        status = "online"
    else:
        consecutive_losses = int(previous_losses) + 1
        status = "offline"

    critical = consecutive_losses > max_loss

    data = {
        "timestamp": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        "status": status,
        "ping_ok": measurement_ok,
        "consecutive_losses": consecutive_losses,
        "max_loss": max_loss,
        "critical": critical
    }
    atomic_write_json(path, data)
    logging.info(
        f"{name} ({ip}) => "
        f"{status}, "
        f"losses={consecutive_losses}/{max_loss}, "
        f"critical={critical}"
    )


def main():
    servers = load_servers()
    config = load_config()
    logging.info(
        f"Starting ping check for {len(servers)} servers"
    )
    with ThreadPoolExecutor(
        max_workers=MAX_WORKERS
    ) as executor:
        futures = [
            executor.submit(
                ping_server,
                server,
                config
            )
            for server in servers
        ]
        for future in as_completed(futures):
            try:
                future.result()
            except Exception as e:
                logging.error(
                    f"Thread error: {e}"
                )

if __name__ == "__main__":
    try:
        lock = FileLock(
            LOCK_FILE,
            timeout=5
        )
        with lock:
            main()
    except Timeout:
        logging.warning(
            "update_ping already running "
            "(lock timeout)"
        )
