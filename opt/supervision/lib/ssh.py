import subprocess,logging,os
from lib.servers import get_default_local_user
def run_ssh(user,ip,cmd,timeout=10):
    lu=get_default_local_user() or "root"
    b="/root/.ssh" if lu=="root" else f"/home/{lu}/.ssh"
    k=f"{b}/id_ed25519" if os.path.isfile(f"{b}/id_ed25519") else f"{b}/id_rsa"
    try:
        return subprocess.run(["ssh","-i",k,"-o","StrictHostKeyChecking=accept-new","-o","UserKnownHostsFile=/dev/null",f"{user}@{ip}",cmd],capture_output=True,text=True,timeout=timeout)
    except Exception:
        logging.exception(f"SSH failed {ip}")
        return None
