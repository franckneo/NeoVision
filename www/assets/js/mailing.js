const MAILING_I18N = {
    resultsSuffix: <?= json_encode(__('lbl_results_suffix', 'résultat(s)')) ?>,
    errorToggleGlobal: <?= json_encode(__('err_toggle_global_failed', 'Erreur lors de la mise à jour du statut global.')) ?>
};

function switchTab(tabId) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    const targetContent = document.getElementById(tabId);
    if (targetContent) targetContent.classList.add('active');
    document.querySelectorAll('.tab-btn').forEach(btn => {
        if (btn.getAttribute('onclick') && btn.getAttribute('onclick').includes(tabId)) btn.classList.add('active');
    });
    localStorage.setItem('active_mailing_tab', tabId);
    if (typeof handleSearchInput === 'function') handleSearchInput();
}
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const tabToOpen = urlParams.get('tab') || localStorage.getItem('active_mailing_tab');
    if (tabToOpen && document.getElementById(tabToOpen)) switchTab(tabToOpen);
    if (typeof handleSearchInput === 'function') handleSearchInput();
});
function openModal(modalId) { document.getElementById(modalId).style.display = 'flex'; }
function closeModal(modalId) { document.getElementById(modalId).style.display = 'none'; }
window.onclick = function(event) { if (event.target.classList.contains('modal-overlay')) event.target.style.display = 'none'; }
function toggleUserChannel(idx) {
    const hiddenInput = document.getElementById(`channel_type_${idx}`);
    const lblMail = document.getElementById(`lbl_mail_${idx}`);
    const lblPush = document.getElementById(`lbl_push_${idx}`);
    const keyBox = document.getElementById(`key_box_${idx}`);
    if (!hiddenInput || !lblMail || !lblPush || !keyBox) return;
    if (hiddenInput.value === 'email') {
        hiddenInput.value = 'push';
        lblMail.classList.remove('active-mail');
        lblPush.classList.add('active-push');
        keyBox.style.display = 'flex';
        const input = keyBox.querySelector('input');
        if (input) input.focus();
    } else {
        hiddenInput.value = 'email';
        lblPush.classList.remove('active-push');
        lblMail.classList.add('active-mail');
        keyBox.style.display = 'none';
    }
}
function toggleGlobal(serverName, category, status) {
    fetch('mailing.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=toggle_global&server=' + encodeURIComponent(serverName) + '&category=' + encodeURIComponent(category) + '&status=' + (status ? '1' : '0')
    }).then(res => res.json()).then(data => {
        if (!data.success) alert(MAILING_I18N.errorToggleGlobal);
    }).catch(err => console.error(err));
}
function handleSearchInput() {
    const input = document.getElementById("searchInput");
    const clearBtn = document.getElementById("clearSearchBtn");
    if (!input) return;
    if (clearBtn) clearBtn.classList.toggle("visible", input.value.length > 0);
    const query = input.value.toLowerCase().trim();
    const activeTab = document.querySelector('.tab-content.active');
    if (!activeTab) return;
    const rows = activeTab.querySelectorAll('tbody tr.clickable-row');
    let visibleCount = 0;
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        if (query === '' || text.includes(query)) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });
    const rowCountEl = document.getElementById('rowCount');
    if (rowCountEl) rowCountEl.textContent = `${visibleCount} ${MAILING_I18N.resultsSuffix}`;
}
function clearSearch() {
    const input = document.getElementById('searchInput');
    if (input) input.value = '';
    handleSearchInput();
}
