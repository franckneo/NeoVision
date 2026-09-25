#!/opt/supervision/venv/bin/python
import json
import subprocess
import os
import re
import time
from datetime import datetime, timezone
from concurrent.futures import ThreadPoolExecutor
INPUT_FILE = "/opt/supervision/data/wifi.json"
OUTPUT_FILE = "/opt/supervision/data/network_aps.json"
STATE_FILE = "/opt/supervision/data/network_aps_state.json"

def utc_now_iso():
    return datetime.now(timezone.utc).isoformat()

def ping_once(ip):
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
            return "up", round(latency, 1)
        return "down", None
    except Exception:
        return "down", None

def ping_ip_with_retry(ip, retry_delay_sec=2):
    status, latency = ping_once(ip)
    if status == "up":
        return status, latency
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

def process_device(site_name, dev, dev_state):
    name = dev.get("name")
    ip = dev.get("ip")
    now_formatted = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    status, latency = ping_ip_with_retry(ip, retry_delay_sec=2)
    now = utc_now_iso()
    failures_count = int(dev_state.get("failures_count", 0))
    down_since = dev_state.get("down_since")
    if status == "up":
        failures_count = 0
        down_since = None
        last_status = "up"
    else:
        failures_count += 1
        if not down_since:
            down_since = now
        last_status = "down"
    new_dev_state = {
        "failures_count": failures_count,
        "down_since": down_since,
        "last_status": last_status
    }
    down_duration_sec = None
    if down_since:
        try:
            t0 = datetime.fromisoformat(down_since).timestamp()
            t1 = datetime.fromisoformat(now).timestamp()
            down_duration_sec = int(max(0, t1 - t0))
        except Exception:
            down_duration_sec = 0

    device_result = {
        "name": name,
        "ip": ip,
        "status": status,
        "latency_ms": latency,
        "down_since": down_since,
        "down_duration_sec": down_duration_sec,
        "failures_count": failures_count,
        "updated_at": now_formatted
    }

    return site_name, name, device_result, new_dev_state

def main():
    if not os.path.exists(INPUT_FILE):
        print(f"Erreur : fichier source {INPUT_FILE} introuvable.")
        return

    try:
        with open(INPUT_FILE, "r", encoding="utf-8") as f:
            sites_data = json.load(f)
    except Exception as e:
        print(f"Erreur lecture input JSON: {e}")
        return
    state = load_state()
    tasks = []
    for site, devices in sites_data.items():
        for dev in devices:
            dev_name = dev.get("name")
            dev_st = state.get(dev_name, {})
            tasks.append((site, dev, dev_st))
    results_by_site = {site: [] for site in sites_data.keys()}
    new_state = {}
    with ThreadPoolExecutor(max_workers=20) as executor:
        futures = [executor.submit(process_device, site, dev, dev_st) for site, dev, dev_st in tasks]
        for f in futures:
            res = f.result()
            if res:
                site_name, dev_name, dev_res, dev_st = res
                if site_name not in results_by_site:
                    results_by_site[site_name] = []
                results_by_site[site_name].append(dev_res)
                new_state[dev_name] = dev_st
    save_json_atomic(STATE_FILE, new_state)
    results = {
        "timestamp": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        "sites": results_by_site
    }
    save_json_atomic(OUTPUT_FILE, results)
    print(f"Mise à jour terminée à {results['timestamp']}")

if __name__ == "__main__":
    main()
