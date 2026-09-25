#!/usr/bin/env python3
import subprocess
import json
import os
import logging
import time
from datetime import datetime
from logging.handlers import RotatingFileHandler
from lib.servers import get_servers, get_default_local_user
from filelock import FileLock, Timeout
from lib.crypto import decrypt_password
from lib.retry import run_with_retry
LOG_FILE = "/var/log/supervision/update_disks.log"
LOCK_FILE = "/opt/supervision/data/update_disks.lock"
HISTORY_SIZE = 90
logger = logging.getLogger()
logger.setLevel(logging.INFO)
handler = RotatingFileHandler( LOG_FILE, maxBytes=5 * 1024 * 1024, backupCount=1)
handler.setFormatter(logging.Formatter("%(asctime)s [%(levelname)s] %(message)s"))
logger.addHandler(handler)

def parse_size_to_gb(size_str):
    if not size_str:
        return 0
    size_str = size_str.strip().upper()
    units = {"K": 1 / 1024 / 1024, "M": 1 / 1024, "G": 1, "T": 1024}
    num = ""
    unit = "G"
    for c in size_str:
        if c.isdigit() or c == ".":
            num += c
        else:
            unit = c
            break
    try:
        return float(num) * units.get(unit, 1)
    except Exception:
        logging.exception(f"Invalid size string: {size_str}")
        return 0

def get_linux_disk_and_os(ip, user):
    start = time.time()
    try:
        cmd = [
            "ssh",
            "-o", "StrictHostKeyChecking=accept-new",
            "-o", "BatchMode=yes",
            "-o", "ConnectTimeout=5",
            f"{user}@{ip}",
            "cat /etc/os-release | grep '^PRETTY_NAME=' | cut -d= -f2 | tr -d '\"'; echo '---'; df -BG --output=source,size,pcent | tail -n +2"
        ]
        r = subprocess.run( cmd, capture_output=True, text=True, timeout=10)
        if r.returncode != 0:
            logging.error(f"[{ip}] SSH error: {r.stderr.strip()}")
            return {"error": "ssh_failed"}, ""
        parts = r.stdout.strip().split("---")
        os_name = parts[0].strip() if len(parts) > 1 else ""
        disk_output = parts[1].strip() if len(parts) > 1 else parts[0].strip()
        disks = {}
        for line in disk_output.splitlines():
            cols = line.split()
            if len(cols) != 3:
                continue
            name, size, percent = cols
            if not name.startswith("/dev/"):
                continue
            size_gb = parse_size_to_gb(size)
            if size_gb < 7:
                continue
            disks[name] = { "total": size, "used_percent": percent }
        return disks, os_name
    except Exception as e:
        logging.exception(f"Linux disk error {ip}")
        return {"error": str(e)}, ""
    finally:
        logging.info(f"[{ip}] linux disk in {time.time() - start:.2f}s")

def get_windows_disk_and_os(ip, user, password):
    if not password:
        return {"error": "no_password"}, ""
    try:
        import winrm
        session = winrm.Session( ip, auth=(user, password), transport="ntlm", read_timeout_sec=30, operation_timeout_sec=20)
        ps = """
$os = (Get-CimInstance Win32_OperatingSystem).Caption
Write-Output "OS:$os"
Get-CimInstance Win32_LogicalDisk |
Where-Object {$_.DriveType -eq 3} |
ForEach-Object {
    $used=[math]::Round((($_.Size-$_.FreeSpace)/$_.Size)*100)
    Write-Output "$($_.DeviceID),$used,$([math]::Round($_.Size/1GB))"
}
"""
        r = session.run_ps(ps)
        if r.status_code != 0:
            logging.error(
                f"[{ip}] WinRM error: "
                f"{r.std_err.decode(errors='ignore')}"
            )
            return {"error": "winrm_failed"}, ""
        os_name = ""
        disks = {}
        for line in r.std_out.decode(errors="ignore").splitlines():
            line = line.strip()
            if line.startswith("OS:"):
                os_name = line[3:].strip()
                continue
            cols = line.split(",")
            if len(cols) != 3:
                continue
            disks[cols[0]] = {
                "used_percent": cols[1] + "%",
                "total": cols[2] + "GB"
            }
        return disks, os_name
    except Exception as e:
        logging.exception(f"Windows disk error {ip}")
        return {"error": str(e)}, ""

def main():
    logging.info("========== START update_disks ==========")
    servers = get_servers()
    now_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    for s in servers:
        name = s.get("name")
        ip = s.get("ip")
        s_type = s.get("type")
        logging.info(f"--- {name} ({s_type}) [{ip}] ---")
        disks_path = f"/opt/supervision/data/{name}_disks.json"
        os_path = f"/opt/supervision/data/{name}_os.json"
        history = []
        if os.path.exists(disks_path):
            try:
                with open(disks_path, "r") as f:
                    history = json.load(f)
            except Exception:
                logging.exception(f"Failed reading {disks_path}")
                history = []
        try:
            if s_type == "linux":
                disks_res, os_name = run_with_retry( get_linux_disk_and_os, 2, 2, ip, s.get("user") or get_default_local_user())
            elif s_type == "windows":
                pwd = decrypt_password( s.get("enc_password"))
                disks_res, os_name = run_with_retry( get_windows_disk_and_os, 2, 2, ip, s.get("user"), pwd)
            else:
                disks_res, os_name = {"error": "unknown_type"}, ""
        except Exception:
            logging.exception(f"disk failure {name}")
            disks_res, os_name = {"error": "critical"}, ""
        entry = {
            "timestamp": now_str,
            "disks": disks_res
        }
        if (
            history
            and history[0]["timestamp"].split()[0]
            == entry["timestamp"].split()[0]
        ):
            history[0] = entry
        else:
            history.insert(0, entry)
        history = history[:HISTORY_SIZE]
        try:
            tmp_path = disks_path + ".tmp"
            with open(tmp_path, "w") as f:
                json.dump(history, f, indent=2)
            os.replace(tmp_path, disks_path)
        except Exception:
            logging.exception(f"write error {disks_path}")
        if os_name:
            try:
                tmp_os_path = os_path + ".tmp"
                with open(tmp_os_path, "w") as f:
                    json.dump({"os": os_name, "timestamp": now_str}, f, indent=2)
                os.replace(tmp_os_path, os_path)
            except Exception:
                logging.exception(f"write error {os_path}")
    logging.info("========== END update_disks ==========")

if __name__ == "__main__":
    try:
        lock = FileLock(LOCK_FILE, timeout=5)
        with lock:
            main()
    except Timeout:
        logging.warning("update_disks already running")
