<?php
require_once '/var/www/common/init.php';
if(!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true){ header("Location:index.php"); exit(); }
$currentLang = $_SESSION['lang'] ?? 'fr';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang) ?>">
<head>
    <meta charset="UTF-8">
    <title><?= __('uptimepc_page_title', 'Status des postes') ?></title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="/assets/style.css?v=4">
</head>
<body class="details uptime-page <?= !empty($isDark) ? 'dark-mode' : '' ?>">
<div id="main">
    <div class="header-main">
        <h1><?= __('uptimepc_main_title', 'Uptime & update des postes') ?></h1>
	<div class="header-buttons">
	    <a href="dashboard.php" class="btn btn-gray"><?= __('btn_back_home', 'Retour à l\'accueil') ?></a>
	    <button id="btnExportCsv" class="btn btn-blue" onclick="exportFilteredTableToCSV()" title="<?= __('btn_export_csv_title', 'Télécharger les données filtrées en CSV pour Excel') ?>">
            📥 <?= __('btn_export_csv', 'Télécharger (CSV)') ?></button>
            <button id="toggleDarkMode" class="btn btn-gray"><?= __('btn_dark_mode', 'Mode 🌙') ?></button>
            <a href="logout.php" class="btn btn-red"><?= __('btn_logout', 'Se déconnecter') ?></a>
        </div>
    </div>
    <div class="stat-card">
        <h3 class="card-title"><?= __('card_pc_status', 'État des machines') ?></h3>
        <div class="search-container">
            <div class="search-input-wrapper">
                <input type="text" id="searchInput" placeholder="<?= __('search_pc_placeholder', '🔍 Rechercher un PC, un détail (ou taper null pour détails vides)...') ?>" oninput="handleSearchInput()">
                <span id="clearSearchBtn" onclick="clearSearch()">&times;</span>
            </div>
            <span id="rowCount">0 <?= __('lbl_results_suffix', 'résultat(s)') ?></span>
            <div class="search-filters">
                <label class="filter-label"><input type="checkbox" id="offline21Checkbox" onchange="filterTable(); saveFilters();"> <strong><?= __('filter_offline_21d', 'Hors ligne > 21j') ?></strong></label>
                <label class="filter-label"><input type="checkbox" id="bigTimeCheckbox" onchange="filterTable(); saveFilters();"> <strong><?= __('filter_big_time', 'Big Time') ?></strong> (<?= __('filter_big_time_desc', '> 15j & en ligne') ?>)</label>
            </div>
        </div>
        <table id="uptimeTable">
            <thead>
                <tr>
                    <th onclick="sortTable('uptimeTable', 0, 'text')"><?= __('col_computer', 'Ordinateur') ?></th>
                    <th><?= __('col_user', 'Utilisateur') ?></th>
                    <th><?= __('col_site', 'Site') ?></th>
                    <th><?= __('col_ip', 'IP') ?></th>
                    <th onclick="sortTable('uptimeTable', 4, 'uptime')"><?= __('col_uptime', 'Uptime') ?></th>
                    <th onclick="sortTable('uptimeTable', 5, 'status')"><?= __('col_status', 'Status') ?></th>
                    <th onclick="sortTable('uptimeTable', 6, 'date')"><?= __('col_last_ok_response', 'Dernière réponse OK') ?></th>
                    <th onclick="sortTable('uptimeTable', 7, 'number')"><?= __('col_updates', 'MAJ') ?></th>
                    <th onclick="sortTable('uptimeTable', 8, 'number')"><?= __('col_since', 'Depuis') ?></th>
                    <th><?= __('col_details', 'Détails') ?></th>
                    <th>WakeMe</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>
<button id="backToTop" title="<?= __('btn_back_to_top', 'Retour en haut') ?>"><span>↑</span></button>
<script src="/assets/darkmode.js"></script>
<script>
const I18N = {
    lang: "<?= htmlspecialchars($currentLang) ?>",
    wakeBtn: "<?= __('btn_wake', 'Réveiller') ?>",
    wakeConfirm: "<?= __('confirm_wake_pc', 'Réveiller %s ?') ?>",
    waking: "<?= __('btn_waking', 'Envoi...') ?>",
    woken: "<?= __('btn_woken', 'Envoyé ✓') ?>",
    retry: "<?= __('btn_retry', 'Réessayer') ?>",
    sendFailed: "<?= __('err_send_failed', 'Échec de l’envoi') ?>",
    macMissing: "<?= __('lbl_mac_missing', 'MAC absente') ?>",
    sinceMin: "<?= __('lbl_since_min', 'Depuis %d min') ?>",
    sinceHours: "<?= __('lbl_since_hours', 'Depuis %d h') ?>",
    sinceDays: "<?= __('lbl_since_days', 'Depuis %d j') ?>",
    dayUnit: "<?= __('lbl_day_unit_short', 'j') ?>",
    resultSingle: "<?= __('lbl_result_single', '%d résultat') ?>",
    resultPlural: "<?= __('lbl_result_plural', '%d résultats') ?>"
};

