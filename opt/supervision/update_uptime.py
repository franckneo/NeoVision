#!/opt/supervision/venv/bin/python
import json
import logging
import subprocess
import os
import warnings
warnings.filterwarnings("ignore", category=UserWarning, module="winrm")
import winrm
from datetime import datetime
from logging.handlers import RotatingFileHandler
from lib.servers import get_servers, get_default_local_user
from lib.retry import run_with_retry
from lib.crypto import decrypt_password
from filelock import FileLock, Timeout
LOG_FILE = "/var/log/supervision/update_uptime.log"
LOCK_FILE = "/opt/supervision/data/update_uptime.lock"
logger = logging.getLogger()
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


def format_uptime(seconds):
    days = seconds // 86400
    hours = (seconds % 86400) // 3600
    minutes = (seconds % 3600) // 60
    return f"{days} days, {hours} hours, {minutes} minutes"


def get_linux_uptime(ip, user):
    try:
        r = subprocess.run(
            [
                "ssh",
                "-o", "StrictHostKeyChecking=accept-new",
                f"{user}@{ip}",
                "cat /proc/uptime"
            ],
            capture_output=True,
            text=True,
            timeout=10
        )
        if r.returncode != 0:
            logging.error(f"[LINUX][{ip}] SSH error: {r.stderr.strip()}")
            return None
        uptime_seconds = int(float(r.stdout.split()[0]))
        return {
            "seconds": uptime_seconds,
            "days": uptime_seconds // 86400
        }
    except Exception:
        logging.exception(f"[LINUX][{ip}] uptime failed")
        return None

def get_windows_uptime(ip, user, password):
    if not password:
        return None
    try:
        session = winrm.Session(
            ip,
            auth=(user, password),
            transport="ntlm",
            read_timeout_sec=60,
            operation_timeout_sec=50
        )
        ps = r'''
$code = '[DllImport("kernel32.dll")] public static extern ulong GetTickCount64();'
$kernel32 = Add-Type -MemberDefinition $code -Name "Kernel32Util" -Namespace "Kernel32" -PassThru
$ms = $kernel32::GetTickCount64()
$sec = [math]::Floor($ms / 1000)
Write-Output $sec
'''
        r = session.run_ps(ps)
        if r.status_code != 0:
            logging.error(f"[WIN][{ip}] WinRM error: {r.std_err.decode(errors='ignore').strip()}")
            return None

        output = r.std_out.decode(errors="ignore").strip()
        if not output:
            logging.error(f"[WIN][{ip}] empty output")
            return None

        lines = [line.strip() for line in output.splitlines() if line.strip()]
        if not lines:
            logging.error(f"[WIN][{ip}] no valid lines in output")
            return None

        uptime_seconds = int(float(lines[-1].replace(",", ".")))
        return {
            "seconds": uptime_seconds,
            "days": uptime_seconds // 86400
        }
    except Exception:
        logging.exception(f"[WIN][{ip}] uptime failed")
        return None

def main():
    logging.info("===== START update_uptime =====")
    servers = get_servers()
    for s in servers:
        name = s.get("name")
        ip = s.get("ip")
        srv_type = s.get("type")
        user = s.get("user")
        file_out = f"/opt/supervision/data/{name}_uptime.json"
        data = {
            "timestamp": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
            "uptime": "Injoignable",
            "uptime_days": None
        }
        result = None
        if srv_type == "linux":
            result = run_with_retry(
                get_linux_uptime, 2, 2, ip, user if user else get_default_local_user()
            )
        elif srv_type == "windows":
            pwd = decrypt_password(s.get("enc_password"))
            result = run_with_retry(
                get_windows_uptime, 2, 2, ip, user, pwd
            )
        else:
            logging.warning(f"{name}: unknown type {srv_type}")
        if result:
            data["uptime"] = format_uptime(result["seconds"])
            data["uptime_days"] = result["days"]
        try:
            tmp_file = file_out + ".tmp"
            with open(tmp_file, "w") as f:
                json.dump(data, f, indent=2)
            os.replace(tmp_file, file_out)
        except Exception:
            logging.exception(f"{name}: write failed")
    logging.info("===== END update_uptime =====")

if __name__ == "__main__":
    try:
        lock = FileLock(LOCK_FILE, timeout=5)
        with lock:
            main()
    except Timeout:
        logging.warning("update_uptime already running")
