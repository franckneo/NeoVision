#!/opt/supervision/venv/bin/python
import json
import os
from datetime import datetime, timedelta

DATA_DIR = '/opt/supervision/data'
MAX_AGE_DAYS = 120
cutoff_date = datetime.now() - timedelta(days=MAX_AGE_DAYS)
SUFFIXES = ['_disks.json', '_ram.json', '_uptime.json']
for fname in os.listdir(DATA_DIR):
    if any(fname.endswith(s) for s in SUFFIXES):
        path = os.path.join(DATA_DIR, fname)
        try:
            with open(path, 'r') as f:
                history = json.load(f)
            if not isinstance(history, list):
                continue
            old_count = len(history)
            filtered_history = [
                entry for entry in history
                if isinstance(entry, dict) and 'timestamp' in entry 
                and datetime.strptime(entry['timestamp'], "%Y-%m-%d %H:%M:%S") >= cutoff_date
            ]
            new_count = len(filtered_history)
            if old_count != new_count:
                with open(path, 'w') as f:
                    json.dump(filtered_history, f, indent=2)
                print(f"{fname}: {old_count - new_count} entrée(s) supprimée(s)")
            else:
                print(f"{fname}: OK (déjà à jour)")
        except Exception as e:
            print(f"Erreur sur {fname}: {e}")
