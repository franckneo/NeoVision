function formatDownSince(isoStr) {
    if (!isoStr) return "";
    const d = new Date(isoStr);
    if (isNaN(d.getTime())) return isoStr;
    const dateFormatted = d.toLocaleDateString(CURRENT_LOCALE, {day: "2-digit", month: "2-digit", year: "numeric"}) + " " + t('word_at', 'à') + " " + d.toLocaleTimeString(CURRENT_LOCALE, {hour: "2-digit", minute: "2-digit"});
    const diffMs = Date.now() - d.getTime();
    if (diffMs > 0) {
        const totalMin = Math.floor(diffMs / 60000), hours = Math.floor(totalMin / 60), mins = totalMin % 60, days = Math.floor(hours / 24);
        let duration = days > 0 ? `${days}j ${hours % 24}h` : (hours > 0 ? `${hours}h ${mins}min` : `${mins} min`);
        return `${dateFormatted} (${duration})`;
    }
    return dateFormatted;
}
document.querySelectorAll(".main-nav-btn").forEach(btn => {
    btn.onclick = () => {
        document.querySelectorAll(".main-nav-btn").forEach(b => b.classList.remove("active"));
        document.querySelectorAll(".view-section").forEach(v => v.style.display = "none");
        btn.classList.add("active");
        const targetView = btn.dataset.view;
        document.getElementById(`view-${targetView}`).style.display = "block";
        localStorage.setItem("activeMainView", targetView);
        if (targetView === "switchs" || targetView === "aps" || targetView === "others") loadNetworkData(targetView);
    };
});
const savedView = localStorage.getItem("activeMainView") || "servers", activeNavBtn = document.querySelector(`.main-nav-btn[data-view="${savedView}"]`);
if (activeNavBtn) activeNavBtn.click();
function numericLoss(dev) {
    const value = dev.loss ?? dev.losses ?? dev.packet_loss ?? dev.packetLoss ?? dev.loss_percent ?? 0, n = parseFloat(String(value).replace('%',''));
    return Number.isFinite(n) ? n : 0;
}
function getNetworkBadgeState(dev, type, config) {
    const cfg = config?.[type]?.[dev.name] || {};
    if (dev.type === 'temperature') {
        const temp = parseFloat(dev.temperature_c), warn = Number(cfg.temp_warn ?? 25), crit = Number(cfg.temp_crit ?? 30);
        if (Number.isFinite(temp)) {
            if (temp >= crit) return 'down';
            if (temp >= warn) return 'warning';
            return 'up';
        }
        return 'unknown';
    }
    if (dev.status === 'down' || dev.status === 'offline') {
        const failures = Number(dev.failures_count ?? 0), maxAllowed = Number(cfg.max_loss ?? cfg.max_loss_allowed ?? dev.max_loss_allowed ?? 0), downDuration = Number(dev.down_duration_sec ?? 0);
        if (downDuration > 7200) return 'down';
        if (failures > maxAllowed) return 'warning';
        return 'up';
    }
    return 'up';
}
async function loadNetworkData(type) {
    const container = document.getElementById(`${type}Quadrants`);
    if (!container) return;
    try {
        const res = await fetch(`/api/data/network_${type}.json?v=` + Date.now());
        if (!res.ok) { container.innerHTML = `<p>${t('msg_no_data_available', 'Aucune donnée disponible pour le moment.')}</p>`; return; }
        const rawData = await res.json();
        container.innerHTML = "";
        let groupedData = {};
        const globalTimestamp = rawData.timestamp || t('word_not_available', 'Non disponible');
        if (Array.isArray(rawData)) {
            rawData.forEach(dev => {
                const siteName = dev.site || t('word_other', 'Autre');
                if (!groupedData[siteName]) groupedData[siteName] = [];
                groupedData[siteName].push(dev);
            });
        } else {
            groupedData = rawData.sites || rawData;
        }
        const sites = Object.keys(groupedData);
        if (sites.length === 0) { container.innerHTML = `<p>${t('msg_no_device_found', 'Aucun équipement trouvé.')}</p>`; return; }
        const isBlueStyle = (type === "others"), alertConfig = await fetchNetworkAlertConfig();
        sites.forEach(site => {
            const siteData = groupedData[site] || [], upCount = siteData.filter(d => getNetworkBadgeState(d, type, alertConfig) === "up").length;
            const card = document.createElement("div");
            card.className = "site-card";
            let html = `<div class="site-title"><span>${site}</span><small style="font-size:12px; opacity:0.8;">${upCount}/${siteData.length} UP</small></div><div class="device-grid">`;
            siteData.forEach(dev => {
                const badgeState = getNetworkBadgeState(dev, type, alertConfig), isTemp = (dev.type === "temperature");
                let deviceValue = isTemp ? ((dev.temperature_c !== undefined && dev.temperature_c !== null) ? `${dev.temperature_c} °C` : "KO") : ((dev.latency_ms !== undefined && dev.latency_ms !== null) ? `${dev.latency_ms} ms` : "KO");
                let badgeClass = badgeState === 'down' ? 'down offline' : (badgeState === 'warning' ? 'warning' : (isBlueStyle ? 'up-blue' : 'up'));
                const displaySub = (isTemp || badgeState !== 'down') ? deviceValue : t('status_offline', 'HORS LIGNE'), lastCheck = dev.updated_at || globalTimestamp;
                let tooltipText = `${t('tooltip_last_check', 'Dernier relevé')} : ${lastCheck}`;
                if (badgeState === 'down' || badgeState === 'warning') {
                    if (dev.down_since) tooltipText = `${t('tooltip_offline_since', 'Hors ligne depuis le')} ${formatDownSince(dev.down_since)}`;
                    else if (dev.last_ok || dev.last_success || dev.last_up_at) tooltipText = `${t('status_offline', 'Hors ligne')} - ${t('tooltip_last_ok', 'Dernier OK')} : ${formatDownSince(dev.last_ok || dev.last_success || dev.last_up_at)}`;
                    else if (dev.down_duration_sec) {
                        const mins = Math.round(dev.down_duration_sec / 60), hours = Math.floor(mins / 60);
                        tooltipText = `${t('tooltip_offline_since', 'Hors ligne depuis')} ~${hours > 0 ? `${hours}h ${mins % 60}min` : `${mins} min`} (${t('tooltip_last_check', 'Dernier relevé')} : ${lastCheck})`;
                    }
                }
                html += `<div class="device-badge ${badgeClass}" title="${tooltipText}"><div class="device-name">${dev.name}</div><div class="device-sub">${dev.ip || ''}</div><div class="device-sub">${displaySub}</div></div>`;
            });
            html += `</div>`;
            card.innerHTML = html;
            card.querySelectorAll('.device-badge').forEach(badge => {
                badge.style.cursor = 'pointer';
                badge.onclick = () => {
                    const tabMap = { switchs: 'tab-switchs', aps: 'tab-aps', others: 'tab-others' };
                    window.location = `mailing.php?tab=${encodeURIComponent(tabMap[type] || 'tab-servers')}`;
                };
            });
            container.appendChild(card);
        });
    } catch (e) {
        console.error("Erreur réseau :", e);
        container.innerHTML = `<p>${t('msg_error_loading_network_data', 'Erreur de chargement des données réseau.')}</p>`;
    }
}
async function pollUpdateStatus() {
    const status = document.getElementById("refreshStatus"), statusReboot = document.getElementById("refreshStatusReboot");
    try {
        const res = await fetch("/api/data/update_status.json?v=" + Date.now()), data = await res.json();
        let text = "Idle";
        const now = Date.now(), lastTimestamp = new Date(data.timestamp || 0).getTime(), finishedAt = new Date(data.finished_at || 0).getTime(), ageMin = (now - lastTimestamp) / 60000;
        if (data.running) text = t('status_in_progress', "En cours...");
        else if (data.message === "already running") text = t('status_already_running', "Déjà en cours");
        else if (data.message === "error" || data.error) text = t('status_error', "Erreur");
        else if (!data.timestamp || ageMin > 10) text = "Idle";
        else if (finishedAt > 0) text = t('status_completed', "Terminé");
        else text = t('status_completed', "Terminé");
        if (status) status.textContent = text;
        if (statusReboot) statusReboot.textContent = text;
    } catch (e) {
        if (status) status.textContent = t('status_error', "Erreur");
        if (statusReboot) statusReboot.textContent = t('status_error', "Erreur");
    }
}
let DASHBOARD_DATA = null;
let NETWORK_ALERT_CONFIG = window.NETWORK_ALERT_CONFIG || {};
async function fetchNetworkAlertConfig(){
    if (NETWORK_ALERT_CONFIG && Object.keys(NETWORK_ALERT_CONFIG).length) return NETWORK_ALERT_CONFIG;
    try {
        const res = await fetch('/api/data/network_alert_config.json?v=' + Date.now());
        NETWORK_ALERT_CONFIG = res.ok ? await res.json() : {};
    } catch (e) { NETWORK_ALERT_CONFIG = {}; }
    return NETWORK_ALERT_CONFIG;
}
async function loadDashboardData(){
    if (DASHBOARD_DATA && DASHBOARD_DATA.servers) return DASHBOARD_DATA;
    try {
        const res = await fetch('/api/data/dashboard.json?v=' + Date.now());
        DASHBOARD_DATA = res.ok ? await res.json() : { servers: [] };
    } catch (e) {
        console.error("loadDashboardData:", e);
        DASHBOARD_DATA = { servers: [] };
    }
    return DASHBOARD_DATA;
}
function getServerEntry(name){
    if (!DASHBOARD_DATA || !Array.isArray(DASHBOARD_DATA.servers)) return null;
    return DASHBOARD_DATA.servers.find(s => s && s.name === name) || null;
}
function getRamEntry(s){
    if (!s) return null;
    if (Array.isArray(s.ram) && s.ram.length) return s.ram[0];
    if (s.ram && typeof s.ram === 'object') return s.ram;
    return null;
}
function getDisksEntry(s){
    if (!s || !s.disks) return null;
    if (Array.isArray(s.disks) && s.disks.length) {
        return {
            timestamp: s.timestamp || s.status_file_timestamp || null,
            disks: s.disks,
            history: []
        };
    }
    if (typeof s.disks === 'object' && s.disks.disks && typeof s.disks.disks === 'object') {
        return s.disks;
    }
    return null;
}
function getDiskHistory(s){
    if (!s) return [];
    if (Array.isArray(s.disk_history)) return s.disk_history;
    if (s.disks && typeof s.disks === 'object' && Array.isArray(s.disks.history)) return s.disks.history;
    return [];
}
function getStatusEntry(s){
    if (!s) return { updates_pending: 0, reboot_required: false, service_errors: [], status_file_timestamp: null };
    const base = (s.status && typeof s.status === 'object') ? s.status : {};
    return {
        updates_pending: Number(base.updates_pending ?? s.updates_pending ?? 0),
        reboot_required: Boolean(base.reboot_required ?? s.reboot_required ?? false),
        service_errors: Array.isArray(base.service_errors) ? base.service_errors : (Array.isArray(s.service_errors) ? s.service_errors : []),
        status_file_timestamp: base.status_file_timestamp ?? s.status_file_timestamp ?? null,
    };
}
function getPingEntry(s){
    if (!s || !s.ping || typeof s.ping !== 'object') {
        return { status: 'unknown', ping_ok: false, consecutive_losses: 0, ping_max_loss: 1, ping_critical: false, last_ok: null };
    }
    return {
        status: s.ping.status || 'unknown',
        ping_ok: !!s.ping.ping_ok,
        consecutive_losses: Number(s.ping.consecutive_losses ?? s.consecutive_losses ?? 0),
        ping_max_loss: Number(s.ping.ping_max_loss ?? s.ping_max_loss ?? 1),
        ping_critical: Boolean(s.ping.ping_critical ?? s.ping_critical ?? false),
        last_ok: s.ping.last_ok ?? null,
    };
}
function renderServerList(servers){
    const ul = document.getElementById("serverList");
    if (!ul) return;
    ul.innerHTML = '';
    const frag = document.createDocumentFragment();
    servers.forEach(s => {
        const li = document.createElement("li");
        li.style.display = "flex"; li.style.alignItems = "center"; li.style.gap = "6px";
        const dot = document.createElement("span");
        dot.className = "dot dot-unknown"; dot.id = `dot-${s.name}`;
        const link = document.createElement("a");
        link.href = `details.php?server=${s.name}`; link.textContent = s.name;
        li.append(dot, link);
        frag.appendChild(li);
    });
    ul.appendChild(frag);
}
function parseSize(str){
    if (!str) return 0;
    const m = String(str).trim().match(/^([\d.,]+)\s*([KMGTP]?I?B?)$/i);
    if (!m) return 0;
    let val = parseFloat(m[1].replace(',', '.'));
    const unit = (m[2] || 'B').toUpperCase();
    const multipliers = { B: 1/1024/1024/1024, KB: 1/1024/1024, KIB: 1/1024/1024, K: 1/1024/1024, MB: 1/1024, MIB: 1/1024, M: 1/1024, GB: 1, GIB: 1, G: 1, TB: 1024, TIB: 1024, T: 1024, PB: 1024*1024, PIB: 1024*1024, P: 1024*1024 };
    return Math.round(val * (multipliers[unit] || 0));
}
function parseUptime(str){
    let total = 0; if (!str) return total;
    const regex = /(\d+)\s*(day|jour|hour|heure|minute)s?/gi;
    let match;
    while ((match = regex.exec(str)) !== null) {
        const val = parseInt(match[1]);
        const unit = match[2].toLowerCase();
        if (unit.startsWith('day') || unit.startsWith('jour')) total += val * 86400;
        else if (unit.startsWith('hour') || unit.startsWith('heure')) total += val * 3600;
        else if (unit.startsWith('minute')) total += val * 60;
        else if (unit.startsWith('s')) total += val;
    }
    return total;
}
function sortTable(tableId, colIndex, type = "text"){
    const table = document.getElementById(tableId);
    if (!table) return;
    const tbody = table.querySelector("tbody");
    const rows = Array.from(tbody.querySelectorAll("tr"));
    const ths = table.querySelectorAll("th");
    const asc = table.dataset.sortOrder !== "asc";
    table.dataset.sortOrder = asc ? "asc" : "desc";
    ths.forEach(th => th.classList.remove("sort-asc", "sort-desc"));
    if (ths[colIndex]) ths[colIndex].classList.add(asc ? "sort-asc" : "sort-desc");
    rows.sort((a, b) => {
        let A = a.children[colIndex] ? (a.children[colIndex].dataset.value ?? a.children[colIndex].innerText.trim()) : '';
        let B = b.children[colIndex] ? (b.children[colIndex].dataset.value ?? b.children[colIndex].innerText.trim()) : '';
        switch (type) {
            case "numeric": A = parseFloat(A) || 0; B = parseFloat(B) || 0; break;
            case "uptime": A = parseUptime(A); B = parseUptime(B); break;
            case "size": A = parseSize(A); B = parseSize(B); break;
            case "percent": A = parseFloat(String(A).replace('%', '')) || 0; B = parseFloat(String(B).replace('%', '')) || 0; break;
            case "status": const o = { ok: 1, healthy: 1, warning: 2, critical: 3 }; A = o[String(A).toLowerCase()] || 0; B = o[String(B).toLowerCase()] || 0; break;
            case "date": A = new Date(A).getTime() || 0; B = new Date(B).getTime() || 0; break;
        }
        if (type === "text") return asc ? String(A).localeCompare(String(B)) : String(B).localeCompare(String(A));
        return asc ? A - B : B - A;
    });
    tbody.append(...rows);
}
function getDiskSparklineElement(server, diskName) {
    const container = document.createElement("div");
    container.style.height = "30px";
    container.style.display = "flex";
    container.style.alignItems = "flex-end";
    container.style.justifyContent = "center";
    container.style.lineHeight = "0";

    const placeholder = document.createElement("span");
    placeholder.textContent = "—";
    placeholder.style.color = "#999";
    container.appendChild(placeholder);

    fetch(`/api/data/${encodeURIComponent(server)}_disks.json?v=` + Date.now())
        .then(res => res.ok ? res.json() : [])
        .then(diskHistory => {
            if (!Array.isArray(diskHistory) || diskHistory.length < 2) return;

            const historyData = [];
            diskHistory.forEach(e => {
                if (!e || !e.disks || !e.timestamp) return;
                let p = null;
                if (Array.isArray(e.disks)) {
                    const found = e.disks.find(d => (d.mount || d.filesystem || d.name || d.device) === diskName);
                    if (found) p = parseFloat(String(found.used_percent ?? found.use_percent ?? found.percent ?? 0).replace('%', ''));
                } else if (typeof e.disks === 'object' && e.disks[diskName]) {
                    p = parseFloat(String(e.disks[diskName].used_percent ?? e.disks[diskName].use_percent ?? 0).replace('%', ''));
                }
                if (p !== null && !isNaN(p)) {
                    historyData.push({ percent: p, date: e.timestamp });
                }
            });

            historyData.sort((a, b) => new Date(a.date) - new Date(b.date));
            const recentHistory = historyData.slice(-11);
            if (recentHistory.length < 2) return;

            container.innerHTML = "";
            const formatDate = d => d.toLocaleDateString(CURRENT_LOCALE, { day: "2-digit", month: "2-digit" });

            for (let i = 1; i < recentHistory.length; i++) {
                const previous = recentHistory[i - 1];
                const current = recentHistory[i];
                const previousValue = previous.percent;
                const value = current.percent;
                const delta = value - previousValue;
                const sign = delta > 0 ? "+" : "";
                const tooltipText = `${formatDate(new Date(previous.date))} > ${formatDate(new Date(current.date))} : ${previousValue}% > ${value}% (${sign}${delta.toFixed(1)}%)`;

                const bar = document.createElement("div");
                bar.className = "sparkbar";
                bar.title = tooltipText;
                bar.setAttribute("data-tooltip", tooltipText);
                bar.style.width = "6px";
                bar.style.height = Math.max(3, Math.round(value * 30 / 100)) + "px";
                bar.style.margin = "0 1px";
                bar.style.flexShrink = "0";
                bar.style.cursor = "help";
                bar.style.backgroundColor = delta > 5 ? "#ef4444" : (delta > 2 ? "#f59e0b" : "#10b981");
                container.appendChild(bar);
            }
        })
        .catch(() => {});

    return container;
}
async function displayServer(server, fragments = null){
    const s = getServerEntry(server);
    if (!s) {
        const dot = document.getElementById(`dot-${server}`);
        if (dot) dot.className = "dot dot-unknown";
        return;
    }
    const jsonHealth = s.health && typeof s.health === "object" ? s.health : {};
    const jsonLevel = ["ok", "warning", "critical", "disabled"].includes(jsonHealth.level) ? jsonHealth.level : null;
    const ram = getRamEntry(s);
    const diskEntry = getDisksEntry(s);
    const diskHistory = getDiskHistory(s);
    const status = getStatusEntry(s);
    const ping = getPingEntry(s);
    const serviceErrors = Array.isArray(status.service_errors) ? status.service_errors : [];
    let ramVal = 'N/A', ramTs = '-', ramCls = '';
    let level = 'ok', causes = [];
    const precomputedHealth = (typeof s.health === 'string' && s.health) ? s.health.toLowerCase() : null;
    const healthReasons = Array.isArray(s.health_reasons) ? s.health_reasons : [];
    if (precomputedHealth === 'critical') level = 'critical';
    else if (precomputedHealth === 'warning' && level === 'ok') level = 'warning';
    if (serviceErrors.length > 0) {
        level = 'critical';
        causes.push(...serviceErrors.map(error => `${t('word_service', 'Service')} ${error}`));
    }
    if (ram && ram.percent !== undefined && ram.percent !== null) {
        const pct = Number(ram.percent);
        ramVal = pct + '%';
        ramTs = ram.timestamp || '-';
        if (Number.isFinite(pct) && pct >= 90) { ramCls = 'ram-alert'; level = 'critical'; causes.push(`RAM ${pct}%`); }
    }
    const memTbody = fragments ? fragments.memory : document.querySelector("#memoryTable tbody");
    if (memTbody) {
        const trMem = document.createElement("tr");
        trMem.style.cursor = "pointer"; trMem.onclick = () => { window.location = `details.php?server=${server}`; };
        trMem.innerHTML = `<td>${server}</td><td class="${ramCls}">${ramVal}</td><td>${ramTs}</td>`;
        memTbody.appendChild(trMem);
    }
    const diskTbody = fragments ? fragments.disques : document.querySelector("#disquesTable tbody");
    const diskArray = diskEntry ? [diskEntry] : [];
    if (diskTbody && diskArray.length && diskArray[0] && diskArray[0].disks) {
        let diskList = [];
        if (Array.isArray(diskArray[0].disks)) {
            diskList = diskArray[0].disks.map(d => ({
                name: d.mount || d.filesystem || d.name || d.device || "/",
                total: d.total || d.size || "0GB",
                used_percent: parseFloat(String(d.used_percent ?? d.use_percent ?? d.percent ?? 0).replace('%', '')) || 0
            }));
        } else if (typeof diskArray[0].disks === 'object') {
            diskList = Object.entries(diskArray[0].disks).map(([name, d]) => ({
                name: name,
                total: (d && (d.total || d.size)) || "0GB",
                used_percent: parseFloat(String((d && (d.used_percent ?? d.use_percent ?? d.percent)) ?? 0).replace('%', '')) || 0
            }));
        }
        diskList.forEach(d => {
            const totalGB = parseSize(d.total);
            const usedP = d.used_percent;
            const usedGB = Math.round(totalGB * usedP / 100);
            const freeGB = Math.max(0, Math.round(totalGB - usedGB));
            if (usedP >= 95) {
                level = 'critical';
                causes.push(`${t('word_disk', 'Disque')} ${d.name} ${t('word_full', 'plein')}`);
            } else if (usedP >= 85 && level === 'ok') {
                level = 'warning';
            }
            const tr = document.createElement("tr");
            tr.style.cursor = "pointer";
            tr.onclick = () => { window.location = `details.php?server=${server}`; };
            tr.innerHTML = `
                <td>${server}</td>
                <td>${d.name}</td>
                <td>${d.total}</td>
                <td><div style="display:flex;justify-content:space-between;width:100%"><span>${usedP}%</span><span>(${usedGB}G ${t('word_used', 'used')} / ${freeGB}G ${t('word_free', 'free')})</span></div></td>
                <td style="text-align:center"></td>
                <td>${diskArray[0].timestamp || status.status_file_timestamp || '-'}</td>
            `;
            if (tr.children[4]) tr.children[4].appendChild(getDiskSparklineElement(server, d.name));
            diskTbody.appendChild(tr);
        });
    }
    if (ping.status === "offline") {
        const consecutiveLosses = Number(ping.consecutive_losses ?? 0), maxLoss = Number(ping.ping_max_loss ?? 1);
        if (consecutiveLosses > maxLoss) { level = "critical"; causes.push(`Offline (${consecutiveLosses} ${t('word_losses', 'pertes')})`); }
        else if (consecutiveLosses > 0) { if (level !== "critical") level = "warning"; causes.push(`${t('msg_ping_lost', 'Ping perdu')} (${consecutiveLosses}/${maxLoss})`); }
    }
    if (status.reboot_required) {
        if (level !== 'critical') level = 'warning';
        causes.push(t('cause_reboot_required', 'Reboot requis'));
    }
    if (healthReasons.length > 0 && causes.length === 0) {
        causes.push(...healthReasons);
    }
    if (jsonLevel) {
        level = jsonLevel;
        causes = Array.isArray(jsonHealth.causes) ? [...jsonHealth.causes] : [];
    }
    const finalCause = causes.length > 0 ? causes.join(', ') : '-';
    const updates = Number(status.updates_pending ?? 0);
    const updTbody = fragments ? fragments.update : document.querySelector("#updateTable tbody");
    if (updTbody) {
        const trUpd = document.createElement("tr");
        trUpd.style.cursor = "pointer"; trUpd.onclick = () => { window.location = `details.php?server=${server}`; };
        trUpd.innerHTML = `<td>${server}</td><td class="${updates > 0 ? 'reboot-required' : ''}">${updates}</td>`;
        updTbody.appendChild(trUpd);
    }
    const rebTbody = fragments ? fragments.reboot : document.querySelector("#rebootTable tbody");
    if (rebTbody) {
        const trReb = document.createElement("tr");
        trReb.style.cursor = "pointer"; trReb.onclick = () => { window.location = `details.php?server=${server}`; };
        trReb.innerHTML = `<td>${server}</td><td class="${status.reboot_required ? 'reboot-required' : ''}">${status.reboot_required ? t('word_yes', 'Oui') : t('word_no', 'Non')}</td>`;
        rebTbody.appendChild(trReb);
    }
    const dot = document.getElementById(`dot-${server}`);
    if (dot) dot.className = `dot dot-${level}`;
    const uptimeTbody = fragments ? fragments.uptime : document.querySelector("#uptimeTable tbody");
    if (uptimeTbody) {
        const trUptime = document.createElement("tr");
        trUptime.style.cursor = "pointer"; trUptime.onclick = () => { window.location = `details.php?server=${server}`; };
        const uptimeText = s.uptime || 'N/A';
        const uptimeTs = s.uptime_timestamp || '-';
        trUptime.innerHTML = `<td>${server}</td><td>${uptimeText}</td><td>${uptimeTs}</td><td class="status-${level}">${level.toUpperCase()}</td><td>${finalCause}</td>`;
        uptimeTbody.appendChild(trUptime);
    }
}
async function refreshDashboard(){
    await loadDashboardData();
    const servers = (DASHBOARD_DATA && Array.isArray(DASHBOARD_DATA.servers)) ? DASHBOARD_DATA.servers : [];
    renderServerList(servers);
    const fragments = {
        uptime:  document.createDocumentFragment(),
        disques: document.createDocumentFragment(),
        memory:  document.createDocumentFragment(),
        update:  document.createDocumentFragment(),
        reboot:  document.createDocumentFragment()
    };
    for (const s of servers) {
        await displayServer(s.name, fragments);
    }
    document.querySelector("#uptimeTable tbody")?.replaceChildren(fragments.uptime);
    document.querySelector("#disquesTable tbody")?.replaceChildren(fragments.disques);
    document.querySelector("#memoryTable tbody")?.replaceChildren(fragments.memory);
    document.querySelector("#updateTable tbody")?.replaceChildren(fragments.update);
    document.querySelector("#rebootTable tbody")?.replaceChildren(fragments.reboot);
    const currentView = localStorage.getItem("activeMainView");
    if (currentView === "switchs" || currentView === "aps" || currentView === "others") loadNetworkData(currentView);
}
async function runManualRefresh() {
    const btn = document.getElementById("refreshNowBtn"), status = document.getElementById("refreshStatus");
    btn.disabled = true; status.textContent = t('status_launching', "Lancement...");
    await fetch("run_update.php", { method: "POST" });
    setTimeout(() => { btn.disabled = false; }, 3000);
    pollUpdateStatus();
}
document.querySelectorAll(".tab").forEach(tab => {
    tab.onclick = () => {
        document.querySelectorAll(".tab,.tab-content").forEach(e => e.classList.remove("active"));
        tab.classList.add("active");
        document.getElementById(tab.dataset.tab).classList.add("active");
        localStorage.setItem("activeTab", tab.dataset.tab);
    };
});
document.addEventListener("DOMContentLoaded", () => {
    pollUpdateStatus();
    setInterval(pollUpdateStatus, 2000);
    const refreshBtn = document.getElementById("refreshNowBtn");
    if (refreshBtn) refreshBtn.onclick = runManualRefresh;
    const refreshBtnReboot = document.getElementById("refreshNowBtnReboot");
    if (refreshBtnReboot) refreshBtnReboot.onclick = runManualRefresh;
    refreshDashboard();
    setInterval(refreshDashboard, 120000);
    const activeTab = localStorage.getItem("activeTab") || "uptime", tabToActivate = document.querySelector(`.tab[data-tab="${activeTab}"]`), contentToActivate = document.getElementById(activeTab);
    if (tabToActivate && contentToActivate) { tabToActivate.classList.add("active"); contentToActivate.classList.add("active"); }
});
