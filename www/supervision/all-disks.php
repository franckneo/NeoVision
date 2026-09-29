<?php
require_once '/var/www/common/init.php';
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) { header("Location:index.php"); exit(); }
$LANG = $LANG ?? [];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang ?? 'fr') ?>">
<head>
    <meta charset="UTF-8">
    <title><?= __('all_disks_title', 'Utilisation des disques - Tous les serveurs') ?></title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>
    <link rel="stylesheet" href="/assets/style.css?v=3">
    <script>
    const I18N = <?= json_encode($LANG, JSON_UNESCAPED_UNICODE) ?>;
    function t(k, fallback) { return I18N[k] || fallback || k; }
    </script>
</head>
<body class="all-disks <?= !empty($isDark) ? 'dark-mode' : '' ?>">
<div class="header">
    <h1><?= __('all_disks_title', 'Utilisation des disques - Tous les serveurs') ?></h1>
    <div class="header-buttons">
        <a href="dashboard.php" class="btn btn-gray"><?= __('btn_back_home', 'Retour à l\'accueil') ?></a>
        <a href="history.php" class="btn btn-gray"><?= __('btn_history', 'Historique') ?></a>
        <a href="mailing.php" class="btn btn-blue">✉️</a>
        <button id="toggleDarkMode" class="btn btn-gray"><?= __('btn_darkmode', 'Mode 🌙') ?></button>
        <a href="logout.php" class="btn btn-red"><?= __('btn_logout', 'Se déconnecter') ?></a>
    </div>
</div>
<div id="main-content">
    <div class="chart-container"><canvas id="allDisksChart"></canvas></div>
</div>
<script src="/assets/darkmode.js"></script>
<script>
document.addEventListener("DOMContentLoaded", () => {
    let allDisksChartInstance = null;
    async function fetchAllDisksData() {
        try {
            const serversResponse = await fetch('/api/data/servers.json');
            if (!serversResponse.ok) throw new Error('Impossible de charger servers.json');
	    const raw = await serversResponse.json();
            const servers = Array.isArray(raw) ? raw : (raw.servers || []);
	    const antiCache = `?v=${Date.now()}`;
            const promises = servers.map(server => fetch(`/api/data/${server.name}_disks.json${antiCache}`).then(r => r.ok ? r.json() : []));
            const allDisksData = await Promise.all(promises);
            return { servers, allDisksData };
        } catch (e) { console.error("Erreur chargement données :", e); return { servers: [], allDisksData: [] }; }
    }
    function createAllDisksChart(servers, allDisksData) {
        if (allDisksChartInstance) allDisksChartInstance.destroy();
        const datasets = [];
        const colors = ['#e74c3c','#f1c40f','#2ecc71','#3498db','#9b59b6','#1abc9c','#f39c12','#95a5a6'];
        let colorIndex = 0;
	const cutoffDate = new Date();
        cutoffDate.setDate(cutoffDate.getDate() - 90);
	servers.forEach((server, idx) => {
            const diskHistory = allDisksData[idx] || [];
            const validEntries = diskHistory.filter(e => e.disks && typeof e.disks === "object" && !e.disks.error);
            const diskNames = new Set();
            validEntries.forEach(e => Object.keys(e.disks).forEach(d => diskNames.add(d)));
            [...diskNames].sort().forEach(disk => {
                let totalSize = null;
                const points = validEntries.filter(e => e.disks[disk]).map(e => {
                    totalSize = e.disks[disk].total;
                    return { x: new Date(e.timestamp), y: parseFloat(e.disks[disk].used_percent), total: e.disks[disk].total };
                }).filter(pt => pt.x >= cutoffDate).sort((a,b) => a.x - b.x);
                if (points.length) {
                    datasets.push({ label: `${server.name} - ${disk}`, data: points, borderColor: colors[colorIndex++ % colors.length], borderWidth: 2, pointRadius: 0, tension: 0.15, fill: false, total_size: totalSize });
                }
            });
        });
        allDisksChartInstance = new Chart(document.getElementById('allDisksChart'), {
            type: 'line',
            data: { datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'nearest', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: ctx => [`${ctx.dataset.label} : ${ctx.parsed.y}%`, ctx.raw && ctx.raw.total ? `${t('label_capacity', 'Capacité')} : ${ctx.raw.total}` : ''] } }
                },
                scales: {
                    x: { type: 'time', time: { unit: 'day', displayFormats: { day: 'dd/MM' } }, grid: { color: 'rgba(128,128,128,0.2)' } },
                    y: { min: 0, max: 100, ticks: { stepSize: 10, callback: v => v + '%' }, grid: { color: 'rgba(128,128,128,0.2)' } }
                }
            }
        });
    }
    async function refreshAllDisks() {
        const { servers, allDisksData } = await fetchAllDisksData();
        if (servers.length && allDisksData.length) createAllDisksChart(servers, allDisksData);
    }
    refreshAllDisks();
    setInterval(refreshAllDisks, 300000);
});
</script>
</body>
</html>
