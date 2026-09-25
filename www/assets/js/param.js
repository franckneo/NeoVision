document.querySelectorAll(".param-nav-btn").forEach(btn => {
    btn.onclick = () => {
        const param = btn.dataset.param;
        document.querySelectorAll(".param-nav-btn").forEach(b => b.classList.remove("active"));
        document.querySelectorAll(".param-view").forEach(v => {
            v.classList.add("is-hidden");
            v.style.display = ""; // Nettoyage de style inline éventuel
        });
        btn.classList.add("active");
        const targetView = document.getElementById(`view-${param}`);
        if (targetView) targetView.classList.remove("is-hidden");
        localStorage.setItem("activeParamView", param);
        document.cookie = `activeParamView=${param};path=/;max-age=2592000;SameSite=Lax`;
    };
});

function toggleMailMethodFields(method) {
    const smtpSection = document.getElementById('smtpFields');
    const o365Section = document.getElementById('o365Fields');
    if (smtpSection) {
        smtpSection.classList.toggle('is-hidden', method !== 'smtp');
    }
    if (o365Section) {
        o365Section.classList.toggle('is-hidden', method !== 'o365');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const methodSelect = document.getElementById('mail_method');
    if (methodSelect) {
        toggleMailMethodFields(methodSelect.value);
        methodSelect.addEventListener('change', (e) => {
            toggleMailMethodFields(e.target.value);
        });
    }

    const btnSave = document.getElementById('btnSaveMailConfig');
    if (btnSave) {
        btnSave.addEventListener('click', async () => {
            const form = document.getElementById('form_mail');
            if (!form) return;
            const formData = new FormData(form);
            const originalText = btnSave.innerHTML;
            btnSave.disabled = true;
            btnSave.innerHTML = '⏳ ' + (typeof t === 'function' ? t('saving', 'Enregistrement...') : 'Enregistrement...');
            try {
                const res = await fetch(form.action, {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.success) {
                    alert(data.message || (typeof t === 'function' ? t('msg_success', 'Succès !') : 'Succès !'));
                    location.reload();
                } else {
                    alert((typeof t === 'function' ? t('msg_error', 'ERREUR : ') : 'ERREUR : ') + (data.error || (typeof t === 'function' ? t('msg_cannot_save', 'Impossible d\'enregistrer') : 'Impossible d\'enregistrer')));
                }
            } catch (err) {
                alert(typeof t === 'function' ? t('msg_network_error', 'Erreur de communication avec le serveur.') : 'Erreur de communication avec le serveur.');
            } finally {
                btnSave.disabled = false;
                btnSave.innerHTML = originalText;
            }
        });
    }

    const btnTest = document.getElementById('btnSendMailTest');
    if (btnTest) {
        btnTest.addEventListener('click', async () => {
            const recipientInput = document.getElementById('mail_test_recipient');
            const recipient = recipientInput ? recipientInput.value.trim() : '';
            if (!recipient) {
                alert(typeof t === 'function' ? t('mail_test_prompt_recipient', 'Veuillez saisir une adresse e-mail destinataire pour le test.') : 'Veuillez saisir une adresse e-mail.');
                return;
            }
            const originalText = btnTest.innerHTML;
            btnTest.disabled = true;
            btnTest.innerHTML = '⏳ ' + (typeof t === 'function' ? t('mail_test_sending', 'Envoi en cours...') : 'Envoi en cours...');
            try {
                const res = await fetch('ajax_notifications.php?action=test_mail', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ recipient })
                });
                const data = await res.json();
                if (data.success) {
                    alert(data.message || (typeof t === 'function' ? t('mail_test_sent_success', 'E-mail de test envoyé avec succès !') : 'E-mail envoyé avec succès !'));
                } else {
                    alert((typeof t === 'function' ? t('mail_test_sent_failed', 'Échec de l\'envoi : ') : 'Échec de l\'envoi : ') + (data.error || 'Erreur'));
                }
            } catch (err) {
                alert(typeof t === 'function' ? t('msg_network_error', 'Erreur de communication avec le serveur.') : 'Erreur de communication avec le serveur.');
            } finally {
                btnTest.disabled = false;
                btnTest.innerHTML = originalText;
            }
        });
    }
});

