#!/opt/supervision/venv/bin/python
import json
import os
import logging
import subprocess
from datetime import datetime
from filelock import FileLock, Timeout
from logging.handlers import RotatingFileHandler
from lib.servers import get_servers, get_default_local_user
from lib.retry import run_with_retry
STATUS_FILE="/opt/supervision/data/update_status.json"
LOCK_FILE="/opt/supervision/data/update_lstatus.lock"
LOG_FILE="/var/log/supervision/update_lstatus.log"
logger=logging.getLogger()
logger.setLevel(logging.INFO)
handler=RotatingFileHandler(LOG_FILE,maxBytes=5*1024*1024,backupCount=1)
handler.setFormatter(logging.Formatter("%(asctime)s [%(levelname)s] %(message)s"))
logger.addHandler(handler)

def write_status(data):
    try:
        tmp=STATUS_FILE+".tmp"
        with open(tmp,"w") as f:
            json.dump(data,f,indent=2)
        os.replace(tmp,STATUS_FILE)
    except Exception:
        logging.exception("Failed writing update_status.json")

def get_linux_update_status(ip,user):
    try:
        reboot_cmd=[
            "ssh",
            "-o","StrictHostKeyChecking=accept-new",
            f"{user}@{ip}",
            "test -d /run/needrestart && echo true || echo false"
        ]
        r1=subprocess.run(reboot_cmd,capture_output=True,text=True,timeout=10)
        reboot_required=r1.stdout.strip()=="true"
        update_cmd=[
            "ssh",
            "-o","StrictHostKeyChecking=accept-new",
            f"{user}@{ip}",
            "apt list --upgradable 2>/dev/null | tail -n +2 | wc -l"
        ]
        r2=subprocess.run(update_cmd,capture_output=True,text=True,timeout=30)
        try:
            updates_pending=int(r2.stdout.strip())
        except Exception:
            logging.exception(f"[{ip}] invalid update count")
            updates_pending=0
        return {
            "updates_pending":updates_pending,
            "reboot_required":reboot_required
        }
    except Exception:
        logging.exception(f"Linux update failed {ip}")
        return {"error":"injoignable"}
    finally:
        logging.info(f"[{ip}] update check done")

def main():
    start_time=datetime.now()
    write_status({
        "running":True,
        "message":"running",
        "timestamp":start_time.isoformat(),
        "started_at":start_time.isoformat(),
        "pid":os.getpid()
    })
    logging.info("========== START lstatus ==========")
    try:
        servers=get_servers()
        linux_servers=[s for s in servers if s.get("type")=="linux"]
        for s in linux_servers:
            name=s.get("name")
            ip=s.get("ip")
            logging.info(f"--- {name} [{ip}] ---")
            status={
                "updates_pending":0,
                "reboot_required":False
            }
            result=run_with_retry( get_linux_update_status, 2, 2, ip, s.get("user") or get_default_local_user() )
            if isinstance(result,dict) and "error" not in result:
                status=result
            path=f"/opt/supervision/data/{name}_status.json"
            try:
                tmp=path+".tmp"
                with open(tmp,"w") as f:
                    json.dump({
                        "timestamp":datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
                        "status":status
                    },f,indent=2)
                os.replace(tmp,path)
            except Exception:
                logging.exception(f"write failed {name}")
        end_time=datetime.now()
        write_status({
            "running":False,
            "message":"done",
            "timestamp":end_time.isoformat(),
            "finished_at":end_time.isoformat(),
            "pid":os.getpid()
        })
        logging.info("========== END lstatus ==========")
    except Exception as e:
        write_status({
            "running":False,
            "message":"error",
            "error":str(e),
            "timestamp":datetime.now().isoformat(),
            "pid":os.getpid()
        })
        logging.exception("fatal error")
        raise

if __name__=="__main__":
    try:
        lock=FileLock(LOCK_FILE,timeout=5)
        with lock:
            main()
    except Timeout:
        write_status({
            "running":False,
            "message":"already running",
            "timestamp":datetime.now().isoformat()
        })
        logging.warning("already running")
