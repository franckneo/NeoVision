from cryptography.fernet import Fernet
from lib.config import FERNET_KEY_PATH
import logging
FERNET = None
def load_key():
    global FERNET
    try:
        with open(FERNET_KEY_PATH, "rb") as f:
            FERNET = Fernet(f.read().strip())
    except Exception as e:
        logging.error(f"Fernet load failed: {e}")
def decrypt_password(enc):
    if not FERNET:
        load_key()
    if not FERNET or not enc:
        return None
    try:
        return FERNET.decrypt(enc.encode()).decode()
    except Exception:
        logging.exception("decrypt failed")
        return None
load_key()