const wolModal = document.getElementById('wolModal'),
      btnOpenAddWolModal = document.getElementById('btnOpenAddWolModal'),
      btnCloseWolModal = document.getElementById('btnCloseWolModal'),
      btnCloseWolModalX = document.getElementById('btnCloseWolModalX'),
      btnDeployWol = document.getElementById('btnDeployWol'),
      wolConsole = document.getElementById('wolConsole');

if (btnOpenAddWolModal) {
    btnOpenAddWolModal.onclick = () => {
        document.getElementById('wolInputName').value = '';
        document.getElementById('wolInputIp').value = '';
        document.getElementById('wolInputSubnets').value = '';
        document.getElementById('wolInputPassword').value = '';
        wolConsole.textContent = t('wol_console_ready', "Prêt. Remplissez les informations et cliquez sur 'Déployer le relais'.");
        btnDeployWol.disabled = false;
        wolModal.style.display = 'flex';
    };
}
if (btnCloseWolModal) btnCloseWolModal.onclick = () => { wolModal.style.display = 'none'; };
if (btnCloseWolModalX) btnCloseWolModalX.onclick = () => { wolModal.style.display = 'none'; };

if (btnDeployWol) {
    btnDeployWol.onclick = async () => {
        const name = document.getElementById('wolInputName').value.trim(),
              ip = document.getElementById('wolInputIp').value.trim(),
              subnets = document.getElementById('wolInputSubnets').value.trim(),
              remote_user = document.getElementById('wolInputUser').value.trim(),
              password = document.getElementById('wolInputPassword').value,
              local_user = document.getElementById('inputLocalSupervisorUser') ? document.getElementById('inputLocalSupervisorUser').value.trim() : '';
        if (!ip || !remote_user || !password) {
            alert(t('alert_fill_wol_fields', "Veuillez remplir l'IP, le compte et le mot de passe distant."));
            return;
        }
        btnDeployWol.disabled = true;
        wolConsole.textContent = "[1/3] " + t('log_ssh_deploy_start', "Connexion SSH et déploiement de la clé...") + "\n";
        try {
            const res = await fetch('deploy_wol_relay.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name, ip, subnets, remote_user, password, local_user })
            });
            const data = await res.json();
            if (data.success) {
                wolConsole.textContent += "[✓] " + (data.message || t('msg_success', "Succès !")) + "\n";
                setTimeout(() => { location.reload(); }, 1500);
            } else {
                wolConsole.textContent += "[✗] " + t('msg_error', "ERREUR : ") + (data.error || t('msg_failed', "Échec")) + "\n";
                btnDeployWol.disabled = false;
            }
        } catch (e) {
            wolConsole.textContent += "[✗] " + t('msg_network_error', "Erreur de communication avec le serveur.") + "\n";
            btnDeployWol.disabled = false;
        }
    };
}

async function deleteWolRelay(alias, name) {
    if (!confirm(t('confirm_delete_wol_relay', `Supprimer le relais WOL "${name}" ?`).replace('${name}', name))) return;
    try {
        const res = await fetch('delete_wol_relay.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ alias })
        });
        const data = await res.json();
        if (data.success) {
            location.reload();
        } else {
            alert(t('msg_error', 'Erreur: ') + (data.error || t('msg_cannot_delete', 'Impossible de supprimer')));
        }
    } catch (e) {
        alert(t('msg_network_error', 'Erreur réseau'));
    }
}

function addPcRow() {
    const tbody = document.getElementById('pcs-tbody');
    const tr = document.createElement('tr');
    tr.className = 'pc-row';
    tr.innerHTML = `
        <td><input type="text" class="table-input pc-input-computer" placeholder="ex: BELAP275"></td>
        <td><input type="text" class="table-input pc-input-user" placeholder="ex: Reserve"></td>
        <td><input type="text" class="table-input pc-input-site" placeholder="ex: Siege"></td>
        <td><input type="text" class="table-input pc-input-details" placeholder="ex: Bureau"></td>
        <td><input type="text" class="table-input pc-input-mac" placeholder="ex: a8:2b:dd:61:67:d1"></td>
        <td style="text-align: center;">
            <button type="button" class="btn-trash" title="${t('action_delete', 'Supprimer')}" onclick="removeAccountRow(this)">🗑️</button>
        </td>
    `;
    tbody.appendChild(tr);
    tr.querySelector('.pc-input-computer').focus();
}

