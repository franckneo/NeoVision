#!/usr/bin/env python3
import subprocess
import json
import os
import logging
import time
from lib.servers import get_servers, get_default_local_user
import warnings
warnings.filterwarnings("ignore", category=UserWarning, module="winrm")
from datetime import datetime
from logging.handlers import RotatingFileHandler
from lib.crypto import decrypt_password
from lib.retry import run_with_retry
from filelock import FileLock, Timeout

LOCK_FILE = "/opt/supervision/data/update_ram.lock"
LOG_FILE = "/var/log/supervision/update_ram.log"
HISTORY_SIZE = 192

logger = logging.getLogger()
logger.setLevel(logging.INFO)
handler = RotatingFileHandler(
    LOG_FILE,
    maxBytes=5 * 1024 * 1024,
    backupCount=1
)
handler.setFormatter(
    logging.Formatter(
        "%(asctime)s [%(levelname)s] %(message)s"
    )
)
logger.addHandler(handler)

def get_linux_memory_usage(ip, user):
    start = time.time()
    try:
        cmd = [
            "ssh",
            "-o", "StrictHostKeyChecking=accept-new",
            f"{user}@{ip}",
            "free -m | awk 'NR==2{printf \"%.2f\", $3*100/$2}'"
        ]
        r = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            timeout=10
        )
        if r.returncode != 0:
            logging.error(
                f"[{ip}] SSH error: {r.stderr.strip()}"
            )
            return -1.0
        val_str = r.stdout.strip().replace(",", ".")
        return float(val_str)
    except Exception:
        logging.exception(
            f"Linux memory usage failed for {ip}"
        )
        return -1.0
    finally:
        logging.info(
            f"[{ip}] linux ram in {time.time()-start:.2f}s"
        )

def get_windows_memory_usage(ip, user, password):
    if not password:
        return -1.0
    try:
        import winrm
        session = winrm.Session(
            ip,
            auth=(user, password),
            transport="ntlm",
            read_timeout_sec=30,
            operation_timeout_sec=20
        )
        ps = """
$m = Get-CimInstance Win32_OperatingSystem
$used = [math]::Round((($m.TotalVisibleMemorySize-$m.FreePhysicalMemory)/$m.TotalVisibleMemorySize)*100,2)
Write-Output $used
"""
        r = session.run_ps(ps)
        out = r.std_out.decode(
            errors="ignore"
        ).strip()
        if r.status_code != 0:
            logging.error(
                f"[{ip}] WinRM error {r.status_code}: "
                f"{r.std_err.decode(errors='ignore')}"
            )
            return -1.0
        if not out:
            logging.error(
                f"[{ip}] WinRM empty output"
            )
            return -1.0
        try:
            return float(
                out.replace(",", ".")
                   .replace("%", "")
            )
        except Exception:
            logging.error(
                f"[{ip}] invalid float output: '{out}'"
            )
            return -1.0
    except Exception:
        logging.exception(
            f"Windows memory usage failed for {ip}"
        )
        return -1.0

def main():
    logging.info(
        "========== START update_ram =========="
    )
    servers = get_servers()
    for s in servers:
        name = s.get("name")
        ip = s.get("ip")
        s_type = s.get("type")
        path = (
            f"/opt/supervision/data/"
            f"{name}_ram.json"
        )
        history = []
        if os.path.exists(path):
            try:
                with open(path, "r") as f:
                    history = json.load(f)
            except Exception:
                logging.exception(
                    f"Failed reading {path}"
                )
                history = []
        try:
            if s_type == "linux":
                val = run_with_retry(
                    get_linux_memory_usage,
                    2,
                    2,
                    ip,
                    s.get("user") or get_default_local_user()
                )
            elif s_type == "windows":
                pwd = decrypt_password(
                    s.get("enc_password")
                )
                val = run_with_retry(
                    get_windows_memory_usage,
                    2,
                    2,
                    ip,
                    s.get("user"),
                    pwd
                )
            else:
                val = -1.0
        except Exception:
            logging.exception(
                f"RAM collection failed {name}"
            )
            val = -1.0
        if val > 0:
            history.insert(0, {
                "timestamp": datetime.now()
                .strftime("%Y-%m-%d %H:%M:%S"),
                "percent": val
            })
            history = history[:HISTORY_SIZE]
        try:
            tmp = path + ".tmp"
            with open(tmp, "w") as f:
                json.dump(
                    history,
                    f,
                    indent=2
                )
            os.replace(tmp, path)
        except Exception:
            logging.exception(
                f"write failed {name}"
            )
    logging.info(
        "========== END update_ram =========="
    )

if __name__ == "__main__":
    try:
        lock = FileLock(LOCK_FILE, timeout=5)
        with lock:
            main()
    except Timeout:
        logging.warning("update_ram already running")
