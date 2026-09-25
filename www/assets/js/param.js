function toggleMailMethodFields() {
    const method = document.getElementById('mail_method')?.value;
    const smtpSec = document.getElementById('section_smtp');
    const o365Sec = document.getElementById('section_office365');

    if (smtpSec) {
        if (method === 'smtp') {
            smtpSec.classList.remove('is-hidden');
        } else {
            smtpSec.classList.add('is-hidden');
        }
    }
    if (o365Sec) {
        if (method === 'office365') {
            o365Sec.classList.remove('is-hidden');
        } else {
            o365Sec.classList.add('is-hidden');
        }
    }
}

document.getElementById('mail_method')?.addEventListener('change', toggleMailMethodFields);

document.getElementById('btnSaveMailConfig')?.addEventListener('click', function() {
    const payload = new URLSearchParams({
        action: 'save_mail_config',
        from_name: document.getElementById('mail_from_name')?.value.trim() || '',
        from_email: document.getElementById('mail_from_email')?.value.trim() || '',
        method: document.getElementById('mail_method')?.value || 'mail',
        smtp_host: document.getElementById('mail_smtp_host')?.value.trim() || '',
        smtp_port: document.getElementById('mail_smtp_port')?.value.trim() || '25',
        smtp_secure: document.getElementById('mail_smtp_secure')?.value || 'tls',
        smtp_user: document.getElementById('mail_smtp_user')?.value.trim() || '',
        smtp_pass: document.getElementById('mail_smtp_pass')?.value || ''
    });

    fetch('param.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: payload.toString()
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(typeof t === 'function' ? t('mail_saved_success', 'Configuration e-mail enregistrée avec succès.') : 'Configuration e-mail enregistrée avec succès.');
            location.reload();
        } else {
            const errPrefix = typeof t === 'function' ? t('msg_error', 'Erreur : ') : 'Erreur : ';
            const errUnknown = typeof t === 'function' ? t('msg_unknown_error', 'Erreur inconnue') : 'Erreur inconnue';
            alert(errPrefix + (data.error || errUnknown));
        }
    })
    .catch(err => {
        const netErr = typeof t === 'function' ? t('msg_network_error', 'Erreur réseau') : 'Erreur réseau';
        alert(netErr + ' : ' + err.message);
    });
});

function testMailConfig() {
    const recipient = document.getElementById('mail_test_recipient')?.value.trim();
    if (!recipient) {
        alert(typeof t === 'function' ? t('mail_test_recipient_required', 'Veuillez saisir une adresse e-mail destinataire.') : 'Veuillez saisir une adresse e-mail destinataire.');
        return;
    }

    const btn = document.getElementById('btnTestMail');
    const origHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> ' + (typeof t === 'function' ? t('mail_testing', 'Envoi en cours...') : 'Envoi en cours...');
    }

    const payload = new URLSearchParams({
        action: 'test_mail',
        to: recipient
    });

    fetch('param.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: payload.toString()
    })
    .then(r => r.json())
    .then(data => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
        if (data.success) {
            alert(typeof t === 'function' ? t('mail_test_success', 'E-mail de test envoyé avec succès !') : 'E-mail de test envoyé avec succès !');
        } else {
            const errPrefix = typeof t === 'function' ? t('msg_error', 'Erreur : ') : 'Erreur : ';
            const errUnknown = typeof t === 'function' ? t('msg_unknown_error', 'Erreur inconnue') : 'Erreur inconnue';
            alert(errPrefix + (data.error || errUnknown));
        }
    })
    .catch(err => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
        const netErr = typeof t === 'function' ? t('msg_network_error', 'Erreur réseau') : 'Erreur réseau';
        alert(netErr + ' : ' + err.message);
    });
}

document.getElementById('btnTestMail')?.addEventListener('click', testMailConfig);

function switchTab(tabId) {
    document.querySelectorAll('.param-view').forEach(v => v.classList.add('is-hidden'));
    document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('active'));

    const targetView = document.getElementById('view-' + tabId);
    if (targetView) targetView.classList.remove('is-hidden');

    const targetTab = document.querySelector(`.nav-tab[data-tab="${tabId}"]`);
    if (targetTab) targetTab.classList.add('active');

    const url = new URL(window.location);
    url.searchParams.set('tab', tabId);
    window.history.replaceState({}, '', url);
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.nav-tab').forEach(tab => {
        tab.addEventListener('click', () => switchTab(tab.dataset.tab));
    });
});
