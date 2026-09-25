import subprocess
import logging

def run_ssh(user, ip, cmd, timeout=10):
    try:
        return subprocess.run(
            ["ssh", "-o", "StrictHostKeyChecking=accept-new", f"{user}@{ip}", cmd],
            capture_output=True,
            text=True,
            timeout=timeout
        )
    except Exception:
        logging.exception(f"SSH failed {ip}")
        return None
