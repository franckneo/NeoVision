let currentSnmpRow=null;
const backToTopBtn=document.getElementById("backToTop"),backToTopSpan=backToTopBtn?backToTopBtn.querySelector("span"):null;
function activateParamTab(param){
const btn=document.querySelector(`.param-nav-btn[data-param="${param}"]`),targetView=document.getElementById(`view-${param}`);
if(!btn||!targetView)return;
document.querySelectorAll(".param-nav-btn").forEach(b=>b.classList.remove("active"));
document.querySelectorAll(".param-view").forEach(v=>{v.classList.add("view-hidden");v.style.display="none";});
btn.classList.add("active");
targetView.classList.remove("view-hidden");
targetView.style.display="block";
localStorage.setItem("activeParamView",param);
document.cookie=`activeParamView=${param};path=/;max-age=2592000;SameSite=Lax`;
window.scrollTo({top:0});
const mainEl=document.getElementById("main");
if(mainEl)mainEl.scrollTop=0;
updateScrollButton();
setTimeout(updateScrollButton,60);
}
document.querySelectorAll(".param-nav-btn").forEach(btn=>{btn.onclick=()=>activateParamTab(btn.dataset.param);});
const urlTabParam=new URLSearchParams(window.location.search).get('tab')||localStorage.getItem("activeParamView");
if(urlTabParam)activateParamTab(urlTabParam);
function removeAccountRow(btn){const row=btn.closest('tr');if(row)row.remove();}
function addPcRow(){
const tbody=document.querySelector('#pcs-tbody')||document.querySelector('#tablePcs tbody'),tr=document.createElement('tr');
if(!tbody)return;
tr.className='pc-row';
tr.innerHTML=`<td><input type="text" class="table-input pc-input-computer" placeholder="ex: BELAP275"></td><td><input type="text" class="table-input pc-input-user" placeholder="ex: Reserve"></td><td><input type="text" class="table-input pc-input-site" placeholder="ex: Siege"></td><td><input type="text" class="table-input pc-input-details" placeholder="ex: Bureau"></td><td><input type="text" class="table-input pc-input-mac" placeholder="ex: a8:2b:dd:61:67:d1"></td><td style="text-align: center;"><button type="button" class="btn-trash" title="Supprimer" onclick="removeAccountRow(this)">🗑️</button></td>`;
tbody.appendChild(tr);
tr.querySelector('.pc-input-computer').focus();
}
function savePcs(){
const rows=document.querySelectorAll('.pc-row'),pcs=[];
rows.forEach(row=>{
const computer=row.querySelector('.pc-input-computer').value.trim(),user=row.querySelector('.pc-input-user').value.trim(),site=row.querySelector('.pc-input-site').value.trim(),detail=row.querySelector('.pc-input-details').value.trim(),mac=row.querySelector('.pc-input-mac').value.trim();
if(computer!=='')pcs.push({computer,user,site,detail,mac});
});
const formData=new FormData();
formData.append('action','save_pcs');
formData.append('pcs_data',JSON.stringify(pcs));
const btn=document.getElementById('btnSavePcs'),orig=btn?btn.innerHTML:'';
if(btn){btn.disabled=true;btn.innerHTML='⏳ '+(window.TXT_SAVING||'Enregistrement...');}
fetch(window.location.href,{method:'POST',body:formData}).then(res=>res.json()).then(data=>{
if(data.success){location.reload();}else{alert(data.error||'Erreur lors de la sauvegarde.');if(btn){btn.disabled=false;btn.innerHTML=orig;}}
}).catch(err=>{alert('Erreur réseau : '+err.message);if(btn){btn.disabled=false;btn.innerHTML=orig;}});
}
function addDeviceRow(type){
const tableId=type==='wifis'?'tableWifis':(type==='switchs'?'tableSwitchs':'tableOthers'),table=document.getElementById(tableId),tr=document.createElement('tr');tr.className='device-row';
const actionTd='<td class="text-center"><button type="button" class="btn-order btn-move-up" title="Monter">▲</button><button type="button" class="btn-order btn-move-down" title="Descendre">▼</button><button type="button" class="btn-trash btn-delete-device" title="Supprimer">🗑️</button></td>';
if(type==='wifis'||type==='switchs'){
const phName=type==='wifis'?'WIFI-SIEGE-01':'SW-SIEGE-01',phIp=type==='wifis'?'10.111.0.100':'10.111.0.124';
tr.innerHTML=`<td><input type="text" class="input-device-name" placeholder="ex: ${phName}"></td><td><input type="text" class="input-device-ip" placeholder="ex: ${phIp}"></td><td><input type="text" class="input-device-site" placeholder="ex: Siege"></td><td class="text-center"><button type="button" class="btn btn-sm btn-blue btn-test-device">Tester</button></td>`+actionTd;
}else{
tr.innerHTML='<td><input type="text" class="input-device-name" placeholder="ex: Sonde temp AND"></td><td><input type="text" class="input-device-ip" placeholder="ex: 10.101.3.2"></td><td><input type="text" class="input-device-site" placeholder="ex: Siege"></td><td><select class="input-device-type"><option value="">--</option><option value="interface">Interface</option><option value="snmp">SNMP</option></select></td><td class="text-center"><button type="button" class="btn-snmp-config btn-sm btn-blue view-hidden" data-version="1" data-community="public" data-oid="">⚙️ SNMP</button></td><td class="text-center"><button type="button" class="btn btn-sm btn-blue btn-test-device">Tester</button></td>'+actionTd;
}
table.querySelector('tbody').appendChild(tr);
}
function saveDevices(type){
const tableId=type==='wifis'?'tableWifis':(type==='switchs'?'tableSwitchs':'tableOthers'),table=document.getElementById(tableId),devices=[],errors=[];
table.querySelectorAll('tbody tr.device-row').forEach((tr,index)=>{
const name=tr.querySelector('.input-device-name')?.value.trim()||'',ip=tr.querySelector('.input-device-ip')?.value.trim()||'',site=tr.querySelector('.input-device-site')?.value.trim()||'Defaut',deviceType=type==='others'?tr.querySelector('.input-device-type')?.value.trim()||'':'';
if(!name)errors.push(`Ligne ${index+1} : nom manquant.`);
if(!ip)errors.push(`Ligne ${index+1} : adresse IP manquante.`);
if(type==='others'&&!deviceType)errors.push(`Ligne ${index+1} : type manquant.`);
if(!name||!ip||(type==='others'&&!deviceType))return;
const device={name,ip,site};
if(type==='others'){
device.type=deviceType;
if(device.type==='snmp'){
const btn=tr.querySelector('.btn-snmp-config');
device.snmp_version=btn?.dataset.version||'1';
device.community=btn?.dataset.community||'public';
device.oid=btn?.dataset.oid||'';
if(device.snmp_version==='3'){if(btn?.dataset.username)device.username=btn.dataset.username;if(btn?.dataset.privacyPassword)device.privacy_password=btn.dataset.privacyPassword;}
}
}
devices.push(device);
});
if(errors.length){alert('Enregistrement impossible :\n'+errors.join('\n'));return;}
const fd=new FormData();fd.append('action','save_'+type);fd.append('devices_data',JSON.stringify(devices));
fetch(window.location.href,{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
if(data.success){alert('Enregistrement réussi');location.reload();}else alert('Erreur: '+(data.error||'Erreur inconnue'));
}).catch(e=>alert('Erreur: '+e.message));
}
function updateScrollButton(){
if(!backToTopBtn)return;
const mainEl=document.getElementById("main"),scrollEl=mainEl&&mainEl.scrollHeight>mainEl.clientHeight?mainEl:document.documentElement,scrollY=scrollEl.scrollTop,canScroll=scrollEl.scrollHeight-scrollEl.clientHeight>80;
if(!canScroll){backToTopBtn.style.display="none";return;}
backToTopBtn.style.display="flex";
if(scrollY>150){if(backToTopSpan)backToTopSpan.textContent="↑";backToTopBtn.title=window.TXT_BACK_TO_TOP||"Retour en haut";}else{if(backToTopSpan)backToTopSpan.textContent="↓";backToTopBtn.title=window.TXT_SCROLL_BOTTOM||"Descendre en bas";}
}
if(backToTopBtn){
window.addEventListener("scroll",updateScrollButton,{passive:true});
const mainEl=document.getElementById("main");
if(mainEl)mainEl.addEventListener("scroll",updateScrollButton,{passive:true});
window.addEventListener("resize",updateScrollButton);
backToTopBtn.addEventListener("click",()=>{
const scrollY=window.scrollY||(mainEl?mainEl.scrollTop:0);
if(scrollY>150){window.scrollTo({top:0,behavior:"smooth"});if(mainEl)mainEl.scrollTo({top:0,behavior:"smooth"});}
else{const targetHeight=Math.max(document.documentElement.scrollHeight,mainEl?mainEl.scrollHeight:0);window.scrollTo({top:targetHeight,behavior:"smooth"});if(mainEl)mainEl.scrollTo({top:targetHeight,behavior:"smooth"});}
});
if(window.ResizeObserver){const ro=new ResizeObserver(()=>updateScrollButton());if(mainEl)ro.observe(mainEl);ro.observe(document.body);}
updateScrollButton();
window.addEventListener("load",updateScrollButton);
setTimeout(updateScrollButton,100);
}
document.addEventListener('change',e=>{
if(e.target.classList.contains('input-device-type')){
const row=e.target.closest('tr'),btnSnmp=row?row.querySelector('.btn-snmp-config'):null;
if(btnSnmp)btnSnmp.classList.toggle('view-hidden',e.target.value!=='snmp');
}
});
document.getElementById('snmpInputVersion')?.addEventListener('change',e=>{const v3Fields=document.getElementById('snmpV3Fields');if(v3Fields)v3Fields.classList.toggle('view-hidden',e.target.value!=='3');});
document.addEventListener('click',async e=>{
const btnSnmp=e.target.closest('.btn-snmp-config');
if(btnSnmp){
currentSnmpRow=btnSnmp;
const modal=document.getElementById('snmpModal');
if(modal){
document.getElementById('snmpInputVersion').value=btnSnmp.dataset.version||'1';
document.getElementById('snmpInputCommunity').value=btnSnmp.dataset.community||'public';
document.getElementById('snmpInputOid').value=btnSnmp.dataset.oid||'';
const v3Fields=document.getElementById('snmpV3Fields');document.getElementById('snmpInputUsername').value=btnSnmp.dataset.username||'';document.getElementById('snmpInputPrivacyPassword').value=btnSnmp.dataset.privacyPassword||'';if(v3Fields)v3Fields.classList.toggle('view-hidden',document.getElementById('snmpInputVersion').value!=='3');;
modal.classList.remove('view-hidden');
modal.style.setProperty('display','flex','important');
}
return;
}
const up=e.target.closest('.btn-move-up'),down=e.target.closest('.btn-move-down'),row=e.target.closest('tr');
if(row&&up&&row.previousElementSibling)row.parentNode.insertBefore(row,row.previousElementSibling);
if(row&&down&&row.nextElementSibling)row.parentNode.insertBefore(row.nextElementSibling,row);
if(e.target.closest('#btnCloseSnmp')||e.target.closest('#btnCloseSnmpX')){
const modal=document.getElementById('snmpModal');
if(modal){modal.classList.add('view-hidden');modal.style.setProperty('display','none','important');}
return;
}
const btnDel=e.target.closest('.btn-delete-device');
if(btnDel){btnDel.closest('tr').remove();return;}
const btnTest=e.target.closest('.btn-test-device');
if(btnTest){
const row=btnTest.closest('tr'),ip=row?row.querySelector('.input-device-ip')?.value.trim():'';
if(!ip){alert('Veuillez renseigner une adresse IP');return;}
const orig=btnTest.textContent;
btnTest.disabled=true;btnTest.textContent='⏳ Ping...';
try{
const fd=new FormData();fd.append('action','test_ping');fd.append('ip',ip);
const res=await fetch(window.location.href,{method:'POST',body:fd}),data=await res.json();
if(data.success)alert('✅ Ping OK : '+ip);else alert('❌ Échec Ping : '+(data.error||'Injoignable'));
}catch(err){alert('❌ Erreur réseau lors du test ping');}
btnTest.disabled=false;btnTest.textContent=orig;
return;
}
});
document.getElementById('btnApplySnmp')?.addEventListener('click',()=>{
if(currentSnmpRow){
const v=document.getElementById('snmpInputVersion'),c=document.getElementById('snmpInputCommunity'),o=document.getElementById('snmpInputOid');
if(v)currentSnmpRow.dataset.version=v.value;
if(c)currentSnmpRow.dataset.community=c.value;
if(o)currentSnmpRow.dataset.oid=o.value;
const u=document.getElementById('snmpInputUsername'),p=document.getElementById('snmpInputPrivacyPassword');if(u)currentSnmpRow.dataset.username=u.value.trim();if(p)currentSnmpRow.dataset.privacyPassword=p.value;
}
const modal=document.getElementById('snmpModal');
if(modal){modal.classList.add('view-hidden');modal.style.setProperty('display','none','important');}
});
document.getElementById('btnSaveMailConfig')?.addEventListener('click',async()=>{
const fd=new FormData();
fd.append('action','save_mail_config');
fd.append('sender_name',document.getElementById('mailSenderName').value.trim());
fd.append('from_email',document.getElementById('mailFromEmail').value.trim());
fd.append('reply_to',document.getElementById('mailReplyTo').value.trim());
fd.append('smtp_mode',document.getElementById('mailSmtpMode').value);
fd.append('smtp_host',document.getElementById('mailSmtpHost').value.trim());
fd.append('smtp_port',document.getElementById('mailSmtpPort').value);
fd.append('smtp_auth',document.getElementById('mailSmtpAuth').checked?'1':'0');
fd.append('smtp_user',document.getElementById('mailSmtpUser').value.trim());
fd.append('smtp_pass',document.getElementById('mailSmtpPass').value);
fd.append('smtp_secure',document.getElementById('mailSmtpSecure').value);
const button=document.getElementById('btnSaveMailConfig');
button.disabled=true;
try{
const response=await fetch(window.location.href,{method:'POST',body:fd}),data=await response.json();
if(data.success){alert('Configuration SMTP enregistrée.');location.reload();}
else alert(data.error||'Erreur lors de la sauvegarde.');
}catch(e){alert('Erreur réseau lors de la sauvegarde.');}
finally{button.disabled=false;}
});
document.getElementById('btnTestMailConfig')?.addEventListener('click',async()=>{
const button=document.getElementById('btnTestMailConfig'),fd=new FormData();
fd.append('action','test_mail_config');
button.disabled=true;
try{
const response=await fetch(window.location.href,{method:'POST',body:fd}),data=await response.json();
if(data.success)alert('Email de test envoyé à '+data.recipient+'.');
else alert(data.error||"Échec de l'envoi de l'email de test.");
}catch(e){alert("Erreur réseau lors de l'envoi de l'email de test.");}
finally{button.disabled=false;}
});
