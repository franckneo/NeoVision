from datetime import datetime, timedelta
import csv
import os
history_dir = "/opt/supervision/history"
csv_file = os.path.join(history_dir, "alerts.csv")
archive_dir = os.path.join(history_dir, "archives")
def archive_old_alerts():
  if not os.path.exists(csv_file):
    return
  now = datetime.now()
  first_day_current_month = datetime(now.year, now.month, 1)
  if now.month == 1:
    target_date = datetime(now.year - 1, 12, 1)
  else:
    target_date = datetime(now.year, now.month - 1, 1)
  os.makedirs(archive_dir, exist_ok=True)
  kept_rows = []
  archives_by_month = {}
  with open(csv_file, mode="r", encoding="utf-8") as f:
    reader = csv.reader(f)
    header = next(reader, None)
    if not header:
      return
    for row in reader:
      if not row:
        continue
      try:
        alert_date = datetime.strptime(row[0], "%Y-%m-%d %H:%M:%S")
      except ValueError:
        kept_rows.append(row)
        continue
      if alert_date < target_date:
        month_key = alert_date.strftime("%Y-%m")
        if month_key not in archives_by_month:
          archives_by_month[month_key] = []
        archives_by_month[month_key].append(row)
      else:
        kept_rows.append(row)
  for month_key, rows in archives_by_month.items():
    archive_file = os.path.join(archive_dir, f"alerts_{month_key}.csv")
    file_exists = os.path.exists(archive_file)
    with open(
        archive_file, mode="a", newline="", encoding="utf-8"
    ) as arch_f:
      writer = csv.writer(arch_f)
      if not file_exists:
        writer.writerow(header)
      writer.writerows(rows)
  with open(csv_file, mode="w", newline="", encoding="utf-8") as f:
    writer = csv.writer(f)
    writer.writerow(header)
    writer.writerows(kept_rows)
if __name__ == "__main__":
  archive_old_alerts()
