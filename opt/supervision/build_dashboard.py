#!/usr/bin/env python3
import json
import os
from datetime import datetime
BASE="/opt/supervision"
DATA=os.path.join(BASE,"data")
OUTPUT=os.path.join(DATA,"dashboard.json")
def load(path,default=None):
    try:
        with open(path,encoding="utf-8") as f:
            return json.load(f)
    except Exception:
        return default
def latest(value):
    if isinstance(value,list):
        return value[0] if value else {}
    return value if isinstance(value,dict) else {}
def load_history(name,suffix):
    return latest(load(os.path.join(DATA,f"{name}_{suffix}.json"),{}))
def load_disks(name):
    item=latest(load(os.path.join(DATA,f"{name}_disks.json"),[]))
    disks=item.get("disks",{}) if isinstance(item,dict) else {}
    return {"timestamp":item.get("timestamp",""),"disks":disks if isinstance(disks,dict) else {}}
def load_servers():
    value=load(os.path.join(DATA,"servers.json"),[])
    return value if isinstance(value,list) else []
def number(value):
    try:
        return float(str(value).replace("%","").strip())
    except (TypeError,ValueError):
        return None
def service_state(value):
    if isinstance(value,dict):
        value=value.get("status",value.get("state","unknown"))
    return str(value).strip().lower()
def calculate_health(disks,ram,uptime,services,status,config):
    if not isinstance(config,dict) or not config.get("global_enabled",True):
        return {"level":"disabled","causes":[]}
    causes=[]
    level="ok"
    def add(severity,cause):
        nonlocal level
        causes.append(cause)
        if severity=="critical":
            level="critical"
        elif severity=="warning" and level=="ok":
            level="warning"
    disk_values=disks.get("disks",{}) if isinstance(disks,dict) else {}
    disk_config=config.get("disks",{})
    if isinstance(disk_config,dict) and isinstance(disk_values,dict):
        for disk_name,rules in disk_config.items():
            if not isinstance(rules,dict) or not rules.get("enabled",False):
                continue
            current=disk_values.get(disk_name)
            if not isinstance(current,dict):
                continue
            used=number(current.get("used_percent"))
            if used is None:
                continue
            if rules.get("crit_enabled",False):
                threshold=number(rules.get("crit_threshold"))
                if threshold is not None and used>=threshold:
                    add("critical",f"Disque {disk_name} à {used:g}% (seuil critique {threshold:g}%)")
                    continue
            if rules.get("warn_enabled",False):
                threshold=number(rules.get("warn_threshold"))
                if threshold is not None and used>=threshold:
                    add("warning",f"Disque {disk_name} à {used:g}% (seuil warning {threshold:g}%)")
    ram_value=None
    if isinstance(ram,dict):
        for key in ("used_percent","usage_percent","percent","ram_percent"):
            if key in ram:
                ram_value=number(ram[key])
                break
    if ram_value is not None:
        if config.get("ram_crit_enabled",False):
            threshold=number(config.get("ram_crit_threshold"))
            if threshold is not None and ram_value>=threshold:
                add("critical",f"RAM à {ram_value:g}% (seuil critique {threshold:g}%)")
        if level!="critical" and config.get("ram_warn_enabled",False):
            threshold=number(config.get("ram_warn_threshold"))
            if threshold is not None and ram_value>=threshold:
                add("warning",f"RAM à {ram_value:g}% (seuil warning {threshold:g}%)")
        if config.get("ram_enabled",False) and not config.get("ram_warn_enabled",False) and not config.get("ram_crit_enabled",False):
            threshold=number(config.get("ram_threshold"))
            if threshold is not None and ram_value>=threshold:
                add("critical",f"RAM à {ram_value:g}% (seuil {threshold:g}%)")
    uptime_days=None
    if isinstance(uptime,dict):
        uptime_days=number(uptime.get("uptime_days",uptime.get("days")))
    if uptime_days is not None:
        if config.get("uptime_crit_enabled",False):
            threshold=number(config.get("uptime_crit_threshold"))
            if threshold is not None and uptime_days>=threshold:
                add("critical",f"Uptime de {uptime_days:g} jours (seuil critique {threshold:g} jours)")
        if level!="critical" and config.get("uptime_warn_enabled",False):
            threshold=number(config.get("uptime_warn_threshold"))
            if threshold is not None and uptime_days>=threshold:
                add("warning",f"Uptime de {uptime_days:g} jours (seuil warning {threshold:g} jours)")
        if config.get("uptime_enabled",False) and not config.get("uptime_warn_enabled",False) and not config.get("uptime_crit_enabled",False):
            threshold=number(config.get("uptime_threshold"))
            if threshold is not None and uptime_days>=threshold:
                add("critical",f"Uptime de {uptime_days:g} jours (seuil {threshold:g} jours)")
    if config.get("update_enabled",False) and isinstance(status,dict):
        updates=number(status.get("updates_pending",0))
        if updates is not None and updates>0:
            add("warning",f"{updates:g} mise(s) à jour en attente")
    if config.get("reboot_enabled",False) and isinstance(status,dict) and status.get("reboot_required",False):
        add("warning","Redémarrage requis")
    if config.get("offline_enabled",False) and isinstance(services,dict):
        service_values=services.get("services",services)
        if isinstance(service_values,dict):
            for name,value in service_values.items():
                state=service_state(value)
                if state not in ("running","active","started","ok","up"):
                    add("critical",f"Service {name}: {state}")
    if level=="ok":
        causes=[]
    return {"level":level,"causes":causes}
def main():
    alert_config=load(os.path.join(DATA,"alert_config.json"),{})
    if not isinstance(alert_config,dict):
        alert_config={}
    servers=[]
    for item in load_servers():
        if isinstance(item,str):
            name=item
            server={}
        elif isinstance(item,dict):
            server=dict(item)
            name=server.get("name") or server.get("hostname") or server.get("Computer") or server.get("computer")
        else:
            continue
        if not name:
            continue
        server["name"]=name
        disks=load_disks(name)
        uptime=load_history(name,"uptime")
        ram=load_history(name,"ram")
        status_file=load_history(name,"status")
        services=load_history(name,"services")
        status=status_file.get("status",status_file) if isinstance(status_file,dict) else {}
        config=alert_config.get(name,{})
        server["disks"]=disks
        if uptime:
            server["uptime"]=uptime.get("uptime",uptime.get("Uptime",""))
            server["uptime_days"]=uptime.get("uptime_days",uptime.get("days",0))
            server["uptime_timestamp"]=uptime.get("timestamp",uptime.get("Timestamp",""))
        if ram:
            server["ram"]=ram
        if status:
            server["status"]=status
            server["updates_pending"]=status.get("updates_pending",0)
            server["reboot_required"]=status.get("reboot_required",False)
        if services:
            server["services"]=services
        server["health"]=calculate_health(disks,ram,uptime,services,status,config)
        servers.append(server)
    temporary=OUTPUT+".tmp"
    with open(temporary,"w",encoding="utf-8") as f:
        json.dump({"timestamp":datetime.now().isoformat(),"servers":servers},f,indent=2,ensure_ascii=False)
        f.write("\n")
    os.replace(temporary,OUTPUT)
if __name__=="__main__":
    main()