function parseUptime(str) {
    let total = 0; if (!str) return total;
    const regex = /(\d+)\s*(jour|day|hour|heure|minute|dag|giorno|tag|dia|hora|uur|stunde|ora)s?/gi; let match;
    while ((match = regex.exec(str)) !== null) {
        const val = parseInt(match[1]), unit = match[2].toLowerCase();
        if (unit.startsWith('jour') || unit.startsWith('day') || unit.startsWith('dag') || unit.startsWith('tag') || unit.startsWith('dia')) total += val * 86400;
        else if (unit.startsWith('heur') || unit.startsWith('hour') || unit.startsWith('uur') || unit.startsWith('stunde') || unit.startsWith('ora')) total += val * 3600;
        else if (unit.startsWith('min')) total += val * 60;
    }
    return total;
}
function saveFilters() {
    sessionStorage.setItem("uptimeSearch", document.getElementById("searchInput").value);
    sessionStorage.setItem("offline21Only", document.getElementById("offline21Checkbox").checked);
    sessionStorage.setItem("bigTimeOnly", document.getElementById("bigTimeCheckbox").checked);
}
function restoreFilters() {
    const search = sessionStorage.getItem("uptimeSearch"), offline21 = sessionStorage.getItem("offline21Only"), bigTime = sessionStorage.getItem("bigTimeOnly");
    if (search !== null) document.getElementById("searchInput").value = search;
    if (offline21 !== null) document.getElementById("offline21Checkbox").checked = offline21 === "true";
    if (bigTime !== null) document.getElementById("bigTimeCheckbox").checked = bigTime === "true";
    handleSearchInput();
}
function handleSearchInput() {
    const input = document.getElementById("searchInput"), clearBtn = document.getElementById("clearSearchBtn");
    clearBtn.style.display = input.value.length > 0 ? "block" : "none";
    saveFilters(); filterTable();
}
function clearSearch() {
    const input = document.getElementById("searchInput"); input.value = "";
    handleSearchInput(); input.focus();
}
function filterTable() {
    const filter = document.getElementById("searchInput").value.toLowerCase().trim(), bigTimeOnly = document.getElementById("bigTimeCheckbox").checked, offline21Only = document.getElementById("offline21Checkbox").checked, rows = document.querySelectorAll("#uptimeTable tbody tr");
    rows.forEach(row => {
        const computer = row.children[0]?.textContent.toLowerCase() || "", user = row.children[1]?.textContent.toLowerCase() || "", site = row.children[2]?.textContent.toLowerCase() || "", ip = row.children[3]?.textContent.toLowerCase() || "", uptimeText = row.children[4]?.textContent || "", details = row.children[9]?.textContent.toLowerCase().trim() || "", statusText = row.dataset.status || "", lastOfflineStr = row.dataset.lastOffline || "";
        let visible = false;
        if (!filter) visible = true;
        else if (filter === "null" || filter === "vide" || filter === "empty") visible = (details === "");
        else if (filter === "!null" || filter === "!vide" || filter === "!empty") visible = (details !== "");
        else if (filter === "!ok") visible = statusText !== "OK";
        else if (filter === "ok") visible = statusText === "OK";
        else if (filter === "no_response" || filter === "no response") visible = statusText === "NO_RESPONSE";
        else {
            const searchTerms = filter.split(/\s+/).filter(Boolean);
            visible = searchTerms.every(term => computer.includes(term) || user.includes(term) || site.includes(term) || ip.includes(term) || details.includes(term) || statusText.toLowerCase().includes(term));
        }
        if (bigTimeOnly) {
            const isOnline = statusText === "OK", uptimeSec = parseUptime(uptimeText), isBigTime = isOnline && (uptimeSec >= 16 * 86400);
            if (!isBigTime) visible = false;
        }
        if (offline21Only) {
            const isOffline = statusText !== "OK", isDetailEmpty = (details === "");
            let isOfflineOver21Days = false;
            if (isOffline && lastOfflineStr) {
                const lastOfflineDate = new Date(lastOfflineStr.replace(" ", "T")), diffMs = Date.now() - lastOfflineDate.getTime(), OFFLINE_LIMIT_MS = (21 * 24 * 60 * 60 * 1000) + (30 * 60 * 1000);
                if (!isNaN(lastOfflineDate.getTime()) && diffMs >= OFFLINE_LIMIT_MS) isOfflineOver21Days = true;
            }
            if (!isOfflineOver21Days || !isDetailEmpty) visible = false;
        }
        row.style.display = visible ? "" : "none";
    });
    const visibleCount = Array.from(rows).filter(r => r.style.display !== "none").length, countSpan = document.getElementById("rowCount");
    if (countSpan) {
        countSpan.textContent = visibleCount > 1 
            ? I18N.resultPlural.replace("%d", visibleCount) 
            : I18N.resultSingle.replace("%d", visibleCount);
    }
}
function saveSort() {
    const table = document.getElementById("uptimeTable");
    if (table.dataset.sortCol !== undefined) {
        sessionStorage.setItem("uptimeSortCol", table.dataset.sortCol);
        sessionStorage.setItem("uptimeSortType", table.dataset.sortType || "text");
        sessionStorage.setItem("uptimeSortOrder", table.dataset.sortOrder || "asc");
    }
}
function restoreSort() {
    const col = sessionStorage.getItem("uptimeSortCol"), type = sessionStorage.getItem("uptimeSortType"), order = sessionStorage.getItem("uptimeSortOrder");
    if (col !== null && order !== null) sortTable("uptimeTable", parseInt(col, 10), type || "text", order);
}
function parseOfflineDuration(text) {
    const match = text.match(/(?:Depuis|Since|Sinds|Da|Seit|Desde)\s+(\d+)\s*(min|h|j|d|g|t)/i);
    if (!match) return -1;
    const value = parseInt(match[1], 10), unit = match[2].toLowerCase();
    if (unit === "min") return value;
    if (unit === "h" || unit === "t") return value * 60;
    if (unit === "j" || unit === "d" || unit === "g") return value * 1440;
    return -1;
}
function sortTable(tableId, colIndex, type = "text", forcedOrder = null) {
    const table = document.getElementById(tableId), tbody = table.querySelector("tbody"), rows = Array.from(tbody.querySelectorAll("tr")), ths = table.querySelectorAll("th"), asc = forcedOrder ? forcedOrder === "asc" : table.dataset.sortOrder !== "asc";
    table.dataset.sortOrder = asc ? "asc" : "desc"; table.dataset.sortCol = colIndex; table.dataset.sortType = type;
    ths.forEach(th => th.classList.remove("sort-asc", "sort-desc"));
    ths[colIndex].classList.add(asc ? "sort-asc" : "sort-desc");
    rows.sort((a, b) => {
        let A = a.children[colIndex].innerText.trim(), B = b.children[colIndex].innerText.trim();
        switch (type) {
            case "uptime": A = parseUptime(A); B = parseUptime(B); break;
            case "number": A = parseInt(A.replace(/[^\d-]/g, ""), 10); B = parseInt(B.replace(/[^\d-]/g, ""), 10); if (isNaN(A)) A = -1; if (isNaN(B)) B = -1; break;
            case "status": A = parseOfflineDuration(A); B = parseOfflineDuration(B); break;
            case "text": default: return asc ? A.localeCompare(B, I18N.lang, { numeric: true }) : B.localeCompare(A, I18N.lang, { numeric: true });
        }
        return asc ? A - B : B - A;
    });
    tbody.append(...rows);
    saveSort();
}
function exportFilteredTableToCSV() {
    const table = document.getElementById("uptimeTable");
    if (!table) return;
    const headers = [];
    const ths = table.querySelectorAll("thead th");
    for (let i = 0; i < ths.length - 1; i++) {
        headers.push('"' + ths[i].innerText.trim().replace(/"/g, '""') + '"');
    }
    const csvRows = [];
    csvRows.push(headers.join(";"));
    const rows = table.querySelectorAll("tbody tr");
    rows.forEach(row => {
        if (row.style.display !== "none") {
            const rowData = [];
            const cells = row.querySelectorAll("td");
            for (let i = 0; i < cells.length - 1; i++) {
                let cellText = cells[i].innerText.trim();
                cellText = cellText.replace(/\r?\n|\r/g, " ");
                rowData.push('"' + cellText.replace(/"/g, '""') + '"');
            }
            csvRows.push(rowData.join(";"));
        }
    });
    if (csvRows.length <= 1) {
        alert("<?= __('msg_no_data_to_export', 'Aucune donnée à exporter.') ?>");
        return;
    }
    const csvContent = "\uFEFF" + csvRows.join("\r\n");
    const blob = new Blob([csvContent], { type: "text/csv;charset=utf-8;" });
    const dateStr = new Date().toISOString().slice(0, 10);
    const fileName = `export_uptime_pc_${dateStr}.csv`;
    const link = document.createElement("a");
    const url = URL.createObjectURL(blob);
    link.setAttribute("href", url);
    link.setAttribute("download", fileName);
    link.style.visibility = "hidden";
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
async function loadUptime() {
    try {
        const response = await fetch('/data/pc/users.json?v=' + Date.now());
        if (!response.ok) return;
        const usersData = await response.json();
        const tbody = document.querySelector("#uptimeTable tbody");
        tbody.innerHTML = "";
        Object.entries(usersData).forEach(([computer, data]) => {
            const compName = computer.toUpperCase();
            const status = data.status || "NO_RESPONSE";
            const pcIp = String(data.ip || "").trim();
            const uptime = data.uptime || "";
            const user = String(data.user || "");
            const site = String(data.site || "");
            const pcDetail = String(data.detail || "");
            const mac = String(data.mac || "").trim();
            let statusClass = status === "OK" ? "status-ok" : "status-ko";
            let uptimeClass = "";
            const match = uptime.match(/(\d+)\s*(jours?|days?|dagen?|giorn[io]|tage?|dias?)/i);
            if (match && parseInt(match[1]) >= 15) uptimeClass = "uptime-warning";
            let timestampClass = "", last = "N/A";
            if (data.last_success) {
                const date = new Date(data.last_success.replace(" ", "T"));
                if (!isNaN(date.getTime())) {
                    last = date.toLocaleString(I18N.lang);
                    const ageH = (Date.now() - date.getTime()) / 3600000;
                    if (status !== "OK") timestampClass = "timestamp-warn";
                    else if (ageH <= 1) timestampClass = "timestamp-ok";
                    else if (ageH <= 168) timestampClass = "timestamp-warn";
                    else timestampClass = "timestamp-old";
                }
            }
            const wolButton = mac 
                ? `<button class="wol-btn" data-computer="${compName}" data-mac="${mac}" data-ip="${pcIp}" onclick="wakeOnLan(this)">${I18N.wakeBtn}</button>` 
                : `<span class="wol-unavailable">${I18N.macMissing}</span>`;
            let offlineSince = "";
            if (status !== "OK" && data.last_offline) {
                const d = new Date(data.last_offline.replace(" ", "T")), 
                      diff = Math.floor((Date.now() - d.getTime()) / 60000) + 30;
                if (diff < 60) offlineSince = `<br><small>${I18N.sinceMin.replace("%d", diff)}</small>`;
                else if (diff < 1440) offlineSince = `<br><small>${I18N.sinceHours.replace("%d", Math.floor(diff / 60))}</small>`;
                else offlineSince = `<br><small>${I18N.sinceDays.replace("%d", Math.floor(diff / 1440))}</small>`;
            }
            const updateCount = data.updates !== undefined && data.updates !== null ? data.updates : "N/A";
            let updateDays = "N/A";
            if (data.updates === 0 && data.update_days === null) updateDays = "0";
            else if (data.update_days !== undefined && data.update_days !== null) updateDays = `${data.update_days} ${I18N.dayUnit}`;
            const tr = document.createElement("tr");
            tr.dataset.computer = compName;
            tr.dataset.status = status;
            tr.dataset.uptime = uptime;
            tr.dataset.lastSuccess = data.last_success || "";
            tr.dataset.lastOffline = data.last_offline || "";
            tr.dataset.detail = pcDetail;
            tr.innerHTML = `
                <td>${compName}</td>
                <td>${user}</td>
                <td>${site}</td>
                <td>${pcIp}</td>
                <td class="${uptimeClass}">${uptime || "N/A"}</td>
                <td class="${statusClass}">${status}${offlineSince}</td>
                <td class="${timestampClass}">${last}</td>
                <td>${updateCount}</td>
                <td>${updateDays}</td>
                <td>${pcDetail}</td>
                <td>${wolButton}</td>
            `;
            tbody.appendChild(tr);
        });
        restoreSort();
        filterTable();
    } catch (error) { 
        console.error("Erreur :", error); 
    }
}
async function wakeOnLan(button) {
    const computer = button.dataset.computer, mac = button.dataset.mac, ip = button.dataset.ip;
    if (!confirm(I18N.wakeConfirm.replace("%s", computer))) return;
    button.disabled = true; button.textContent = I18N.waking;
    try {
        const response = await fetch("wakeonlan.php", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ mac, ip }) }), result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || I18N.sendFailed);
        button.textContent = I18N.woken;
        setTimeout(() => { button.textContent = I18N.wakeBtn; button.disabled = false; }, 3000);
    } catch (error) {
        alert(error.message); button.textContent = I18N.retry; button.disabled = false;
    }
}
restoreFilters();
loadUptime();
setInterval(loadUptime, 60000);
const backToTopBtn = document.getElementById("backToTop");
window.addEventListener("scroll", () => { backToTopBtn.style.display = window.scrollY > 300 ? "block" : "none"; });
backToTopBtn.addEventListener("click", () => { window.scrollTo({ top: 0, behavior: "smooth" }); });
</script>
</body>
</html>
