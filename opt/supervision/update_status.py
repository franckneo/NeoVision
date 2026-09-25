#!/usr/bin/env python3
import json
import logging
import time
import subprocess
import os
import winrm
import warnings
warnings.filterwarnings("ignore", category=UserWarning, module="winrm")
from datetime import datetime
from logging.handlers import RotatingFileHandler
from lib.servers import get_servers, get_default_local_user
from lib.crypto import decrypt_password
from lib.retry import run_with_retry
from filelock import FileLock, Timeout
LOG_FILE="/var/log/supervision/update_status.log"
LOCK_FILE = "/opt/supervision/data/update_status.lock"
logger=logging.getLogger()
logger.setLevel(logging.INFO)
handler=RotatingFileHandler(
    LOG_FILE,
    maxBytes=5*1024*1024,
    backupCount=1
)
handler.setFormatter(
    logging.Formatter("%(asctime)s [%(levelname)s] %(message)s")
)
logger.addHandler(handler)

def get_linux_update_status(ip,user):
    start=time.time()
    try:
        reboot_cmd=[
            "ssh",
            "-o",
            "StrictHostKeyChecking=accept-new",
            f"{user}@{ip}",
            "test -d /run/needrestart && echo true || echo false"
        ]
        r1=subprocess.run(
            reboot_cmd,
            capture_output=True,
            text=True,
            timeout=10
        )
        reboot_required=r1.stdout.strip()=="true"
        update_cmd=[
            "ssh",
            "-o",
            "StrictHostKeyChecking=accept-new",
            f"{user}@{ip}",
            "apt list --upgradable 2>/dev/null | tail -n +2 | wc -l"
        ]
        r2=subprocess.run(
            update_cmd,
            capture_output=True,
            text=True,
            timeout=30
        )
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
        return {"error":"linux_unreachable"}

    finally:
        logging.info(
            f"[{ip}] linux update in {time.time()-start:.2f}s"
        )

def get_windows_update_status(ip,user,password):
    if not password:
        return {"error":"no_password"}

    start=time.time()

    try:
        session=winrm.Session(
            ip,
            auth=(user,password),
            transport="ntlm",
            read_timeout_sec=10,
            operation_timeout_sec=5
        )

        ps="""
if (Test-Path 'C:\\Scripts\\update_status.json') {
    Get-Content 'C:\\Scripts\\update_status.json'
} else {
    Write-Output 'null'
}
"""
        r=session.run_ps(ps)
        output=r.std_out.decode(
            errors="ignore"
        ).strip()
        if r.status_code!=0:
            logging.error(f"[{ip}] WinRM error")
            return {"error":"windows_no_data"}
        if not output or output=="null":
            logging.error(f"[{ip}] update_status.json missing")
            return {"error":"windows_no_data"}
        data=json.loads(output)
        return {
            "updates_pending":int(
                data.get("updates_pending",0)
            ),
            "reboot_required":bool(
                data.get("reboot_required",False)
            )
        }
    except Exception:
        logging.exception(f"Windows update failed {ip}")
        return {"error":"windows_unreachable"}
    finally:
        logging.info(
            f"[{ip}] windows update in {time.time()-start:.2f}s"
        )

def main():
    logging.info(
        "========== START update_status =========="
    )
    servers=get_servers()
    for s in servers:
        name=s.get("name")
        ip=s.get("ip")
        s_type=s.get("type")
        logging.info(
            f"--- {name} ({s_type}) [{ip}] ---"
        )
        status={
            "updates_pending":0,
            "reboot_required":False
        }
        try:
            if s_type=="linux":
                result=run_with_retry(
                    get_linux_update_status, 2, 2, ip, s.get("user") or get_default_local_user())
            elif s_type=="windows":
                pwd=decrypt_password(
                    s.get("enc_password")
                )
                result=run_with_retry(
                    get_windows_update_status, 2, 2, ip, s.get("user"), pwd)
            else:
                result={"error":"unknown_type"}

            if (
                isinstance(result,dict)
                and "error" not in result
            ):
                status=result
        except Exception:
            logging.exception(
                f"Processing failed {name}"
            )
        path=f"/opt/supervision/data/{name}_status.json"
        try:
            tmp_path=path+".tmp"
            with open(tmp_path,"w") as f:
                json.dump(
                    {
                        "timestamp":datetime.now().strftime(
                            "%Y-%m-%d %H:%M:%S"
                        ),
                        "status":status
                    },
                    f,
                    indent=2
                )

            os.replace(tmp_path,path)
        except Exception:
            logging.exception(
                f"write failed {path}"
            )
    logging.info(
        "========== END update_status =========="
    )
if __name__ == "__main__":
    try:
        lock = FileLock(LOCK_FILE, timeout=5)
        with lock:
            main()
    except Timeout:
        logging.warning("update_status already running")
