import winrm
import logging

def get_session(ip, user, password):
    try:
        return winrm.Session(
            ip,
            auth=(user, password),
            transport="ntlm",
            read_timeout_sec=30,
            operation_timeout_sec=20
        )
    except Exception:
        logging.exception("WinRM session failed")
        return None