function savePcs() {
    const rows = document.querySelectorAll('#pcs-tbody .pc-row');
    const pcs = [];

    rows.forEach(row => {
        const computer = row.querySelector('.pc-input-computer').value.trim();
        const user = row.querySelector('.pc-input-user').value.trim();
        const site = row.querySelector('.pc-input-site').value.trim();
        const detail = row.querySelector('.pc-input-details').value.trim();
        const mac = row.querySelector('.pc-input-mac').value.trim();
        if (computer !== '') {
            pcs.push({ computer, user, site, detail, mac });
        }
    });

    const formData = new FormData();
    formData.append('action', 'save_pcs');
    formData.append('pcs_data', JSON.stringify(pcs));
    const btn = document.getElementById('btnSavePcs');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '⏳ ' + t('saving', 'Enregistrement...');
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.error || 'Erreur lors de la sauvegarde.');
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    })
    .catch(err => {
        alert('Erreur réseau : ' + err.message);
        btn.disabled = false;
        btn.innerHTML = originalText;
    });
}

function removeAccountRow(btn) {
    const row = btn.closest('tr');
    if (row) row.remove();
}

function addSshRow() {
    const tbody = document.querySelector('#tableSshAccounts tbody'), tr = document.createElement('tr');
    tr.dataset.sshId = 'ssh_' + Date.now();
    tr.innerHTML = `<td><input type="text" class="table-input ssh-name" placeholder="ex: Compte User"></td><td><input type="text" class="table-input ssh-user" placeholder="ex: root"></td><td style="text-align:center;"><button type="button" class="btn-trash" title="${t('action_delete', 'Supprimer')}" onclick="removeAccountRow(this)">🗑️</button></td>`;
    tbody.appendChild(tr);
}

function addWinrmRow() {
    const tbody = document.querySelector('#tableWinrmAccounts tbody'), tr = document.createElement('tr');
    tr.dataset.winId = 'win_' + Date.now();
    tr.dataset.encPassword = '';
    tr.innerHTML = `<td><input type="text" class="table-input win-name" placeholder="ex: Admin Local"></td><td><input type="text" class="table-input win-domain" placeholder="ex: user ou laisser vide"></td><td><input type="text" class="table-input win-user" placeholder="ex: admin"></td><td><input type="password" class="table-input win-pwd" placeholder="${t('placeholder_type_password', 'Saisir mot de passe')}"></td><td style="text-align:center;"><button type="button" class="btn-trash" title="${t('action_delete', 'Supprimer')}" onclick="removeAccountRow(this)">🗑️</button></td>`;
    tbody.appendChild(tr);
}

