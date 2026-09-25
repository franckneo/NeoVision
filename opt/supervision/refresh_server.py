#!/opt/supervision/venv/bin/python
import sys
import os
import json
import logging
import subprocess
from datetime import datetime
import winrm
from lib.servers import get_servers, get_default_local_user
from lib.crypto import decrypt_password
from lib.retry import run_with_retry
DATA_DIR = "/opt/supervision/data"
LOG_FILE = "/var/log/supervision/refresh_server.log"
CONFIG_FILE = f"{DATA_DIR}/alert_config.json"
logging.basicConfig(filename=LOG_FILE, level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s")

def format_uptime(seconds):
    days = seconds // 86400
    hours = (seconds % 86400) // 3600
    minutes = (seconds % 3600) // 60
    return f"{days} days, {hours} hours, {minutes} minutes"

def write_json(path, data):
    temp = f"{path}.tmp"
    with open(temp, "w") as f:
        json.dump(data, f, indent=2)
    os.replace(temp, path)

def get_ssh_cmd(user, ip, command):
    return ["ssh", "-o", "StrictHostKeyChecking=accept-new", f"{user}@{ip}", command]

def get_winrm_session(ip, user, password):
    if not password:
        raise RuntimeError("Mot de passe Windows absent")
    return winrm.Session(ip, auth=(user, password), transport="ntlm", read_timeout_sec=30, operation_timeout_sec=20)

def winrm_output(result):
    return result.std_out.decode("utf-8-sig", errors="ignore").strip()

def parse_size_to_gb(value):
    if not value:
        return 0
    value = value.strip().upper()
    units = {"K": 1 / 1024 / 1024, "M": 1 / 1024, "G": 1, "T": 1024}
    number = ""
    unit = "G"
    for char in value:
        if char.isdigit() or char == ".":
            number += char
        else:
            unit = char
            break
    try:
        return float(number) * units.get(unit, 1)
    except Exception:
        return 0

def get_linux_disks(ip, user):
    result = subprocess.run(get_ssh_cmd(user, ip, "df -BG --output=source,size,pcent | tail -n +2"), capture_output=True, text=True, timeout=10)
    if result.returncode != 0:
        return {"error": "ssh_failed"}
    disks = {}
    for line in result.stdout.strip().splitlines():
        parts = line.split()
        if len(parts) != 3:
            continue
        device, size, percent = parts
        if device.startswith("/dev/") and parse_size_to_gb(size) >= 7:
            disks[device] = {"total": size, "used_percent": percent}
    return disks

def get_windows_disks(ip, user, password):
    session = get_winrm_session(ip, user, password)
    result = session.run_ps("Get-CimInstance Win32_LogicalDisk | Where-Object {$_.DriveType -eq 3} | ForEach-Object { $used=[math]::Round((($_.Size-$_.FreeSpace)/$_.Size)*100); Write-Output \"$($_.DeviceID),$used,$([math]::Round($_.Size/1GB))\" }")
    if result.status_code != 0:
        return {"error": "winrm_failed"}
    disks = {}
    for line in winrm_output(result).splitlines():
        parts = line.split(",")
        if len(parts) == 3:
            disks[parts[0]] = {"used_percent": f"{parts[1]}%", "total": f"{parts[2]}GB"}
    return disks

def get_linux_ram(ip, user):
    result = subprocess.run(get_ssh_cmd(user, ip, "free -m | awk 'NR==2{printf \"%.2f\", $3*100/$2}'"), capture_output=True, text=True, timeout=10)
    if result.returncode != 0:
        return -1.0
    try:
        return float(result.stdout.strip().replace(",", "."))
    except Exception:
        return -1.0

def get_windows_ram(ip, user, password):
    session = get_winrm_session(ip, user, password)
    result = session.run_ps("$m=Get-CimInstance Win32_OperatingSystem; $used=[math]::Round((($m.TotalVisibleMemorySize-$m.FreePhysicalMemory)/$m.TotalVisibleMemorySize)*100,2); Write-Output $used")
    output = winrm_output(result)
    if result.status_code != 0 or not output:
        return -1.0
    try:
        return float(output.replace(",", ".").replace("%", ""))
    except Exception:
        return -1.0

def get_linux_status(ip, user):
    reboot = subprocess.run(get_ssh_cmd(user, ip, "test -d /run/needrestart && echo true || echo false"), capture_output=True, text=True, timeout=10).stdout.strip() == "true"
    result = subprocess.run(get_ssh_cmd(user, ip, "apt list --upgradable 2>/dev/null | tail -n +2 | wc -l"), capture_output=True, text=True, timeout=30)
    try:
        updates = int(result.stdout.strip())
    except Exception:
        updates = 0
    return {"updates_pending": updates, "reboot_required": reboot}

def get_windows_status(ip, user, password):
    session = get_winrm_session(ip, user, password)
    result = session.run_ps("if (Test-Path 'C:\\Scripts\\update_status.json') { Get-Content -Raw 'C:\\Scripts\\update_status.json' } else { Write-Output 'null' }")
    output = winrm_output(result)
    if result.status_code != 0 or not output or output == "null":
        return {"error": "windows_no_data"}
    try:
        data = json.loads(output)
        return {"updates_pending": int(data.get("updates_pending", 0)), "reboot_required": bool(data.get("reboot_required", False))}
    except Exception as error:
        return {"error": f"json_parse_error: {error}"}

def get_linux_uptime(ip, user):
    result = subprocess.run(get_ssh_cmd(user, ip, "cat /proc/uptime"), capture_output=True, text=True, timeout=10)
    if result.returncode != 0:
        return {"error": "ssh_failed"}
    try:
        seconds = int(float(result.stdout.split()[0]))
        return {"seconds": seconds, "days": seconds // 86400, "hours": (seconds % 86400) // 3600, "minutes": (seconds % 3600) // 60}
    except Exception:
        return {"error": "parse_failed"}

def get_windows_uptime(ip, user, password):
    session = get_winrm_session(ip, user, password)
    result = session.run_ps("$os=Get-CimInstance Win32_OperatingSystem; $seconds=[int]((Get-Date)-$os.LastBootUpTime).TotalSeconds; Write-Output $seconds")
    output = winrm_output(result)
    if result.status_code != 0 or not output:
        return {"error": "winrm_failed"}
    try:
        seconds = int(output)
        return {"seconds": seconds, "days": seconds // 86400, "hours": (seconds % 86400) // 3600, "minutes": (seconds % 3600) // 60}
    except Exception:
        return {"error": "parse_failed"}

def check_linux_service(ip, user, service):
    exists = subprocess.run(get_ssh_cmd(user, ip, f"systemctl list-unit-files {service}.service"), capture_output=True, text=True, timeout=8)
    if service not in exists.stdout:
        return None
    result = subprocess.run(get_ssh_cmd(user, ip, f"systemctl is-active {service}"), capture_output=True, text=True, timeout=8)
    return result.stdout.strip() or "inactive"

def process_linux_services(ip, user, config):
    services = {}
    service_map = {
        "squid": "squid",
        "webmin": "webmin",
        "kea_dhcp": "kea-dhcp4-server",
    }
    for config_key, service_name in service_map.items():
        if not config.get(f"{config_key}_enabled", False):
            continue
        status = check_linux_service(ip, user, service_name)
        if status is not None:
            services[config_key] = {
                "status": status
            }
    ports = {}
    for port in (22, 80):
        result = subprocess.run(
            get_ssh_cmd(
                user,
                ip,
                f"timeout 3 bash -c '</dev/tcp/127.0.0.1/{port}'"
            ),
            capture_output=True,
            text=True,
            timeout=8
        )
        ports[str(port)] = (
            "active" if result.returncode == 0 else "inactive"
        )
    if "squid" in services:
        services["squid"]["ports"] = ports
    return services

def check_windows_process(session, process_name):
    result = session.run_ps(f'$process=Get-Process -Name "{process_name}" -ErrorAction SilentlyContinue; if ($process) {{ "running" }} else {{ "stopped" }}')
    if result.status_code != 0:
        return "error"
    status = winrm_output(result).lower()
    return status if status in ("running", "stopped") else "error"

def check_windows_wsus(session):
    script = r'''$wsusSvc=(Get-Service -Name "WSUSService" -ErrorAction SilentlyContinue).Status;$appPool="N/A";if (Get-Module -ListAvailable -Name WebAdministration) { Import-Module WebAdministration -ErrorAction SilentlyContinue;$appPool=(Get-WebAppPoolState -Name "WsusPool" -ErrorAction SilentlyContinue).Value };$portOpen=Test-NetConnection -ComputerName localhost -Port 8530 -InformationLevel Quiet;@{wsus_service=if ($wsusSvc -eq "Running") {"running"} else {"stopped"};wsus_apppool=if ($appPool -eq "Started") {"running"} else {"stopped"};wsus_port=if ($portOpen) {"active"} else {"inactive"}}|ConvertTo-Json'''
    result = session.run_ps(script)
    if result.status_code != 0 or not winrm_output(result):
        return {}
    try:
        return json.loads(winrm_output(result))
    except Exception:
        return {}

def load_alert_config():
    try:
        with open(CONFIG_FILE, "r") as f:
            return json.load(f)
    except Exception:
        return {}

def process_windows_services(ip, user, password, server_name):
    session = get_winrm_session(ip, user, password)
    config = load_alert_config().get(server_name, {})
    services = {}
    checks = {"powerbi_enabled": ("powerbi_gateway", "Microsoft.PowerBI.DataMovement.PersonalGateway"), "outlook_enabled": ("outlook", "outlook"), "stagenow_enabled": ("stagenow", "Symbol.StageNow.V2Client")}
    for enabled, (key, process) in checks.items():
        if config.get(enabled, False):
            services[key] = check_windows_process(session, process)
    if config.get("wsus_enabled", False) or "wsus" in server_name.lower():
        services.update(check_windows_wsus(session))
    return services

def save_disks(target_name, timestamp, disks):
    path = f"{DATA_DIR}/{target_name}_disks.json"
    history = []
    if os.path.exists(path):
        try:
            with open(path, "r") as f:
                history = json.load(f)
        except Exception:
            history = []
    entry = {"timestamp": timestamp, "disks": disks}
    if history and history[0].get("timestamp", "").split()[0] == timestamp.split()[0]:
        history[0] = entry
    else:
        history.insert(0, entry)
    write_json(path, history[:90])

def save_ram(target_name, timestamp, percent):
    if percent <= 0:
        return
    path = f"{DATA_DIR}/{target_name}_ram.json"
    history = []
    if os.path.exists(path):
        try:
            with open(path, "r") as f:
                history = json.load(f)
        except Exception:
            history = []
    history.insert(0, {"timestamp": timestamp, "percent": percent})
    write_json(path, history[:192])

def main():
    if len(sys.argv) < 2:
        print("Erreur: serveur manquant")
        sys.exit(1)
    target_name = sys.argv[1]
    logging.info(f"========== REFRESH ON DEMAND FOR {target_name} ==========")
    server = next((item for item in get_servers() if item.get("name") == target_name), None)
    if not server:
        logging.error(f"Serveur {target_name} introuvable")
        print("Serveur introuvable")
        sys.exit(1)
    default_user = get_default_local_user()
    ip = server.get("ip")
    server_type = server.get("type")
    user = server.get("user") or default_user
    password = decrypt_password(server.get("enc_password")) if server_type == "windows" and server.get("enc_password") else None
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    try:
        disks = run_with_retry(get_linux_disks, 2, 2, ip, user) if server_type == "linux" else run_with_retry(get_windows_disks, 2, 2, ip, user, password)
        save_disks(target_name, timestamp, disks)
    except Exception:
        logging.exception("Erreur refresh disques")
    try:
        ram = run_with_retry(get_linux_ram, 2, 2, ip, user) if server_type == "linux" else run_with_retry(get_windows_ram, 2, 2, ip, user, password)
        save_ram(target_name, timestamp, ram)
    except Exception:
        logging.exception("Erreur refresh RAM")
    try:
        uptime = run_with_retry(get_linux_uptime, 2, 2, ip, user) if server_type == "linux" else run_with_retry(get_windows_uptime, 2, 2, ip, user, password)
        uptime_data = {"timestamp": timestamp, "uptime": "Injoignable", "uptime_days": None}
        if uptime and "seconds" in uptime:
            uptime_data["uptime"] = format_uptime(uptime["seconds"])
            uptime_data["uptime_days"] = uptime["seconds"] // 86400
        write_json(f"{DATA_DIR}/{target_name}_uptime.json", uptime_data)
    except Exception:
        logging.exception("Erreur refresh uptime")
    status = {"updates_pending": 0, "reboot_required": False}
    try:
        result = run_with_retry(get_linux_status, 2, 2, ip, user) if server_type == "linux" else run_with_retry(get_windows_status, 2, 2, ip, user, password)
        if isinstance(result, dict) and "error" not in result:
            status = result
        write_json(f"{DATA_DIR}/{target_name}_status.json", {"timestamp": timestamp, "status": status})
        logging.info(f"Status mis à jour pour {target_name}: {status}")
    except Exception:
        logging.exception("Erreur refresh status")
    try:
        alert_config = load_alert_config().get(target_name, {})
        services = (
            process_linux_services(ip, user, alert_config)
            if server_type == "linux"
            else process_windows_services(ip, user, password, target_name)
        )
        services_file = f"{DATA_DIR}/{target_name}_services.json"
        if services:
            write_json(
                services_file,
                {
                    "timestamp": timestamp,
                    "ip": ip,
                    "services": services
                }
            )
            logging.info(f"Services mis à jour pour {target_name}: {services}")
        else:
            logging.warning(
                f"Aucun service détecté pour {target_name} : ancien fichier conservé"
            )
    except Exception:
        logging.exception("Erreur refresh services")
    logging.info(f"========== REFRESH COMPLETED FOR {target_name} ==========")
    print("OK")
if __name__ == "__main__":
    main()
