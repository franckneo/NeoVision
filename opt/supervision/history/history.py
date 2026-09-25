#!/usr/bin/env python3
import csv
import json
import os
import subprocess
import tempfile
import time
from datetime import datetime
from pathlib import Path
BASE = Path("/opt/supervision")
DATA = BASE / "data"
HISTORY = BASE / "history"
CONFIG_FILE = DATA / "alert_config.json"
NETWORK_CONFIG_FILE = DATA / "network_alert_config.json"
SERVERS_FILE = DATA / "servers.json"
ACTIVE_FILE = HISTORY / "active_alerts.json"
STATE_FILE = HISTORY / "state.json"
CSV_FILE = HISTORY / "alerts.csv"
LOCK_FILE = HISTORY / "history.lock"
CSV_FIELDS = ["debut", "fin", "duree", "equipement", "type", "probleme", "valeur", "seuil", "ip", "details"]
TIME_FORMAT = "%Y-%m-%d %H:%M:%S"
LOG_FILE = Path("/var/log/supervision/history.log")
def history_log(message):
    try:
        LOG_FILE.parent.mkdir(parents=True, exist_ok=True)
        timestamp = datetime.now().astimezone().strftime(TIME_FORMAT)
        with LOG_FILE.open("a", encoding="utf-8") as f:
            f.write(f"[{timestamp}] {message}\n")
    except Exception:
        pass
def atomic_json_write(path, data):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp = tempfile.mkstemp(prefix=f".{path.name}.", dir=str(path.parent))
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as f:
            json.dump(data, f, ensure_ascii=False, indent=2)
            f.write("\n")
        os.chmod(tmp, 0o664)
        os.replace(tmp, path)
        try:
            os.chown(path, -1, os.stat(str(path.parent)).st_gid)
        except Exception:
            pass
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)
def load_json(path, default):
    try:
        with path.open("r", encoding="utf-8") as f:
            return json.load(f)
    except Exception:
        return default
def ensure_files():
    HISTORY.mkdir(parents=True, exist_ok=True)
    if not ACTIVE_FILE.exists(): atomic_json_write(ACTIVE_FILE, {})
    if not STATE_FILE.exists(): atomic_json_write(STATE_FILE, {})
    if not CSV_FILE.exists():
        tmp = CSV_FILE.with_suffix(".tmp")
        with tmp.open("w", encoding="utf-8", newline="") as f:
            writer = csv.DictWriter(f, fieldnames=CSV_FIELDS, delimiter=";")
            writer.writeheader()
        os.chmod(tmp, 0o640)
        os.replace(tmp, CSV_FILE)
def acquire_lock():
    try:
        fd = os.open(str(LOCK_FILE), os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o640)
        os.write(fd, str(os.getpid()).encode())
        return fd
    except FileExistsError:
        try:
            if time.time() - LOCK_FILE.stat().st_mtime > 600:
                LOCK_FILE.unlink()
                fd = os.open(str(LOCK_FILE), os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o640)
                os.write(fd, str(os.getpid()).encode())
                return fd
        except Exception:
            pass
        return None
def release_lock(fd):
    if fd is not None:
        try: os.close(fd)
        except Exception: pass
        try: LOCK_FILE.unlink()
        except FileNotFoundError: pass
def now(): return datetime.now().astimezone().strftime(TIME_FORMAT)
def parse_time(value): return datetime.strptime(value, TIME_FORMAT)
def format_duration(start, end):
    seconds = max(0, int((parse_time(end) - parse_time(start)).total_seconds()))
    return f"{seconds // 3600:02d}:{(seconds % 3600) // 60:02d}:{seconds % 60:02d}"
def append_csv(row):
    with CSV_FILE.open("a", encoding="utf-8", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=CSV_FIELDS, delimiter=";", extrasaction="ignore")
        writer.writerow({k: row.get(k, "") for k in CSV_FIELDS})
        f.flush()
        os.fsync(f.fileno())
def first_number(value):
    if isinstance(value, (int, float)): return float(value)
    if isinstance(value, str):
        try: return float(value.replace("%", "").replace(",", ".").strip())
        except ValueError: return None
    return None
def recursive_find_number(obj, keys):
    if isinstance(obj, dict):
        for key in keys:
            if key in obj:
                number = first_number(obj[key])
                if number is not None: return number
        for value in obj.values():
            result = recursive_find_number(value, keys)
            if result is not None: return result
    elif isinstance(obj, list):
        for value in obj:
            result = recursive_find_number(value, keys)
            if result is not None: return result
    return None
def recursive_find_value(obj, keys):
    if isinstance(obj, dict):
        for key in keys:
            if key in obj: return obj[key]
        for value in obj.values():
            result = recursive_find_value(value, keys)
            if result is not None: return result
    elif isinstance(obj, list):
        for value in obj:
            result = recursive_find_value(value, keys)
            if result is not None: return result
    return None