document.getElementById('btnSaveAccounts')?.addEventListener('click', function() {
    const localUser = document.getElementById('inputLocalSupervisorUser').value.trim(),
          sshAccounts = [],
          winrmAccounts = [];
    document.querySelectorAll('#tableSshAccounts tbody tr').forEach(row => {
        const id = row.dataset.sshId || '',
              name = row.querySelector('.ssh-name').value.trim(),
              user = row.querySelector('.ssh-user').value.trim();
        if (user) sshAccounts.push({ id, name, user });
    });
    document.querySelectorAll('#tableWinrmAccounts tbody tr').forEach(row => {
        const id = row.dataset.winId || '',
              name = row.querySelector('.win-name').value.trim(),
              domain = row.querySelector('.win-domain').value.trim(),
              user = row.querySelector('.win-user').value.trim(),
              newPwd = row.querySelector('.win-pwd').value,
              encPwd = row.dataset.encPassword || '';
        if (user) winrmAccounts.push({ id, name, domain, user, new_password: newPwd, enc_password: encPwd });
    });
    fetch('ajax_accounts.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'save_all', local_user: localUser, ssh_accounts: sshAccounts, winrm_accounts: winrmAccounts })
    }).then(r => r.json()).then(data => {
        if (data.success) {
            alert(t('alert_accounts_saved', 'Comptes enregistrés avec succès !'));
            location.reload();
        } else {
            alert(t('msg_error', 'Erreur : ') + (data.error || t('msg_cannot_save', "Impossible d'enregistrer")));
        }
    }).catch(err => alert(t('msg_network_error', 'Erreur réseau.')));
});
const btnAddServer = document.getElementById('btnAddServer');
if (btnAddServer) {
    btnAddServer.onclick = () => {
        const tbody = document.querySelector('#tableServers tbody'), tr = document.createElement('tr');
        tr.className = 'server-row';
        tr.dataset.originalName = '';
        tr.dataset.encPassword = '';
        tr.innerHTML = `<td><input type="text" class="input-srv-name" placeholder="ex: BSSRV03"></td><td><input type="text" class="input-srv-ip" placeholder="ex: 10.101.0.33"></td><td><select class="server-type-select input-srv-type"><option value="windows" selected>Windows</option><option value="linux">Linux</option></select></td><td><input type="text" class="input-srv-user" placeholder="ex: root\\\\admin"></td><td class="col-action"><button type="button" class="btn btn-sm btn-blue btn-win-pwd">🔑 ${t('btn_pwd', 'MDP')}</button></td><td style="text-align:center;"><button type="button" class="btn btn-sm btn-blue btn-test-conn">${t('btn_test', 'Tester')}</button></td><td style="text-align:center; white-space:nowrap;"><button type="button" class="btn-order btn-move-up" title="${t('action_move_up', 'Monter')}">▲</button><button type="button" class="btn-order btn-move-down" title="${t('action_move_down', 'Descendre')}">▼</button></td><td style="text-align:center;"><button type="button" class="btn-trash btn-delete-srv" title="${t('action_delete', 'Supprimer')}">🗑️</button></td>`;
        tbody.appendChild(tr);
    };
}

document.addEventListener('change', e => {
    if (e.target.classList.contains('server-type-select')) {
        const row = e.target.closest('tr'), colAction = row.querySelector('.col-action');
        if (e.target.value === 'linux') {
            colAction.innerHTML = `<button type="button" class="btn btn-sm btn-blue btn-ssh-key">${t('btn_send_key', 'Envoyer la clé')}</button>`;
        } else {
            colAction.innerHTML = `<button type="button" class="btn btn-sm btn-blue btn-win-pwd">🔑 ${t('btn_pwd', 'MDP')}</button>`;
        }
    }
});

document.addEventListener('click', e => {
    const upBtn = e.target.closest('.btn-move-up'), downBtn = e.target.closest('.btn-move-down');
    if (upBtn) {
        const row = upBtn.closest('tr'), prev = row.previousElementSibling;
        if (prev) row.parentNode.insertBefore(row, prev);
    } else if (downBtn) {
        const row = downBtn.closest('tr'), next = row.nextElementSibling;
        if (next) row.parentNode.insertBefore(next, row);
    }
});

const btnSaveServers = document.getElementById('btnSaveServers');
if (btnSaveServers) {
    btnSaveServers.onclick = async () => {
        const rows = document.querySelectorAll('#tableServers tbody tr.server-row'), servers = [];
        rows.forEach(r => {
            const name = r.querySelector('.input-srv-name').value.trim(),
                  ip = r.querySelector('.input-srv-ip').value.trim(),
                  type = r.querySelector('.input-srv-type').value,
                  user = r.querySelector('.input-srv-user').value.trim(),
                  encPassword = r.dataset.encPassword || null;
            if (name) {
                const srv = { name, ip, type, user };
                if (type === 'windows' && encPassword) { srv.enc_password = encPassword; }
                servers.push(srv);
            }
        });
        try {
            const res = await fetch('save_servers.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ servers })
            });
            const data = await res.json();
            if (data.success) {
                alert(t('alert_servers_saved', 'Configuration enregistrée avec succès !'));
                window.location.reload();
            } else {
                alert(t('msg_error', 'Erreur : ') + (data.error || t('msg_cannot_save', "Impossible d'enregistrer")));
            }
        } catch (err) {
            alert(t('msg_network_error_save', "Erreur réseau lors de l'enregistrement"));
        }
    };
}

