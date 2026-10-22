const t=(k,def)=>window.I18N&&window.I18N[k]?window.I18N[k]:def;
const sshKeyStatus=document.getElementById('sshKeyStatus'),btnCheckSshKey=document.getElementById('btnCheckSshKey'),btnGenSshKey=document.getElementById('btnGenSshKey');
async function sshKeyAction(action){
if(sshKeyStatus)sshKeyStatus.textContent='Vérification...';
try{
const res=await fetch('ajax_accounts.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action})}),data=await res.json();
if(!data.success)throw new Error(data.error||'Erreur');
if(sshKeyStatus)sshKeyStatus.textContent=data.exists?'Clé présente : '+data.key:data.key?'Clé générée : '+data.key:'Aucune clé trouvée.';
}catch(e){if(sshKeyStatus)sshKeyStatus.textContent='Erreur : '+e.message;}
}
if(btnCheckSshKey)btnCheckSshKey.onclick=()=>sshKeyAction('check_ssh_key');
if(btnGenSshKey)btnGenSshKey.onclick=async()=>{
if(!confirm('Générer une nouvelle clé SSH si aucune clé n’existe ?'))return;
if(sshKeyStatus)sshKeyStatus.textContent='Génération...';
try{
const res=await fetch('ajax_accounts.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'generate_ssh_key'})}),data=await res.json();
if(!data.success)throw new Error(data.error||'Erreur');
if(sshKeyStatus)sshKeyStatus.textContent='Clé générée : '+data.key;
}catch(e){if(sshKeyStatus)sshKeyStatus.textContent='Erreur : '+e.message;}
};
const wolModal=document.getElementById('wolModal'),btnOpenAddWolModal=document.getElementById('btnOpenAddWolModal'),btnCloseWolModal=document.getElementById('btnCloseWolModal'),btnCloseWolModalX=document.getElementById('btnCloseWolModalX'),btnDeployWol=document.getElementById('btnDeployWol'),wolConsole=document.getElementById('wolConsole');
if(btnOpenAddWolModal){btnOpenAddWolModal.onclick=()=>{document.getElementById('wolInputName').value='';document.getElementById('wolInputIp').value='';document.getElementById('wolInputSubnets').value='';document.getElementById('wolInputPassword').value='';const u=document.getElementById('wolInputUser');if(u)u.value='';wolConsole.textContent=t('wol_console_ready',"Prêt. Remplissez les informations et cliquez sur 'Déployer le relais'.");btnDeployWol.disabled=false;wolModal.classList.remove('view-hidden');wolModal.style.display='flex';};}
const hideWol=()=>{if(wolModal){wolModal.classList.add('view-hidden');wolModal.style.display='none';}};
if(btnCloseWolModal)btnCloseWolModal.onclick=hideWol;
if(btnCloseWolModalX)btnCloseWolModalX.onclick=hideWol;
if(btnDeployWol){
btnDeployWol.onclick=async()=>{
const name=document.getElementById('wolInputName').value.trim(),ip=document.getElementById('wolInputIp').value.trim(),subnets=document.getElementById('wolInputSubnets').value.trim(),remote_user=(document.getElementById('wolInputUser')?.value||'').trim(),password=document.getElementById('wolInputPassword').value;
if(!ip||!remote_user||!password){alert(t('alert_fill_wol_fields',"Veuillez remplir l'IP, le compte et le mot de passe distant."));return;}
btnDeployWol.disabled=true;
wolConsole.textContent="[1/3] "+t('log_ssh_deploy_start',"Connexion SSH et déploiement de la clé...")+"\n";
try{
const res=await fetch('deploy_wol_relay.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name,ip,subnets,remote_user,password})}),data=await res.json();
if(data.success){wolConsole.textContent+="[✓] "+(data.message||t('msg_success',"Succès !"))+"\n";setTimeout(()=>{location.reload();},1500);}else{wolConsole.textContent+="[✗] "+t('msg_error',"ERREUR : ")+(data.error||t('msg_failed',"Échec"))+"\n";btnDeployWol.disabled=false;}
}catch(e){wolConsole.textContent+="[✗] "+t('msg_network_error',"Erreur de communication avec le serveur.")+"\n";btnDeployWol.disabled=false;}
};
}
async function deleteWolRelay(alias,name){
if(!confirm(t('confirm_delete_wol_relay',`Supprimer le relais WOL "${name}" ?`).replace('${name}',name)))return;
try{
const res=await fetch('delete_wol_relay.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({alias})}),data=await res.json();
if(data.success){location.reload();}else{alert(t('msg_error','Erreur: ')+(data.error||t('msg_cannot_delete','Impossible de supprimer')));}
}catch(e){alert(t('msg_network_error','Erreur réseau'));}
}
function addSshRow(){
const tbody=document.querySelector('#tableSshAccounts tbody'),tr=document.createElement('tr');
tr.dataset.sshId='ssh_'+Date.now();
tr.innerHTML=`<td><input type="text" class="table-input ssh-name" placeholder="ex: Compte User"></td><td><input type="text" class="table-input ssh-user" placeholder="ex: user"></td><td style="text-align:center;"><button type="button" class="btn-trash" title="${t('action_delete','Supprimer')}" onclick="removeAccountRow(this)">🗑️</button></td>`;
tbody.appendChild(tr);
}
function addWinrmRow(){
const tbody=document.querySelector('#tableWinrmAccounts tbody'),tr=document.createElement('tr');
tr.dataset.winId='win_'+Date.now();
tr.dataset.encPassword='';
tr.innerHTML=`<td><input type="text" class="table-input win-name" placeholder="ex: Admin Local"></td><td><input type="text" class="table-input win-domain" placeholder="ex: user ou laisser vide"></td><td><input type="text" class="table-input win-user" placeholder="ex: admin"></td><td><input type="password" class="table-input win-pwd" placeholder="${t('placeholder_type_password','Saisir mot de passe')}"></td><td style="text-align:center;"><button type="button" class="btn-trash" title="${t('action_delete','Supprimer')}" onclick="removeAccountRow(this)">🗑️</button></td>`;
tbody.appendChild(tr);
}
document.getElementById('btnSaveAccounts')?.addEventListener('click',function(){
const sshAccounts=[],winrmAccounts=[];
document.querySelectorAll('#tableSshAccounts tbody tr').forEach(row=>{const id=row.dataset.sshId||'',name=row.querySelector('.ssh-name').value.trim(),user=row.querySelector('.ssh-user').value.trim();if(user)sshAccounts.push({id,name,user});});
document.querySelectorAll('#tableWinrmAccounts tbody tr').forEach(row=>{const id=row.dataset.winId||'',name=row.querySelector('.win-name').value.trim(),domain=row.querySelector('.win-domain').value.trim(),user=row.querySelector('.win-user').value.trim(),newPwd=row.querySelector('.win-pwd').value,encPwd=row.dataset.encPassword||'';if(user)winrmAccounts.push({id,name,domain,user,new_password:newPwd,enc_password:encPwd});});
fetch('ajax_accounts.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'save_all',ssh_accounts:sshAccounts,winrm_accounts:winrmAccounts})}).then(r=>r.json()).then(data=>{
if(data.success){alert(t('alert_accounts_saved','Comptes enregistrés avec succès !'));location.reload();}else{alert(t('msg_error','Erreur : ')+(data.error||t('msg_cannot_save',"Impossible d'enregistrer")));}
}).catch(err=>alert(t('msg_network_error','Erreur réseau.')));
});
document.getElementById('btnAddServer')?.addEventListener('click',()=>{
const tbody=document.querySelector('#tableServers tbody'),tr=document.createElement('tr');
tr.className='server-row';
tr.dataset.originalName='';
tr.dataset.encPassword='';
tr.innerHTML=`<td><input type="text" class="input-srv-name" placeholder="ex: SRV-AD-1"></td><td><input type="text" class="input-srv-ip" placeholder="ex: 192.168.0.11"></td><td><select class="server-type-select input-srv-type"><option value="windows" selected>Windows</option><option value="linux">Linux</option></select></td><td><input type="text" class="input-srv-user" placeholder="ex: DOMAINE\\admin"></td><td class="col-action"><button type="button" class="btn btn-sm btn-blue btn-win-pwd">🔑 ${t('btn_pwd','MDP')}</button></td><td style="text-align:center;"><button type="button" class="btn btn-sm btn-blue btn-test-conn">${t('btn_test','Tester')}</button></td><td style="text-align:center; white-space:nowrap;"><button type="button" class="btn-order btn-move-up" title="${t('action_move_up','Monter')}">▲</button><button type="button" class="btn-order btn-move-down" title="${t('action_move_down','Descendre')}">▼</button></td><td style="text-align:center;"><button type="button" class="btn-trash btn-delete-srv" title="${t('action_delete','Supprimer')}">🗑️</button></td>`;
tbody.appendChild(tr);
});
document.addEventListener('change',e=>{
if(e.target.classList.contains('server-type-select')){
const row=e.target.closest('tr'),colAction=row.querySelector('.col-action')||row.cells[4];
if(colAction)colAction.innerHTML=e.target.value==='linux'?`<button type="button" class="btn btn-sm btn-blue btn-ssh-key">${t('btn_send_key','Envoyer la clé')}</button>`:`<button type="button" class="btn btn-sm btn-blue btn-win-pwd">🔑 ${t('btn_pwd','MDP')}</button>`;
}
});
document.addEventListener('click',e=>{
const upBtn=e.target.closest('.btn-move-up'),downBtn=e.target.closest('.btn-move-down');
if(upBtn){const row=upBtn.closest('tr'),prev=row.previousElementSibling;if(prev)row.parentNode.insertBefore(row,prev);}
else if(downBtn){const row=downBtn.closest('tr'),next=row.nextElementSibling;if(next)row.parentNode.insertBefore(next,row);}
});
document.querySelectorAll('#btnSaveServers,.btnSaveServers').forEach(btn=>btn.addEventListener('click',async()=>{
const rows=document.querySelectorAll('#tableServers tbody tr'),servers=[];
rows.forEach(r=>{
const nameInput=r.querySelector('.input-srv-name'),ipInput=r.querySelector('.input-srv-ip'),typeSelect=r.querySelector('.input-srv-type'),userInput=r.querySelector('.input-srv-user');
if(nameInput&&ipInput){const name=nameInput.value.trim(),ip=ipInput.value.trim(),type=typeSelect?typeSelect.value:'windows',user=userInput?userInput.value.trim():'',encPassword=r.dataset.encPassword||null;if(name){const srv={name,ip,type,user};if(type==='windows'&&encPassword){srv.enc_password=encPassword;}servers.push(srv);}}
});
try{
const res=await fetch('save_servers.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({servers})}),data=await res.json();
if(data.success){alert(t('alert_servers_saved','Configuration enregistrée avec succès !'));window.location.reload();}else{alert(t('msg_error','Erreur : ')+(data.error||t('msg_cannot_save',"Impossible d'enregistrer")));}
}catch(err){alert(t('msg_network_error_save',"Erreur réseau lors de l'enregistrement"));}
}));
let targetServerToDelete=null,targetRowToDelete=null;
const modal=document.getElementById("deleteModal"),modalText=document.getElementById("deleteModalText");
document.addEventListener('click',e=>{
const btn=e.target.closest('.btn-delete-srv');
if(btn){
targetRowToDelete=btn.closest('tr');
const nameInput=targetRowToDelete.querySelector('.input-srv-name');
targetServerToDelete=targetRowToDelete.dataset.originalName||(nameInput?nameInput.value.trim():'');
if(!targetServerToDelete){targetRowToDelete.remove();return;}
if(modal&&modalText){modalText.innerHTML=t('modal_delete_srv_confirm',"Êtes-vous sûr de vouloir supprimer le serveur <strong>{srv}</strong> ?<br><br>Cette action supprimera définitivement le serveur et l'historique associé (RAM, disques, statuts, services).").replace('{srv}',targetServerToDelete);modal.style.display='flex';}
else if(confirm(t('confirm_delete_srv',`Supprimer le serveur ${targetServerToDelete} ?`))){deleteServerDirect(targetServerToDelete,targetRowToDelete);}
}
});
async function deleteServerDirect(name,row){
try{
const res=await fetch('delete_server.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name})}),data=await res.json();
if(data.success){if(row)row.remove();}else{alert(t('msg_error','Erreur: ')+(data.error||t('msg_cannot_delete','Impossible de supprimer')));}
}catch(err){alert(t('msg_network_error_delete','Erreur réseau lors de la suppression'));}
}
const btnCancelDel=document.getElementById("btnCancelDelete"),btnConfDel=document.getElementById("btnConfirmDelete");
if(btnCancelDel)btnCancelDel.onclick=()=>{if(modal)modal.style.display='none';targetServerToDelete=null;targetRowToDelete=null;};
if(btnConfDel)btnConfDel.onclick=async()=>{
if(!targetServerToDelete)return;
await deleteServerDirect(targetServerToDelete,targetRowToDelete);
if(modal)modal.style.display='none';
};
const winModal=document.getElementById("winModal"),winAccountSelect=document.getElementById("winAccountSelect"),winUserInput=document.getElementById("winInputUser"),winPassInput=document.getElementById("winInputPassword"),winModalTarget=document.getElementById("winModalTarget");
let currentWinRow=null;
document.addEventListener('click',e=>{
const btn=e.target.closest('.btn-win-pwd');
if(btn&&winModal){
currentWinRow=btn.closest('tr');
const name=currentWinRow.querySelector('.input-srv-name').value.trim(),user=currentWinRow.querySelector('.input-srv-user').value.trim();
winModalTarget.textContent=name||t('label_win_server_default','Serveur Windows');
winUserInput.value=user;winPassInput.value='';winAccountSelect.value='';
Array.from(winAccountSelect.options).forEach(opt=>{if(opt.dataset.user===user)winAccountSelect.value=opt.value;});
winModal.classList.remove('view-hidden');winModal.classList.add('active');winModal.style.display='flex';
}
});
if(winAccountSelect)winAccountSelect.onchange=()=>{const opt=winAccountSelect.selectedOptions[0];if(opt&&opt.value){winUserInput.value=opt.dataset.user;winPassInput.value='';}};
const closeWinModal=()=>{if(winModal){winModal.classList.add('view-hidden');winModal.classList.remove('active');winModal.style.display='none';}currentWinRow=null;};
const bCloseWin=document.getElementById('btnCloseWinModal'),bHeaderCloseWin=document.getElementById('btnHeaderCloseWin'),bApplyWin=document.getElementById('btnApplyWin');
if(bCloseWin)bCloseWin.onclick=closeWinModal;
if(bHeaderCloseWin)bHeaderCloseWin.onclick=closeWinModal;
if(bApplyWin)bApplyWin.onclick=async()=>{
if(!currentWinRow)return;
const selectedOpt=winAccountSelect.selectedOptions[0];
if(selectedOpt&&selectedOpt.value){currentWinRow.querySelector('.input-srv-user').value=selectedOpt.dataset.user;currentWinRow.dataset.encPassword=selectedOpt.dataset.enc;closeWinModal();return;}
const user=winUserInput.value.trim(),pwd=winPassInput.value;
if(user)currentWinRow.querySelector('.input-srv-user').value=user;
if(pwd){try{const res=await fetch('ajax_accounts.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'encrypt_pwd',password:pwd})}),data=await res.json();if(data.success){currentWinRow.dataset.encPassword=data.enc_password;alert(t('alert_pwd_encrypted','Mot de passe chiffré et mis à jour pour ce serveur.'));}else{alert(t('msg_error','Erreur : ')+data.error);}}catch(err){alert(t('msg_network_error','Erreur réseau'));}}
closeWinModal();
};
const sshModal=document.getElementById("sshModal"),sshConsole=document.getElementById("sshConsole"),sshTargetSpan=document.getElementById("sshModalTarget"),sshIpInput=document.getElementById("sshInputIp"),sshUserInput=document.getElementById("sshInputUser"),sshPassInput=document.getElementById("sshInputPassword"),sshProfileSelect=document.getElementById("sshProfileSelect"),btnRunSsh=document.getElementById("btnRunSsh");
let currentSshRow=null;
document.addEventListener('click',e=>{
const btn=e.target.closest('.btn-ssh-key');
if(btn&&sshModal){
currentSshRow=btn.closest('tr');
const ip=currentSshRow.querySelector('.input-srv-ip').value.trim(),user=currentSshRow.querySelector('.input-srv-user').value.trim(),srvName=currentSshRow.querySelector('.input-srv-name').value.trim();
sshTargetSpan.textContent=srvName||ip;sshIpInput.value=ip;sshUserInput.value=user;if(sshProfileSelect)sshProfileSelect.value=user||'';sshPassInput.value='';
sshConsole.textContent=t('ssh_console_ready',"Prêt. Entrez le mot de passe distant et cliquez sur 'Déployer la clé'.");
sshModal.classList.remove('view-hidden');sshModal.classList.add('active');sshModal.style.display='flex';
}
});
if(sshProfileSelect)sshProfileSelect.onchange=()=>{if(sshProfileSelect.value){sshUserInput.value=sshProfileSelect.value;}};
const closeSshModal=()=>{if(sshModal){sshModal.classList.add('view-hidden');sshModal.classList.remove('active');sshModal.style.display='none';}currentSshRow=null;};
const bCloseSsh=document.getElementById('btnCloseSsh'),bHeaderCloseSsh=document.getElementById('btnHeaderCloseSsh');
if(bCloseSsh)bCloseSsh.onclick=closeSshModal;
if(bHeaderCloseSsh)bHeaderCloseSsh.onclick=closeSshModal;
if(btnRunSsh)btnRunSsh.onclick=async()=>{
const ip=sshIpInput.value.trim(),user=sshUserInput.value.trim(),password=sshPassInput.value;
if(!password){alert(t('alert_fill_ssh_password','Veuillez saisir le mot de passe distant.'));return;}
btnRunSsh.disabled=true;
sshConsole.textContent="[1/3] "+t('log_ssh_step1',"Vérification de la clé locale (neovision)...")+"\n";
try{
const res=await fetch('deploy_ssh_key.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({ip,user,password})}),data=await res.json();
if(data.success){sshConsole.textContent+="[2/3] "+t('log_ssh_step2',"Connexion à {ip} via {user}...").replace('{ip}',ip).replace('{user}',user)+"\n[3/3] "+t('log_ssh_step3',"Clé publique injectée avec succès !")+"\n\n[✓] "+data.message;if(currentSshRow)currentSshRow.querySelector('.input-srv-user').value=user;}
else{sshConsole.textContent+=(data.output?"\n"+data.output:"")+"\n[✗] "+t('msg_error',"ERREUR : ")+(data.error||t('log_ssh_failed',"Échec du déploiement"));}
}catch(err){sshConsole.textContent+="[✗] "+t('msg_network_error',"Erreur de communication avec le serveur.");}
btnRunSsh.disabled=false;
};
document.addEventListener('click',async e=>{
const btn=e.target.closest('.btn-test-conn');
if(btn){
const row=btn.closest('tr'),name=row.querySelector('.input-srv-name').value.trim(),ip=row.querySelector('.input-srv-ip').value.trim(),typeSelect=row.querySelector('.input-srv-type'),type=typeSelect?typeSelect.value:'windows',userInput=row.querySelector('.input-srv-user'),user=userInput?userInput.value.trim():'';
if(!ip){alert(t('alert_fill_ip',"Veuillez saisir une adresse IP."));return;}
const origText=btn.textContent;btn.disabled=true;btn.classList.add('testing');btn.textContent="⏳ "+t('btn_testing',"Test...");
try{
const res=await fetch('test_connection.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name,ip,type,user})}),data=await res.json();
if(data.success){alert("✅ "+data.message);}else{alert("❌ "+data.error);}
}catch(err){alert("❌ "+t('msg_test_network_error',"Erreur réseau lors du test"));}
btn.disabled=false;btn.classList.remove('testing');btn.textContent=origText;
}
});
