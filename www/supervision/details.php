<?php
require_once '/var/www/common/init.php';
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) { header("Location:index.php"); exit(); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'refresh') {
    session_write_close();
    header('Content-Type: application/json');
    $server = isset($_POST['server']) ? preg_replace('/[^a-zA-Z0-9_\-]/', '', $_POST['server']) : '';
    if (!empty($server)) {
        $acc = json_decode(@file_get_contents('/opt/supervision/data/accounts.json'), true);
        $localUser = (!empty($acc['local_user']) && posix_getpwnam($acc['local_user'])) ? $acc['local_user'] : 'root';
        $prefix = ($localUser !== 'root') ? "/usr/bin/sudo -u " . escapeshellarg($localUser) . " " : "";
        $cmd = "{$prefix}/opt/supervision/venv/bin/python /opt/supervision/refresh_server.py " . escapeshellarg($server) . " 2>&1";
        exec($cmd, $output, $returnCode);
        echo json_encode(['success' => ($returnCode === 0)]);
    } else {
        echo json_encode(['success' => false, 'error' => __('msg_invalid_server', 'Serveur invalide')]);
    }
    exit();
}
$LANG = $LANG ?? [];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang ?? 'fr') ?>">
<head>
<meta charset="UTF-8">
<title><?= __('details_page_title', 'Détails du serveur') ?></title>
<link rel="icon" href="favicon.ico" type="image/x-icon">
<link rel="stylesheet" href="/assets/style.css?v=2">
<script>
const I18N = <?= json_encode($LANG, JSON_UNESCAPED_UNICODE) ?>;
function t(k, fallback) { return I18N[k] || fallback || k; }
const CURRENT_LOCALE = '<?= ($currentLang === "en") ? "en-US" : (($currentLang === "de") ? "de-DE" : (($currentLang === "es") ? "es-ES" : (($currentLang === "it") ? "it-IT" : (($currentLang === "nl") ? "nl-NL" : (($currentLang === "pt") ? "pt-PT" : "fr-FR"))))) ?>';
</script>
</head>
<body class="details <?= (!empty($_COOKIE['darkMode']) && $_COOKIE['darkMode'] === 'enabled') ? 'dark-mode' : '' ?>">
<div id="sidebar">
    <a href="all-disks.php" class="btn btn-blue all-disks-link"><?= __('btn_all_disks', 'All Disks') ?></a>
    <h2><?= __('sidebar_servers', 'Serveurs') ?></h2>
    <div class="sidebar-servers-container"><ul id="serverList"></ul></div>
    <div class="sidebar-footer"><a href="param.php" class="btn btn-gray sidebar-param-btn"><?= __('btn_settings', 'Paramètres') ?></a></div>
</div>
<div id="main">
    <div class="header-main">
        <div>
            <div class="server-title-row">
                <h1 id="serverName"></h1>
                <button id="refreshBtn" class="btn btn-blue" onclick="triggerRefresh()"><?= __('btn_refresh', 'Actualiser 🔄') ?></button>
            </div>
            <div id="serverOs" class="server-os"></div>
        </div>
        <div class="header-buttons">
            <a href="dashboard.php" class="btn btn-gray"><?= __('btn_back_home', 'Retour à l\'accueil') ?></a>
            <a href="mailing.php" class="btn btn-blue">✉️</a>
            <a href="history.php" class="btn btn-gray"><?= __('btn_history', 'Historique') ?></a>
            <button id="toggleDarkMode" class="btn btn-gray"><?= __('btn_darkmode', 'Mode 🌙') ?></button>
            <a href="logout.php" class="btn btn-red"><?= __('btn_logout', 'Se déconnecter') ?></a>
        </div>
    </div>
    <div class="stats-row" id="statsRow"></div>
    <div class="stats-row" id="servicesRow"></div>
    <div class="stat-card">
        <h3><?= __('chart_disks_title', 'Utilisation des disques (90 derniers jours)') ?></h3>
        <div class="chart-container"><canvas id="disksChart"></canvas></div>
    </div>
    <div class="stat-card">
        <h3><?= __('chart_ram_title', 'Utilisation de la RAM (24 dernières heures)') ?></h3>
        <div class="chart-container chart-ram"><canvas id="ramChart"></canvas></div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>
