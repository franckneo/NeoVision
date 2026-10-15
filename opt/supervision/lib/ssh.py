import subprocess
import os
import json

def get_local_user():
    try:
        with open('/opt/supervision/data/accounts.json', 'r') as f:
            data = json.load(f)
            return data.get('local_user', '').strip() or 'root'
    except Exception:
        return 'root'

def run_ssh(user, ip, command, timeout=10):
    local_user = get_local_user()
    home_dir = "/root" if local_user == "root" else f"/home/{local_user}"
    key_path = f"{home_dir}/.ssh/id_ed25519"
    if not os.path.exists(key_path) and os.path.exists(f"{home_dir}/.ssh/id_rsa"):
        key_path = f"{home_dir}/.ssh/id_rsa"
    ssh_args = [
        "/usr/bin/ssh",
        "-i", key_path,
        "-o", "BatchMode=yes",
        "-o", "StrictHostKeyChecking=no",
        "-o", "ConnectTimeout=5",
        f"{user}@{ip}",
        command
    ]
    current_user = os.environ.get("USER", "")
    if current_user != local_user and os.geteuid() != 0:
        cmd = ["/usr/bin/sudo", "-n", "-u", local_user] + ssh_args
    else:
        cmd = ssh_args
    try:
        return subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=timeout)
    except subprocess.TimeoutExpired:
        return None