def get_ip(obj):
    value = recursive_find_value(obj, ["ip", "IP", "address", "host", "hostname"])
    return str(value).strip() if value is not None else ""
def add_event(events, key, equipement, alert_type, problem, value="", threshold="", ip="", details=""):
    events[key] = {"equipement": str(equipement), "type": str(alert_type), "probleme": str(problem), "valeur": "" if value is None else str(value), "seuil": "" if threshold is None else str(threshold), "ip": str(ip or ""), "details": str(details or "")}
def process_events(events, active):
    current_time = now()
    for key, event in events.items():
        if key not in active: active[key] = {**event, "debut": current_time}
    closed = []
    for key, incident in active.items():
        if key in events: continue
        append_csv({**incident, "fin": current_time, "duree": format_duration(incident["debut"], current_time)})
        closed.append(key)
    for key in closed: del active[key]
    return active
def read_servers():
    data = load_json(SERVERS_FILE, [])
    return data if isinstance(data, list) else []
def read_server_config():
    data = load_json(CONFIG_FILE, {})
    return data if isinstance(data, dict) else {}
def detect_servers(events, servers, config):
    for server in servers:
        name = str(server.get("name", "")).strip()
        if not name: continue
        cfg = config.get(name, {})
        if not isinstance(cfg, dict): continue
        ip = str(server.get("ip", "")).strip()
        pdata = load_json(DATA / f"{name}_ping.json", {})
        losses = int(pdata.get("consecutive_losses", 0) or 0)
        max_loss = int(cfg.get("max_loss", 1) or 0)
        if losses > max_loss:
            add_event(events, f"server|{name}|ping", name, "ping", "Serveur hors ligne", losses, max_loss, ip, f"{losses} pertes consécutives")
        disks_cfg = cfg.get("disks", {})
        disk_path = DATA / f"{name}_disks.json"
        if isinstance(disks_cfg, dict) and disk_path.exists():
            disk_data = load_json(disk_path, [])
            latest_disks = (disk_data[0] if isinstance(disk_data, list) and disk_data else {}).get("disks", {})
            for disk_name, disk_cfg in disks_cfg.items():
                if not isinstance(disk_cfg, dict): continue
                warn_threshold = int(disk_cfg.get("warn_threshold", 80) or 80)
                crit_threshold = int(disk_cfg.get("crit_threshold", 90) or 90)
                disk_info = (latest_disks or {}).get(disk_name, {})
                used = first_number(disk_info.get("used_percent") if isinstance(disk_info, dict) else disk_info)
                if used is not None:
                    is_crit = used >= crit_threshold
                    is_warn = used >= warn_threshold
                    if is_crit or is_warn:
                        level = "CRITICAL" if is_crit else "WARNING"
                        threshold_used = crit_threshold if is_crit else warn_threshold
                        add_event(events, f"server|{name}|disk|{disk_name}", name, "disk", f"Espace disque ({level}) au-dessus du seuil", f"{used:g}%", f"{threshold_used}%", ip, f"Disque {disk_name}")

        if cfg.get("ram_enabled", False):
            threshold = int(cfg.get("ram_threshold", 90) or 90)
            ram_data = load_json(DATA / f"{name}_ram.json", [])
            used = recursive_find_number(ram_data[0] if isinstance(ram_data, list) and ram_data else ram_data, ["percent", "ram_percent", "used_percent", "usage_percent"])
            if used is not None and used >= threshold:
                add_event(events, f"server|{name}|ram", name, "ram", "Utilisation mémoire au-dessus du seuil", f"{used:g}%", f"{threshold}%", ip, "")
        if cfg.get("uptime_enabled", False):
            threshold = int(cfg.get("uptime_threshold", 30) or 30)
            days = recursive_find_number(load_json(DATA / f"{name}_uptime.json", {}), ["uptime_days", "days"])
            if days is not None and days >= threshold:
                add_event(events, f"server|{name}|uptime", name, "uptime", "Uptime au-dessus du seuil", f"{days:g} jours", f"{threshold} jours", ip, "")
        status = load_json(DATA / f"{name}_status.json", {})
        status = status.get("status", status) if isinstance(status, dict) else {}
        if cfg.get("update_enabled", False):
            updates = first_number(status.get("updates_pending"))
            if updates is not None and updates > 0:
                add_event(events, f"server|{name}|updates", name, "update", "Mises à jour disponibles", f"{updates:g}", "0", ip, "")
        if cfg.get("reboot_enabled", False):
            reboot = status.get("reboot_required")
            if reboot is True or str(reboot).lower() == "true":
                add_event(events, f"server|{name}|reboot", name, "reboot", "Redémarrage requis", "oui", "non", ip, "")
        service_values = load_json(DATA / f"{name}_services.json", {}).get("services", {})
        service_configs = [
            {"keys": ["powerbi_gateway", "powerbi"], "label": "Power BI Gateway", "config_key": "powerbi_enabled"},
            {"keys": ["wsus_service", "wsus"], "label": "WSUS", "config_key": "wsus_enabled"},
            {"keys": ["outlook"], "label": "Outlook", "config_key": "outlook_enabled"},
            {"keys": ["stagenow"], "label": "StageNow", "config_key": "stagenow_enabled"},
            {"keys": ["squid"], "label": "Squid", "config_key": "squid_enabled"},
            {"keys": ["kea_dhcp", "dhcp"], "label": "KEA DHCP", "config_key": "dhcp_enabled"},
            {"keys": ["webmin"], "label": "Webmin", "config_key": "webmin_enabled"}
        ]
        if isinstance(service_values, dict):
            for item in service_configs:
                if item.get("config_key") and not cfg.get(item["config_key"], False):
                    continue
                raw = None
                found_key = None
                for k in item["keys"]:
                    if k in service_values:
                        raw = service_values[k]
                        found_key = k
                        break
                if found_key is None:
                    continue
                if isinstance(raw, dict):
                    raw = raw.get("status", "unknown")
                if str(raw).strip().lower() not in {"running", "active", "ok", "started"}:
                    service_id = item["keys"][0]
                    add_event(
                        events,
                        f"server|{name}|service|{service_id}",
                        name,
                        "service",
                        f"{item['label']} ne fonctionne pas normalement",
                        raw,
                        "running",
                        ip,
                        f"Service : {item['label']}"
                    )