let targetServerToDelete = null, targetRowToDelete = null;
const modal = document.getElementById("deleteModal"), modalText = document.getElementById("deleteModalText");

document.addEventListener('click', e => {
    const btn = e.target.closest('.btn-delete-srv');
    if (btn) {
        targetRowToDelete = btn.closest('tr');
        targetServerToDelete = targetRowToDelete.dataset.originalName || targetRowToDelete.querySelector('.input-srv-name').value.trim();
        if (!targetServerToDelete) { targetRowToDelete.remove(); return; }
        modalText.innerHTML = t('modal_delete_srv_confirm', "Êtes-vous sûr de vouloir supprimer le serveur <strong>{srv}</strong> ?<br><br>Cette action supprimera définitivement le serveur et l'historique associé (RAM, disques, statuts, services).").replace('{srv}', targetServerToDelete);
        modal.style.display = 'flex';
    }
});

const btnCancelDelete = document.getElementById("btnCancelDelete");
if (btnCancelDelete) {
    btnCancelDelete.onclick = () => { modal.style.display = 'none'; targetServerToDelete = null; targetRowToDelete = null; };
}

const btnConfirmDelete = document.getElementById("btnConfirmDelete");
if (btnConfirmDelete) {
    btnConfirmDelete.onclick = async () => {
        if (!targetServerToDelete) return;
        try {
            const res = await fetch('delete_server.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: targetServerToDelete })
            });
            const data = await res.json();
            if (data.success) {
                if (targetRowToDelete) targetRowToDelete.remove();
                modal.style.display = 'none';
            } else {
                alert(t('msg_error', 'Erreur: ') + (data.error || t('msg_cannot_delete', 'Impossible de supprimer')));
            }
        } catch (err) {
            alert(t('msg_network_error_delete', 'Erreur réseau lors de la suppression'));
        }
        modal.style.display = 'none';
    };
}

const winModal = document.getElementById("winModal"),
      winAccountSelect = document.getElementById("winAccountSelect"),
      winUserInput = document.getElementById("winInputUser"),
      winPassInput = document.getElementById("winInputPassword"),
      winModalTarget = document.getElementById("winModalTarget");
let currentWinRow = null;

document.addEventListener('click', e => {
    const btn = e.target.closest('.btn-win-pwd');
    if (btn) {
        currentWinRow = btn.closest('tr');
        const name = currentWinRow.querySelector('.input-srv-name').value.trim(),
              user = currentWinRow.querySelector('.input-srv-user').value.trim();
        winModalTarget.textContent = name || t('label_win_server_default', 'Serveur Windows');
        winUserInput.value = user;
        winPassInput.value = '';
        winAccountSelect.value = '';
        Array.from(winAccountSelect.options).forEach(opt => {
            if (opt.dataset.user === user) winAccountSelect.value = opt.value;
        });
        winModal.style.display = 'flex';
    }
});

if (winAccountSelect) {
    winAccountSelect.onchange = () => {
        const opt = winAccountSelect.selectedOptions[0];
        if (opt && opt.value) {
            winUserInput.value = opt.dataset.user;
            winPassInput.value = '';
        }
    };
}

const closeWinModal = () => { if (winModal) winModal.style.display = 'none'; currentWinRow = null; };
const btnCloseWin = document.getElementById('btnCloseWin');
const btnHeaderCloseWin = document.getElementById('btnHeaderCloseWin');
if (btnCloseWin) btnCloseWin.onclick = closeWinModal;
if (btnHeaderCloseWin) btnHeaderCloseWin.onclick = closeWinModal;

