#!/usr/bin/env python3
import json
import re
import os
import shutil
import unicodedata
from pathlib import Path
from datetime import datetime, timedelta
SOURCE = Path("/mnt/pcupdate")
USERS_JSON = Path("/opt/supervision/data/pc/users.json")
LOG_FILE = Path("/var/log/collect_uptime.log")
MAX_AGE_MINUTES = 30
NOW = datetime.now()
NOW_STR = NOW.strftime("%Y-%m-%d %H:%M:%S")
def log(message):
    with LOG_FILE.open("a", encoding="utf-8") as f:
        f.write(f"[{NOW_STR}] {message}\n")
def normalize_key(value):
    value = value.replace("\ufeff", "").strip().casefold()
    return "".join(
        char for char in unicodedata.normalize("NFD", value)
        if unicodedata.category(char) != "Mn"
    )
def extract(text, label):
    wanted = normalize_key(label)
    for line in text.splitlines():
        if ":" not in line:
            continue
        key, value = line.split(":", 1)
        if normalize_key(key) == wanted:
            return value.strip()
    return ""
def parse_date(value):
    try:
        return datetime.strptime(value, "%Y-%m-%d %H:%M:%S")
    except ValueError:
        return None
all_pcs = {}
if USERS_JSON.exists():
    try:
        with USERS_JSON.open("r", encoding="utf-8") as f:
            all_pcs = json.load(f)
    except Exception as e:
        log(f"Erreur lecture users.json : {e}")
for pc, data in all_pcs.items():
    data.setdefault("user", "")
    data.setdefault("site", "")
    data.setdefault("detail", "")
    data.setdefault("mac", "")
    data.setdefault("ip", "")
    data.setdefault("uptime", "")
    data.setdefault("status", "NO_RESPONSE")
    data.setdefault("last_success", "")
    data.setdefault("last_offline", "")
    data.setdefault("updates", 0)
    data.setdefault("oldest_date", None)
    data.setdefault("update_days", None)
for txt_file in SOURCE.glob("*.txt"):
    try:
        text = txt_file.read_text(encoding="utf-8-sig", errors="replace")
        computer = extract(text, "Ordinateur")
        if not computer:
            computer = txt_file.stem
        computer = computer.strip().upper()
        if computer not in all_pcs:
            all_pcs[computer] = {
                "user": "",
                "site": "",
                "detail": "",
                "mac": "",
                "ip": "",
                "uptime": "",
                "status": "OFFLINE",
                "last_success": "",
                "last_offline": NOW_STR,
                "updates": 0,
                "oldest_date": None,
                "update_days": None
            }
        item = all_pcs[computer]
        ip = extract(text, "Adresse IP")
        if ip:
            item["ip"] = ip
        control_text = extract(text, "Date du controle")
        control_date = parse_date(control_text)
        is_recent = (
            control_date is not None
            and control_date <= NOW
            and NOW - control_date <= timedelta(minutes=MAX_AGE_MINUTES)
        )
        if is_recent:
            boot_text = extract(text, "Dernier démarrage")
            uptime_text = ""
            try:
                boot_date = datetime.strptime(boot_text, "%m/%d/%Y %H:%M:%S")
                uptime = NOW - boot_date
                if uptime.total_seconds() >= 0:
                    uptime_text = f"{uptime.days} jours {uptime.seconds // 3600} heures"
            except ValueError:
                pass
            item["uptime"] = uptime_text
            item["status"] = "OK"
            item["last_success"] = control_date.strftime("%Y-%m-%d %H:%M:%S")
            item["last_offline"] = ""
        else:
            item["status"] = "OFFLINE"
            if not item.get("last_offline"):
                item["last_offline"] = NOW_STR
        updates = 0
        match = re.search(r"Nombre de MAJ\s*:\s*(\d+)", text, re.IGNORECASE)
        if match:
            updates = int(match.group(1))
        oldest_date = None
        matches = re.findall(r"Propos[ée]e depuis\s*:\s*(\d{4}-\d{2}-\d{2})", text, re.IGNORECASE)
        for d in matches:
            if oldest_date is None or d < oldest_date:
                oldest_date = d
        days = None
        if oldest_date is not None:
            oldest = datetime.strptime(oldest_date, "%Y-%m-%d").date()
            days = max(0, (NOW.date() - oldest).days)
        item["updates"] = updates
        item["oldest_date"] = oldest_date
        item["update_days"] = days
    except Exception as e:
        log(f"{txt_file.name} : {e}")
USERS_JSON.parent.mkdir(parents=True, exist_ok=True)
users_tmp = USERS_JSON.with_suffix(".tmp")
with users_tmp.open("w", encoding="utf-8") as f:
    json.dump(
        dict(sorted(all_pcs.items())),
        f,
        indent=4,
        ensure_ascii=False
    )
    f.write("\n")
users_tmp.replace(USERS_JSON)
try:
    os.chmod(USERS_JSON, 0o666)  # ou 0o664
    shutil.chown(USERS_JSON, user="www-data", group="www-data")
except Exception as e:
    log(f"Erreur ajustement permissions users.json : {e}")