def read_network_config():
    data = load_json(NETWORK_CONFIG_FILE, {})
    return data if isinstance(data, dict) else {}
def read_network_inventory(section):
    for path in [DATA / f"network_{section}.json", DATA / f"{section}.json"]:
        if path.exists():
            data = load_json(path, {})
            if isinstance(data, dict): return data
    return {}
def network_ip(inventory, name):
    if not isinstance(inventory, dict): return ""
    target_clean = str(name).strip().lower()
    def walk(obj):
        if isinstance(obj, dict):
            obj_name = str(obj.get("name", "")).strip().lower()
            if obj_name and (obj_name == target_clean or target_clean in obj_name or obj_name in target_clean):
                ip = get_ip(obj)
                if ip: return ip
            for value in obj.values():
                res = walk(value)
                if res: return res
        elif isinstance(obj, list):
            for item in obj:
                res = walk(item)
                if res: return res
        return ""
    return walk(inventory)
def ping_host(ip):
    if not ip: return None
    try:
        return subprocess.run(["ping", "-c", "1", "-W", "1", ip], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=3).returncode == 0
    except Exception:
        return None
def detect_network(events, state, network_config):
    if not isinstance(network_config, dict):
        history_log("[DEBUG] network_config n'est pas un dict")
        return
    for section in ("switchs", "aps", "others"):
        section_cfg = network_config.get(section, {})
        if not isinstance(section_cfg, dict): continue
        inventory = read_network_inventory(section)
        history_log(f"[DEBUG] Analyse de la section réseau : {section} (inventaire chargé)")
        for name, cfg in section_cfg.items():
            if not isinstance(cfg, dict): continue
            if not cfg.get("enabled", False) or not cfg.get("ping_enabled", False):
                continue
            ip = network_ip(inventory, name)
            if not ip:
                history_log(f"[WARNING] IP introuvable pour l'équipement réseau : {name} dans {section}")
                continue
            state_key = f"network|{section}|{name}|ping"
            ping_state = state.setdefault(state_key, {"losses": 0})
            ok = ping_host(ip)
            history_log(f"[DEBUG] Ping de {name} ({ip}) -> Résultat : {ok}")
            if ok is None: continue
            ping_state["losses"] = 0 if ok else int(ping_state.get("losses", 0)) + 1
            max_loss = int(cfg.get("max_loss", 1) or 0)
            if ping_state["losses"] > max_loss:
                history_log(f"[ALERTE] Équipement réseau DOWN détecté : {name} ({ip}), pertes : {ping_state['losses']}")
                add_event(events, state_key, name, "network", "Équipement réseau inaccessible", ping_state["losses"], max_loss, ip, f"{ping_state['losses']} pertes consécutives")

def main():
    ensure_files()
    lock_fd = acquire_lock()
    if lock_fd is None: return 0
    try:
        config, servers, network_config = read_server_config(), read_servers(), read_network_config()
        active, state = load_json(ACTIVE_FILE, {}), load_json(STATE_FILE, {})
        events = {}
        detect_servers(events, servers, config)
        detect_network(events, state, network_config)
        atomic_json_write(ACTIVE_FILE, process_events(events, active if isinstance(active, dict) else {}))
        atomic_json_write(STATE_FILE, state if isinstance(state, dict) else {})
        return 0
    finally:
        release_lock(lock_fd)

if __name__ == "__main__":
    raise SystemExit(main())
