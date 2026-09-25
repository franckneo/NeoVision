import time
import logging

def run_with_retry(func, retries=2, delay=2, *args, **kwargs):
    last = None
    for attempt in range(retries):
        try:
            start = time.time()
            last = func(*args, **kwargs)
            logging.info(f"{func.__name__} ok in {time.time()-start:.2f}s (try {attempt+1})")
            return last
        except Exception:
            logging.exception(f"{func.__name__} failed (try {attempt+1})")
            time.sleep(delay)
    return last