const btnApplyWin = document.getElementById('btnApplyWin');
if (btnApplyWin) {
    btnApplyWin.onclick = async () => {
        if (!currentWinRow) return;
        const selectedOpt = winAccountSelect.selectedOptions[0];
        if (selectedOpt && selectedOpt.value) {
            currentWinRow.querySelector('.input-srv-user').value = selectedOpt.dataset.user;
            currentWinRow.dataset.encPassword = selectedOpt.dataset.enc;
            closeWinModal();
            return;
        }
        const user = winUserInput.value.trim(), pwd = winPassInput.value;
        if (user) currentWinRow.querySelector('.input-srv-user').value = user;
        if (pwd) {
            try {
                const res = await fetch('ajax_accounts.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'encrypt_pwd', password: pwd })
                });
                const data = await res.json();
                if (data.success) {
                    currentWinRow.dataset.encPassword = data.enc_password;
                    alert(t('alert_pwd_encrypted', 'Mot de passe chiffré et mis à jour pour ce serveur.'));
                } else {
                    alert(t('msg_error', 'Erreur : ') + data.error);
                }
            } catch (err) {
                alert(t('msg_network_error', 'Erreur réseau'));
            }
        }
        closeWinModal();
    };
}

const sshModal = document.getElementById("sshModal"),
      sshConsole = document.getElementById("sshConsole"),
      sshTargetSpan = document.getElementById("sshModalTarget"),
      sshIpInput = document.getElementById("sshInputIp"),
      sshUserInput = document.getElementById("sshInputUser"),
      sshPassInput = document.getElementById("sshInputPassword"),
      sshLocalUserInput = document.getElementById("sshInputLocalUser"),
      sshProfileSelect = document.getElementById("sshProfileSelect"),
      btnRunSsh = document.getElementById("btnRunSsh");
let currentSshRow = null;

document.addEventListener('click', e => {
    const btn = e.target.closest('.btn-ssh-key');
    if (btn) {
        currentSshRow = btn.closest('tr');
        const ip = currentSshRow.querySelector('.input-srv-ip').value.trim(),
              user = currentSshRow.querySelector('.input-srv-user').value.trim(),
              srvName = currentSshRow.querySelector('.input-srv-name').value.trim();
        sshTargetSpan.textContent = srvName || ip;
        sshIpInput.value = ip;
        sshUserInput.value = user;
        sshProfileSelect.value = user || '';
        sshPassInput.value = '';
        sshConsole.textContent = t('ssh_console_ready', "Prêt. Entrez le mot de passe distant et cliquez sur 'Déployer la clé'.");
        sshModal.style.display = 'flex';
    }
});

if (sshProfileSelect) {
    sshProfileSelect.onchange = () => {
        if (sshProfileSelect.value) { sshUserInput.value = sshProfileSelect.value; }
    };
}

const closeSshModal = () => { if (sshModal) sshModal.style.display = 'none'; currentSshRow = null; };
const btnCloseSsh = document.getElementById('btnCloseSsh');
const btnHeaderCloseSsh = document.getElementById('btnHeaderCloseSsh');
if (btnCloseSsh) btnCloseSsh.onclick = closeSshModal;
if (btnHeaderCloseSsh) btnHeaderCloseSsh.onclick = closeSshModal;

if (btnRunSsh) {
    btnRunSsh.onclick = async () => {
        const ip = sshIpInput.value.trim(),
              local_user = sshLocalUserInput.value.trim(),
              user = sshUserInput.value.trim(),
              password = sshPassInput.value;
        if (!password) {
            alert(t('alert_fill_ssh_password', 'Veuillez saisir le mot de passe distant.'));
            return;
        }
        btnRunSsh.disabled = true;
        sshConsole.textContent = "[1/3] " + t('log_ssh_step1', "Vérification/génération de la clé locale ({user})...").replace('{user}', local_user) + "\n";
        try {
            const res = await fetch('deploy_ssh_key.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ip, local_user, user, password })
            });
            const data = await res.json();
            if (data.success) {
                sshConsole.textContent += "[2/3] " + t('log_ssh_step2', "Connexion à {ip} via {user}...").replace('{ip}', ip).replace('{user}', user) + "\n";
                sshConsole.textContent += "[3/3] " + t('log_ssh_step3', "Clé publique injectée avec succès !") + "\n\n";
                sshConsole.textContent += "[✓] " + data.message;
                if (currentSshRow) currentSshRow.querySelector('.input-srv-user').value = user;
            } else {
                sshConsole.textContent += (data.output ? "\n" + data.output : "") + "\n[✗] " + t('msg_error', "ERREUR : ") + (data.error || t('log_ssh_failed', "Échec du déploiement"));
            }
        } catch (err) {
            sshConsole.textContent += "[✗] " + t('msg_network_error', "Erreur de communication avec le serveur.");
        }
        btnRunSsh.disabled = false;
    };
}

