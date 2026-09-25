#!/opt/supervision/venv/bin/python
import json
import os
import logging
import subprocess
from datetime import datetime
from concurrent.futures import ThreadPoolExecutor, as_completed
from filelock import FileLock, Timeout
from logging.handlers import RotatingFileHandler
from lib.servers import get_servers, get_default_local_user
DATA_DIR = "/opt/supervision/data"
CONFIG_FILE = f"{DATA_DIR}/alert_config.json"
LOCK_FILE = f"{DATA_DIR}/update_ubuntu.lock"
LOG_FILE = "/var/log/supervision/update_ubuntu.log"
logger = logging.getLogger()
logger.setLevel(logging.INFO)
handler = RotatingFileHandler(LOG_FILE, maxBytes=5 * 1024 * 1024, backupCount=1)
handler.setFormatter(logging.Formatter("%(asctime)s [%(levelname)s] %(message)s"))
logger.addHandler(handler)
def check_systemd_service(ip, user, service_name):
    try:
        check_exists = subprocess.run(
            [
                "ssh",
                "-o", "StrictHostKeyChecking=accept-new",
                "-o", "ConnectTimeout=5",
                f"{user}@{ip}",
                f"systemctl list-unit-files {service_name}.service"
            ],
            capture_output=True,
            text=True,
            timeout=8
        )
        if service_name not in check_exists.stdout:
            return None
        r = subprocess.run(
            [
                "ssh",
                "-o", "StrictHostKeyChecking=accept-new",
                "-o", "ConnectTimeout=5",
                f"{user}@{ip}",
                f"systemctl is-active {service_name}"
            ],
            capture_output=True,
            text=True,
            timeout=8
        )
        status = r.stdout.strip()
        return status if status else "inactive"
    except Exception:
        logging.exception(f"[{ip}] Check service {service_name} failed")
        return "error"
def check_tcp_port(ip, user, port):
    try:
        command = (
            f"timeout 5 bash -c "
            f"\"cat < /dev/null > /dev/tcp/127.0.0.1/{port}\""
        )
        result = subprocess.run(
            [
                "ssh",
                "-o", "StrictHostKeyChecking=accept-new",
                "-o", "ConnectTimeout=5",
                f"{user}@{ip}",
                command
            ],
            capture_output=True,
            text=True,
            timeout=8
        )

        return "active" if result.returncode == 0 else "inactive"
    except Exception:
        logging.exception(f"[{ip}] Check TCP port {port} failed")
        return "error"

def process_server(server, alert_config):
    name = server.get("name")
    ip = server.get("ip")
    user = server.get("user") or get_default_local_user()
    config = alert_config.get(name, {})
    results = {}
    if config.get("kea_dhcp_enabled", False):
        status = check_systemd_service(
            ip,
            user,
            "kea-dhcp4-server"
        )
        if status is not None:
            results["kea_dhcp"] = status
    if config.get("squid_enabled", False):
        ssh_status = check_tcp_port(ip, user, 22)
        squid_status = check_tcp_port(ip, user, 80)
        results["squid"] = {
            "status": (
                "active"
                if ssh_status == "active" and squid_status == "active"
                else "inactive"
            ),
            "ports": {
                "22": ssh_status,
                "80": squid_status
            }
        }
    if config.get("webmin_enabled", False):
        webmin_status = check_tcp_port(ip, user, 10000)
        results["webmin"] = {
            "status": webmin_status,
            "port": 10000
        }
    data = {
        "timestamp": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        "ip": ip,
        "services": results
    }
    out_file = f"{DATA_DIR}/{name}_services.json"
    tmp_file = f"{out_file}.tmp"

    try:
        with open(tmp_file, "w") as f:
            json.dump(data, f, indent=2)

        os.replace(tmp_file, out_file)
    except Exception:
        logging.exception(f"[{name}] Write JSON failed")

def main():
    logging.info("========== START update_ubuntu ==========")
    servers = get_servers()
    try:
        with open(CONFIG_FILE, "r") as f:
            alert_config = json.load(f)
    except Exception:
        logging.exception("Impossible de lire alert_config.json")
        alert_config = {}
    linux_servers = [
        s for s in servers
        if s.get("type") == "linux"
    ]
    with ThreadPoolExecutor(max_workers=10) as executor:
        futures = [
            executor.submit(process_server, s, alert_config)
            for s in linux_servers
        ]
        for future in as_completed(futures):
            try:
                future.result()
            except Exception:
                logging.exception("Worker exception")
    logging.info("========== END update_ubuntu ==========")

if __name__ == "__main__":
    try:
        lock = FileLock(LOCK_FILE, timeout=5)
        with lock:
            main()
    except Timeout:
        logging.warning("update_ubuntu already running")