<script src="/assets/darkmode.js"></script>
<script>
let ramChartInstance=null, disksChartInstance=null, currentServer=null, SERVER_ALERT_CONFIG=null;
async function triggerRefresh() {
    if (!currentServer) return;
    const btn = document.getElementById("refreshBtn"), originalText = btn.textContent;
    btn.textContent = t('btn_refreshing', 'Mise à jour...'); btn.disabled = true;
    const formData = new FormData();
    formData.append('action', 'refresh'); formData.append('server', currentServer);
    try {
        const response = await fetch('details.php', { method: 'POST', body: formData }), result = await response.json();
        if (!result.success) throw new Error(result.error || t('msg_refresh_failed', 'Échec du rafraîchissement'));
        await refreshAll();
        const servers = await fetchServers();
        renderServerList(servers);
    } catch (e) { alert(t('msg_refresh_error', 'Erreur lors de l\'actualisation')); }
    finally { btn.textContent = originalText; btn.disabled = false; }
}
async function fetchServerAlertConfig(){if(SERVER_ALERT_CONFIG)return SERVER_ALERT_CONFIG;try{const r=await fetch('/api/data/alert_config.json?v='+Date.now());SERVER_ALERT_CONFIG=r.ok?await r.json():{};}catch(e){SERVER_ALERT_CONFIG={};}return SERVER_ALERT_CONFIG;}
async function fetchServers(){
    try {
        const r = await fetch('/api/data/dashboard.json?v=' + Date.now());
        if (r.ok) { const data = await r.json(); return data.servers || []; }
    } catch (e) {}
    const rFallback = await fetch('/api/data/servers.json?v=' + Date.now());
    if (!rFallback.ok) return [];
    const fbData = await rFallback.json();
    return Array.isArray(fbData) ? fbData : (fbData.servers || []);
}
function computeServerLevel(s,cfg){
    if(!s)return"unknown";
    const srvCfg=(cfg&&cfg[s.name])?cfg[s.name]:{};
    let lvl='ok';
    const upStr=String(s.uptime||'').toLowerCase();
    if(upStr.includes('injoignable')||upStr.includes('unreachable'))return'critical';
    const preHealth=(typeof s.health==='string'&&s.health)?s.health.toLowerCase():null;
    if(preHealth==='critical')return'critical';if(preHealth==='warning')lvl='warning';
    const se=Array.isArray(s.status?.service_errors)?s.status.service_errors:(Array.isArray(s.service_errors)?s.service_errors:[]);
    if(se.length>0)return'critical';
    const ramObj=Array.isArray(s.ram)&&s.ram.length?s.ram[0]:(s.ram&&typeof s.ram==='object'?s.ram:null);
    if(ramObj&&ramObj.percent!==undefined&&ramObj.percent!==null){
        const pct=Number(ramObj.percent),rcEn=srvCfg.ram_crit_enabled??srvCfg.ram_enabled??false,rwEn=srvCfg.ram_warn_enabled??false;
        if(rcEn&&pct>=Number(srvCfg.ram_crit_threshold??srvCfg.ram_threshold??90))return'critical';
        if(rwEn&&pct>=Number(srvCfg.ram_warn_threshold??80)&&lvl==='ok')lvl='warning';
    }
    const dObj=Array.isArray(s.disks)&&s.disks.length?s.disks:(s.disks?.disks?s.disks.disks:null);
    if(dObj){
        const list=Array.isArray(dObj)?dObj.map(d=>({n:d.mount||d.filesystem||d.name||d.device||"/",p:parseFloat(String(d.used_percent??d.percent??0).replace('%',''))||0})):Object.entries(dObj).map(([k,v])=>({n:k,p:parseFloat(String(v?.used_percent??v?.percent??0).replace('%',''))||0}));
        const dCfg=srvCfg.disks||{};
																				  
																											   
																	
						 
        for(const d of list){
            const c=dCfg[d.n]||{},ce=c.crit_enabled??c.enabled??false,we=c.warn_enabled??false;
            if(ce&&d.p>=Number(c.crit_threshold??c.threshold??90))return'critical';
            if(we&&d.p>=Number(c.warn_threshold??80)&&lvl==='ok')lvl='warning';
        }
    }
    if(s.status?.reboot_required||s.reboot_required)if(lvl==='ok')lvl='warning';
    const jh=s.health&&typeof s.health==='object'?s.health.level:null;
    if(jh==='critical')return'critical';if(jh==='warning'&&lvl==='ok')lvl='warning';
    const rawTs=s.uptime_timestamp||s.status?.status_file_timestamp||s.status_file_timestamp;
    if(rawTs){
        let tsMs=NaN;
        if(typeof rawTs==='number')tsMs=rawTs>1e11?rawTs:rawTs*1000;
        else if(typeof rawTs==='string'){
            const cl=rawTs.trim();
            if(/^\d{2}\/\d{2}\/\d{4}/.test(cl)){const p=cl.split(/[\sT]+/),dp=p[0].split('/');tsMs=new Date(`${dp[2]}-${dp[1]}-${dp[0]}T${p[1]||'00:00:00'}`).getTime();}
            else{tsMs=new Date(cl.replace(' ','T')).getTime();if(isNaN(tsMs))tsMs=new Date(cl).getTime();}
															   
																	   
																															  
															   
				 
			 
											
        }
        if(!isNaN(tsMs)){
            const age=(Date.now()-tsMs)/60000;
            if(age>=Number(srvCfg.uptime_crit_threshold??srvCfg.uptime_threshold??20))return'critical';
            if(age>=Number(srvCfg.uptime_warn_threshold??15)&&lvl==='ok')lvl='warning';
        }else return'critical';
    }else return'critical';
    const ping=typeof s.ping==='object'?s.ping:{status:s.ping||'unknown'};
    if((srvCfg.offline_enabled??true)&&(ping.status==='offline'||(ping.ping_ok===false&&ping.status!=='unknown'))){
        const cl=Number(ping.consecutive_losses??s.consecutive_losses??0),ml=Math.max(1,Number(srvCfg.max_loss??ping.ping_max_loss??s.ping_max_loss??1));
        if(cl>=ml)return'critical';if(cl>0&&lvl==='ok')lvl='warning';
    }
    return lvl;
}
async function renderServerList(servers){
    const cfg=await fetchServerAlertConfig(),ul=document.getElementById("serverList");
    ul.innerHTML="";
						  
											
    servers.forEach(s=>{const lvl=computeServerLevel(s,cfg);ul.innerHTML+=`<li style="display:flex;align-items:center;gap:6px;"><span class="dot dot-${lvl}" id="dot-${s.name}"></span><a href="details.php?server=${s.name}">${s.name}</a></li>`;});
	   
}
async function refreshAll(){
    if(!currentServer) return;
    const antiCache = `?${Date.now()}`;
    const [uptime, status, ram, disks, services, osData] = await Promise.all([
        fetch(`/api/data/${currentServer}_uptime.json${antiCache}`).then(r=>r.json()).catch(()=>null),
        fetch(`/api/data/${currentServer}_status.json${antiCache}`).then(r=>r.json()).catch(()=>null),
        fetch(`/api/data/${currentServer}_ram.json${antiCache}`).then(r=>r.json()).catch(()=>[]),
        fetch(`/api/data/${currentServer}_disks.json${antiCache}`).then(r=>r.json()).catch(()=>[]),
        fetch(`/api/data/${currentServer}_services.json${antiCache}`).then(r=>r.json()).catch(()=>null),
        fetch(`/api/data/${currentServer}_os.json${antiCache}`).then(r=>r.json()).catch(()=>null)
    ]);
    const osEl = document.getElementById("serverOs");
    if (osEl) {
        osEl.textContent = osData && osData.os ? `OS : ${osData.os}` : '';
    }
    renderStats(uptime, status); renderServices(services); renderRamChart(ram); renderDisksChart(disks);
}
function renderStats(uptime, status) {
    let uptimeClass = 'no', uptimeVal = uptime?.uptime || t('val_unreachable', 'Injoignable');
    const unreachable = !uptime || !uptime.uptime || uptime.uptime === 'Injoignable';
    if (unreachable) { uptimeClass = 'error'; }
    else {
        const days = Number(uptime.uptime_days ?? 0);
        if (days >= 90) uptimeClass = 'error';
        else if (days >= 30) uptimeClass = 'warning_stats';
    }
    const hasUpdates = (status?.status?.updates_pending > 0);
    const updateClass = hasUpdates ? 'warning_stats' : 'no';
    document.getElementById("statsRow").innerHTML = `<div class="stats-item ${uptimeClass}"><div class="stats-label">${t('label_uptime', 'Uptime')}</div><div class="stats-value">${uptimeVal}</div></div><div class="stats-item ${updateClass}"><div class="stats-label">${t('label_updates', 'Updates')}</div><div class="stats-value">${status?.status?.updates_pending ?? 'N/A'}</div></div><div class="stats-item ${status?.status?.reboot_required ? 'warning_stats' : 'no'}"><div class="stats-label">${t('label_reboot', 'Reboot')}</div><div class="stats-value">${status?.status?.reboot_required ? t('val_yes', 'Oui') : t('val_no', 'Non')}</div></div>`;
}
function renderServices(servicesData) {
    const container = document.getElementById("servicesRow");
    if (!servicesData || !servicesData.services || Object.keys(servicesData.services).length === 0) { container.innerHTML = ""; return; }
    const serviceNames = {
        'powerbi_gateway': t('service_powerbi', 'Power BI Gateway'),
        'powerbi': t('service_powerbi', 'Power BI Gateway'),
        'outlook': t('service_outlook', 'Outlook'),
        'stagenow': t('service_stagenow', 'StageNow Client'),
        'wsus_service': t('service_wsus_svc', 'Service WSUS'),
        'wsus_apppool': t('service_wsus_pool', 'IIS WsusPool'),
        'wsus_port': t('service_wsus_port', 'Port WSUS (8530)'),
        'kea-dhcp-server': t('service_kea', 'Kea DHCP'),
        'squid': t('service_squid', 'Proxy Squid'),
        'webmin': t('service_webmin', 'Webmin')
    };
    let html = "";
    for (const [key, service] of Object.entries(servicesData.services)) {
        const displayName = serviceNames[key.toLowerCase()] || key, state = typeof service === "object" ? service.status ?? "unknown" : service;
        const isOk = ["active", "running", "ok", "listening", "open"].includes(String(state).toLowerCase()), cssClass = isOk ? "no" : "error";
        let details = "";
        if (typeof service === "object") {
            if (service.ports) details = Object.entries(service.ports).map(([port, status]) => `${t('label_port', 'Port')} ${port}: ${status}`).join(" | ");
            else if (service.port) details = `${t('label_port', 'Port')} ${service.port}`;
        }
        html += `<div class="stats-item ${cssClass}" style="flex: 0 0 calc(33.333% - 14px); max-width: calc(33.333% - 14px); box-sizing: border-box;"><div class="stats-label">${displayName}</div><div class="stats-value">${state}${details ? `<br><small>${details}</small>` : ""}</div></div>`;
    }
    container.innerHTML = html;
}
function renderRamChart(data){
    if(!Array.isArray(data) || !data.length) return;
    const now = new Date(), currentHour = now.getHours() + now.getMinutes() / 60;
    const points = data.map(entry => {
        const ts = new Date(entry.timestamp), ageHours = (now - ts) / 3600000;
        if(ageHours > 24) return null;
        let x = ts.getHours() + ts.getMinutes() / 60;
        const isToday = ts.getFullYear() === now.getFullYear() && ts.getMonth() === now.getMonth() && ts.getDate() === now.getDate();
        if(!isToday && x < currentHour) x += 24;
        return { x: x, y: Number(entry.percent), ts: ts };
    }).filter(Boolean).sort((a,b) => a.x - b.x);
    if(!points.length) return;
    const values = points.map(p => p.y), current = values[values.length - 1].toFixed(1), avg = (values.reduce((a,b)=>a+b,0) / values.length).toFixed(1), max = Math.max(...values).toFixed(1);
    const titleElement = document.querySelector("#ramChart").closest(".stat-card").querySelector("h3");
    titleElement.innerHTML = `${t('chart_ram_title', 'Utilisation de la RAM (24 dernières heures)')} <small style="font-weight:normal;">${t('chart_current', 'Current')}: ${current}% | ${t('chart_avg', 'Avg')}: ${avg}% | ${t('chart_max', 'Max')}: ${max}%</small>`;
    const mrtgMarker = {
        id: "mrtgMarker",
        afterDraw(chart){
            const ctx = chart.ctx, xScale = chart.scales.x, x = xScale.getPixelForValue(currentHour);
            ctx.save(); ctx.strokeStyle = "red"; ctx.lineWidth = 2; ctx.beginPath(); ctx.moveTo(x, chart.chartArea.top); ctx.lineTo(x, chart.chartArea.bottom); ctx.stroke(); ctx.restore();
        }
    };
    if(ramChartInstance) ramChartInstance.destroy();
    ramChartInstance = new Chart(document.getElementById("ramChart"), {
        type: "line",
        data: { datasets: [{ label: t('chart_ram_label', 'RAM (%)'), data: points, borderColor: "#3498db", borderWidth: 2, tension: 0.2, pointRadius: 0, pointHoverRadius: 5, hitRadius: 20 }] },
        plugins: [mrtgMarker],
        options: {
            responsive: true, maintainAspectRatio: false, parsing: false, interaction: { mode: "nearest", intersect: false },
            scales: {
                x: { type: "linear", min: 0, max: 24, ticks: { stepSize: 2, callback: v => String(Math.round(v) % 24).padStart(2,'0') + "h" } },
                y: { min: 0, max: 100, ticks: { callback: v => v + "%" } }
            },
            plugins: { legend: { display: false }, tooltip: { callbacks: { title: context => context[0].raw.ts.toLocaleString(CURRENT_LOCALE), label: context => `${t('chart_ram_label', 'RAM (%)')} : ${context.parsed.y}%` } } }
        }
    });
}
function renderDisksChart(rawData){
    const valid = rawData.filter(e => e.disks && !e.disks.error && Object.values(e.disks).some(d => d.used_percent)).reverse();
    if(!valid.length) return;
    if(disksChartInstance) disksChartInstance.destroy();
    const labels = valid.map(e => new Date(e.timestamp).toLocaleDateString(CURRENT_LOCALE)), disks = {};
    valid.forEach(e => { Object.entries(e.disks).forEach(([n,d]) => { if(d.used_percent){ disks[n] = disks[n] || []; disks[n].push(parseFloat(d.used_percent.replace('%',''))); } }); });
    disksChartInstance = new Chart(document.getElementById("disksChart"), {
        type: 'line', data: { labels, datasets: Object.keys(disks).map(d => ({ label: d, data: disks[d], tension: 0.1 })) },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: false, grace: '5%', ticks: { stepSize: 5, callback: v => v + '%' } } } }
    });
}
async function init(){
    const params = new URLSearchParams(window.location.search);
    currentServer = params.get("server");
    const servers = await fetchServers();
    if(currentServer) {
        const srv = servers.find(s => s.name === currentServer);
        if (srv && srv.ip) document.getElementById("serverName").textContent = `${t('details_of', 'Détails de')} ${currentServer} - ${srv.ip}`;
        else document.getElementById("serverName").textContent = `${t('details_of', 'Détails de')} ${currentServer}`;
    }
    renderServerList(servers);
    refreshAll();
    setInterval(refreshAll, 60000);
}
init();
</script>
</body>
</html>