document.addEventListener('click', async e => {
    const btn = e.target.closest('.btn-test-conn');
    if (btn) {
        const row = btn.closest('tr'),
              name = row.querySelector('.input-srv-name').value.trim(),
              ip = row.querySelector('.input-srv-ip').value.trim(),
              type = row.querySelector('.input-srv-type').value,
              user = row.querySelector('.input-srv-user').value.trim();
        if (!ip) {
            alert(t('alert_fill_ip', "Veuillez saisir une adresse IP."));
            return;
        }
        const origText = btn.textContent;
        btn.disabled = true;
        btn.classList.add('testing');
        btn.textContent = "⏳ " + t('btn_testing', "Test...");
        try {
            const res = await fetch('test_connection.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name, ip, type, user })
            });
            const data = await res.json();
            if (data.success) {
                alert("✅ " + data.message);
            } else {
                alert("❌ " + data.error);
            }
        } catch (err) {
            alert("❌ " + t('msg_test_network_error', "Erreur réseau lors du test"));
        }
        btn.disabled = false;
        btn.classList.remove('testing');
        btn.textContent = origText;
    }
});
const backToTopBtn = document.getElementById("backToTop");
const backToTopSpan = backToTopBtn ? backToTopBtn.querySelector("span") : null;
function updateScrollButton() {
    if (!backToTopBtn) return;
    const mainEl = document.getElementById("main");
    const scrollY = window.scrollY || (mainEl ? mainEl.scrollTop : 0);
    const scrollHeight = Math.max(
        document.documentElement.scrollHeight,
        document.body.scrollHeight,
        mainEl ? mainEl.scrollHeight : 0
    );
    const clientHeight = window.innerHeight;
    const canScroll = (scrollHeight - clientHeight) > 80;
    if (!canScroll) {
        backToTopBtn.style.display = "none";
        return;
    }
    backToTopBtn.style.display = "flex";
    if (scrollY > 150) {
        if (backToTopSpan) backToTopSpan.textContent = "↑";
        backToTopBtn.title = typeof t === 'function' ? t('btn_back_to_top', 'Retour en haut') : 'Retour en haut';
    } else {
        if (backToTopSpan) backToTopSpan.textContent = "↓";
        backToTopBtn.title = typeof t === 'function' ? t('btn_scroll_bottom', 'Descendre en bas') : 'Descendre en bas';
    }
}
if (backToTopBtn) {
    window.addEventListener("scroll", updateScrollButton, { passive: true });
    const mainEl = document.getElementById("main");
    if (mainEl) {
        mainEl.addEventListener("scroll", updateScrollButton, { passive: true });
    }
    window.addEventListener("resize", updateScrollButton);
    backToTopBtn.addEventListener("click", () => {
        const scrollY = window.scrollY || (mainEl ? mainEl.scrollTop : 0);
        if (scrollY > 150) {
            window.scrollTo({ top: 0, behavior: "smooth" });
            if (mainEl) mainEl.scrollTo({ top: 0, behavior: "smooth" });
        } else {
            const targetHeight = Math.max(document.documentElement.scrollHeight, mainEl ? mainEl.scrollHeight : 0);
            window.scrollTo({ top: targetHeight, behavior: "smooth" });
            if (mainEl) mainEl.scrollTo({ top: targetHeight, behavior: "smooth" });
        }
    });
    if (window.ResizeObserver) {
        const ro = new ResizeObserver(() => updateScrollButton());
        if (mainEl) ro.observe(mainEl);
        ro.observe(document.body);
    }
    updateScrollButton();
    window.addEventListener("load", updateScrollButton);
    setTimeout(updateScrollButton, 100);
}
