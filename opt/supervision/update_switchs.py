#!/opt/supervision/venv/bin/python
import json
import subprocess
import os
import re
import time
from datetime import datetime, timezone
from concurrent.futures import ThreadPoolExecutor

INPUT_FILE = "/opt/supervision/data/switchs.json"
CONFIG_FILE = "/opt/supervision/data/network_alert_config.json"
OUTPUT_FILE = "/opt/supervision/data/network_switchs.json"
STATE_FILE = "/opt/supervision/data/network_switchs_state.json"

def utc_now_iso():
    return datetime.now(timezone.utc).isoformat()

def load_alert_config():
    if not os.path.exists(CONFIG_FILE):
        return {}
    try:
        with open(CONFIG_FILE, "r", encoding="utf-8") as f:
            return json.load(f) or {}
    except Exception:
        return {}

def ping_once(ip):
    """Effectue un unique ping de contrôle."""
    try:
        res = subprocess.run(
            ["ping", "-c", "1", "-W", "2", ip],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True
        )
        if res.returncode == 0:
            match = re.search(r"time=([\d.]+)", res.stdout)
            latency = float(match.group(1)) if match else 0.0
            return True, round(latency, 1)
        return False, None
    except Exception:
        return False, None

def ping_ip_with_retry(ip, retry_delay_sec=2):
    """
    Tente un ping. S'il échoue, attend retry_delay_sec et retente une 2ème fois
    afin d'éviter les faux positifs dus à un paquet isolé perdu.
    """
    is_up, latency = ping_once(ip)
    if is_up:
        return True, latency
    
    time.sleep(retry_delay_sec)
    return ping_once(ip)

def load_state():
    if not os.path.exists(STATE_FILE):
        return {}
    try:
        with open(STATE_FILE, "r", encoding="utf-8") as f:
            return json.load(f) or {}
    except Exception:
        return {}

def save_json_atomic(file_path, data):
    tmp = file_path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(data, f, indent=2, ensure_ascii=False)
    os.replace(tmp, file_path)

def process_equipment(item, eq_state, alert_config):
    site_name = item.get("_site", "Autres")
    eq = item.get("eq", {})
    now_formatted = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    name = eq.get("name") or eq.get("hostname") or "Inconnu"
    ip = eq.get("ip") or eq.get("host") or ""
    cfg = alert_config.get("switchs", {}).get(name, {})
    max_loss_allowed = int(cfg.get("max_loss", eq.get("max_loss_allowed", 0)))
    
    failures_count = int(eq_state.get("failures_count", 0))
    down_since = eq_state.get("down_since", None)

    if not ip:
        new_state = {"failures_count": failures_count, "down_since": down_since}
        return site_name, {
            "name": name,
            "ip": ip,
            "status": "down",
            "failures_count": failures_count,
            "max_loss_allowed": max_loss_allowed,
            "latency_ms": None,
            "down_since": down_since,
            "down_duration_sec": 0,
            "updated_at": now_formatted
        }, name, new_state

    is_up, latency = ping_ip_with_retry(ip, retry_delay_sec=2)
    now_iso = utc_now_iso()

    if is_up:
        failures_count = 0
        down_since = None
        status = "up"
        down_duration_sec = 0
    else:
        failures_count += 1
        if not down_since:
            down_since = now_iso
        try:
            start_dt = datetime.fromisoformat(down_since)
            down_duration_sec = int((datetime.now(timezone.utc) - start_dt).total_seconds())
        except Exception:
            down_duration_sec = 0
        status = "down"

    new_state = {
        "failures_count": failures_count,
        "down_since": down_since
    }

    return site_name, {
        "name": name,
        "ip": ip,
        "status": status,
        "failures_count": failures_count,
        "max_loss_allowed": max_loss_allowed,
        "latency_ms": latency,
        "down_since": down_since,
        "down_duration_sec": down_duration_sec,
        "updated_at": now_formatted
    }, name, new_state

def main():
    if not os.path.exists(INPUT_FILE):
        print(f"Fichier introuvable: {INPUT_FILE}")
        return
    try:
        with open(INPUT_FILE, "r", encoding="utf-8") as f:
            data = json.load(f)
    except Exception as e:
        print(f"Erreur lecture input JSON: {e}")
        return

    alert_config = load_alert_config()
    state = load_state()

    equipments_to_process = []
    if isinstance(data, list):
        for eq in data:
            equipments_to_process.append({"_site": "Autres", "eq": eq})
    elif isinstance(data, dict):
        root = data.get("sites") or data.get("switchs") or data
        for key, val in root.items():
            if isinstance(val, list):
                for eq in val:
                    equipments_to_process.append({"_site": key, "eq": eq})
            elif isinstance(val, dict):
                equipments_to_process.append({"_site": "Autres", "eq": val})

    results_by_site = {}
    new_state = {}

    with ThreadPoolExecutor(max_workers=20) as executor:
        futures = []
        for item in equipments_to_process:
            eq_name = item.get("eq", {}).get("name") or item.get("eq", {}).get("hostname") or "Inconnu"
            eq_st = state.get(eq_name, {})
            futures.append(executor.submit(process_equipment, item, eq_st, alert_config))

        for f in futures:
            res = f.result()
            if res:
                site_name, eq_data, eq_name, eq_st = res
                if site_name not in results_by_site:
                    results_by_site[site_name] = []
                results_by_site[site_name].append(eq_data)
                new_state[eq_name] = eq_st

    save_json_atomic(STATE_FILE, new_state)

    output_data = {
        "timestamp": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        "sites": results_by_site
    }

    try:
        save_json_atomic(OUTPUT_FILE, output_data)
        print(f"Mise à jour réussie ({len(equipments_to_process)} équipements traités).")
    except Exception as e:
        print(f"Erreur écriture fichier sortie: {e}")

if __name__ == "__main__":
    main()
