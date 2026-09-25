#!/opt/supervision/venv/bin/python

import json
import os
import logging
import winrm

from datetime import datetime
from concurrent.futures import ThreadPoolExecutor, as_completed
from filelock import FileLock, Timeout
from logging.handlers import RotatingFileHandler

from lib.servers import get_servers
from lib.crypto import decrypt_password


DATA_DIR = "/opt/supervision/data"
CONFIG_FILE = f"{DATA_DIR}/alert_config.json"
LOCK_FILE = f"{DATA_DIR}/update_windows.lock"
LOG_FILE = "/var/log/supervision/update_windows.log"

logger = logging.getLogger("update_windows")
logger.setLevel(logging.INFO)

handler = RotatingFileHandler(
    LOG_FILE,
    maxBytes=5 * 1024 * 1024,
    backupCount=1
)
handler.setFormatter(
    logging.Formatter("%(asctime)s [%(levelname)s] %(message)s")
)
logger.addHandler(handler)


def load_alert_config():
    try:
        with open(CONFIG_FILE, "r") as f:
            return json.load(f)
    except Exception:
        logger.exception("Impossible de lire alert_config.json")
        return {}

def check_process_status(session, process_name):
    try:
        ps = f'''
$process = Get-Process -Name "{process_name}" -ErrorAction SilentlyContinue
if ($process) {{ "running" }} else {{ "stopped" }}
'''
        result = session.run_ps(ps)
        output = result.std_out.decode("utf-8", errors="ignore")
        status = output.strip().splitlines()[-1].strip().lower()

        if result.status_code != 0:
            return "error"

        return status if status in ("running", "stopped") else "error"

    except Exception as e:
        logger.exception(f"Erreur contrôle {process_name}: {e}")
        return "error"

def check_wsus_services(session):
    """Vérifie le service WSUS, l'AppPool IIS et le port Web 8530."""
    try:
        ps = r'''
$wsusSvc = (Get-Service -Name "WSUSService" -ErrorAction SilentlyContinue).Status
$appPool = "N/A"
if (Get-Module -ListAvailable -Name WebAdministration) {
    Import-Module WebAdministration -ErrorAction SilentlyContinue
    $appPool = (Get-WebAppPoolState -Name "WsusPool" -ErrorAction SilentlyContinue).Value
}
$portOpen = (Test-NetConnection -ComputerName "localhost" -Port 8530 -InformationLevel Quiet)

@{
    wsus_service = if ($wsusSvc -eq 'Running') { 'running' } else { 'stopped' }
    wsus_apppool = if ($appPool -eq 'Started') { 'running' } else { 'stopped' }
    wsus_port    = if ($portOpen) { 'active' } else { 'inactive' }
} | ConvertTo-Json
'''
        result = session.run_ps(ps)
        if result.status_code == 0 and result.std_out:
            return json.loads(result.std_out.decode(errors="ignore"))
        return {}
    except Exception as e:
        logger.warning(f"Échec check WSUS: {e}")
        return {}


def process_server(server, alert_config):
    name = server.get("name")
    ip = server.get("ip")
    user = server.get("user")

    try:
        server_config = alert_config.get(name, {})
        results = {}
        password = decrypt_password(server.get("enc_password")) if server.get("enc_password") else None

        if password:
            session = winrm.Session(
                ip,
                auth=(user, password),
                transport="ntlm",
                read_timeout_sec=15,
                operation_timeout_sec=10
            )

            # Power BI Gateway
            if server_config.get("powerbi_enabled", False):
                results["powerbi_gateway"] = check_process_status(session, "Microsoft.PowerBI.DataMovement.PersonalGateway")

            # Outlook
            if server_config.get("outlook_enabled", False):
                results["outlook"] = check_process_status(session, "outlook")

            # StageNow
            if server_config.get("stagenow_enabled", False):
                results["stagenow"] = check_process_status(session, "Symbol.StageNow.V2Client")

            # WSUS (Exécuté si activé dans la config OU si "wsus" est dans le nom du serveur)
            if server_config.get("wsus_enabled", False) or "wsus" in name.lower():
                wsus_data = check_wsus_services(session)
                if wsus_data:
                    results.update(wsus_data)

        data = {
            "timestamp": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
            "ip": ip,
            "services": results
        }

        output_file = f"{DATA_DIR}/{name}_services.json"
        temp_file = f"{output_file}.tmp"

        with open(temp_file, "w") as f:
            json.dump(data, f, indent=2)

        os.replace(temp_file, output_file)

    except Exception:
        logger.exception(f"[{name}] Échec traitement Windows")


def main():
    logger.info("========== START update_windows ==========")

    servers = get_servers()
    alert_config = load_alert_config()

    windows_servers = [
        s for s in servers
        if s.get("type") == "windows"
    ]

    with ThreadPoolExecutor(max_workers=10) as executor:
        futures = [
            executor.submit(
                process_server,
                server,
                alert_config
            )
            for server in windows_servers
        ]

        for future in as_completed(futures):
            try:
                future.result()
            except Exception:
                logger.exception("Erreur worker")

    logger.info("========== END update_windows ==========")


if __name__ == "__main__":
    try:
        with FileLock(LOCK_FILE, timeout=5):
            main()
    except Timeout:
        logger.warning("update_windows déjà en cours")
