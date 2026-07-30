// ============================================
// shared.js — extracted from home.html
// ============================================

function getUserAvatarStyle(name){if(!name)return{bg:'var(--accent-bg)',color:'var(--accent)'};let h=0;for(let i=0;i<name.length;i++)h=(h*31+name.charCodeAt(i))&0xffff;const[bg,color]=AVATAR_COLORS[h%AVATAR_COLORS.length];return{bg,color};}

function buildPrepCell(prepBy){const name=(prepBy||'').trim();const initials=name.substring(0,2).toUpperCase()||'?';const{bg,color}=getUserAvatarStyle(name);const cached=userAvatarCache[name];const inner=cached?`<img src="${cached}" alt="${name}">`:`<span>${initials}</span>`;return`<div class="prep-cell"><div class="prep-avatar" style="background:${bg};color:${color}" data-user="${name}" onmouseenter="showAvatarTooltip(event,'${name}')" onmouseleave="hideAvatarTooltip()" onclick="openAvatarPreview('${name}')">${inner}</div><span class="prep-name">${name||'—'}</span></div>`;}

function showAvatarTooltip(e,name){clearTimeout(tooltipTimer);tooltipTimer=setTimeout(()=>{const tip=document.getElementById('avatarTooltip'),wrap=document.getElementById('tooltipImgWrap');document.getElementById('tooltipName').textContent=name;const cached=userAvatarCache[name];const{bg,color}=getUserAvatarStyle(name);if(cached){wrap.innerHTML=`<img class="tip-img" src="${cached}" alt="${name}">`;}else{wrap.innerHTML=`<div class="tip-initials" style="background:${bg};color:${color}">${name.substring(0,2).toUpperCase()}</div>`;}const rect=e.target.getBoundingClientRect();tip.style.left=(rect.left+rect.width/2)+'px';tip.style.top=(rect.top-12)+'px';tip.style.transform='translate(-50%,-100%)';tip.classList.add('show');},300);}

function hideAvatarTooltip(){clearTimeout(tooltipTimer);document.getElementById('avatarTooltip').classList.remove('show');}

function openAvatarPreview(name){hideAvatarTooltip();const cached=userAvatarCache[name];const{bg,color}=getUserAvatarStyle(name);const wrap=document.getElementById('previewImgWrap');if(cached){wrap.innerHTML=`<img class="ap-img" src="${cached}" alt="${name}">`;}else{wrap.innerHTML=`<div class="ap-initials" style="background:${bg};color:${color}">${name.substring(0,2).toUpperCase()}</div>`;}document.getElementById('previewName').textContent=name;document.getElementById('previewRole').textContent=(sessionStorage.getItem('rpt_is_admin')==='1'&&name===CURRENT_USER)?'Administrator':'Staff';document.getElementById('avatarPreviewModal').classList.add('show');}

function closeAvatarPreview(){document.getElementById('avatarPreviewModal').classList.remove('show');}

async function prefetchUserAvatars(data){
  if(!data||!data.length)return;
  const users=[...new Set(data.map(r=>r[2]).filter(Boolean))];
  for(const username of users){
    if(userAvatarCache[username]!==undefined)continue;
    try{
      const res=await safeFetch(CLOUD_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'getProfile',username})});
      userAvatarCache[username]=res.avatar||null;
    }catch(e){userAvatarCache[username]=null;}
  }
}

function formatPHDate(val){if(!val)return"---";try{let s=String(val).trim();if(/^\d{2}\/\d{2}\/\d{4}/.test(s))return s;s=s.replace(" ","T");if(!/[Z+\-]\d*$/.test(s))s+="Z";const d=new Date(s);if(isNaN(d))return val;return d.toLocaleString("en-US",{timeZone:"Asia/Manila",month:"2-digit",day:"2-digit",year:"numeric",hour:"2-digit",minute:"2-digit",hour12:true});}catch(e){return val;}}

async function safeFetch(url,options={}){const res=await fetch(url,options);const rawText=await res.text();try{return JSON.parse(rawText);}catch(_){}const iArr=rawText.indexOf("["),iObj=rawText.indexOf("{");let start=-1;if(iArr===-1&&iObj===-1){showDebug(rawText);throw new Error("Server returned HTML, not JSON.");}if(iArr===-1)start=iObj;else if(iObj===-1)start=iArr;else start=Math.min(iArr,iObj);const closer=rawText[start]==="["?"]":"}";const end=rawText.lastIndexOf(closer);if(end===-1||end<start){showDebug(rawText);throw new Error("Cannot parse server response.");}try{return JSON.parse(rawText.substring(start,end+1));}catch(e){showDebug(rawText);throw new Error("Incomplete JSON from server.");}}

function showDebug(t){document.getElementById("debugRaw").textContent=t.substring(0,500);document.getElementById("debugBanner").style.display="block";}

function hideDebug(){document.getElementById("debugBanner").style.display="none";}

function buildAccentPresets(){const wrap=document.getElementById('accentPresets');if(!wrap)return;wrap.innerHTML=ACCENT_PRESETS.map(p=>`<div class="accent-dot${p.color===currentAccentColor?' active':''}" style="background:${p.color}" title="${p.label}" onclick="selectAccent('${p.color}')"></div>`).join('');}

// ============================================
// Dark / Light mode
// ============================================
function setThemeMode(mode){
  const root=document.documentElement;
  root.style.setProperty('--bg', mode==='light' ? '#f4f5f7' : '#0f1117');
  RPT_THEME.apply(currentAccentColor||getComputedStyle(root).getPropertyValue('--accent').trim()||'#3b82f6');
  localStorage.setItem('rpt_theme_mode', mode);
  updateThemeToggleUI();
}
function toggleThemeMode(){
  const isLight=document.body.classList.contains('theme-light');
  setThemeMode(isLight ? 'dark' : 'light');
}
function updateThemeToggleUI(){
  const isLight=document.body.classList.contains('theme-light');
  const icon=document.getElementById('themeToggleIcon');
  if(icon) icon.textContent = isLight ? '☀️' : '🌙';
  const dOpt=document.getElementById('themeModeOptDark');
  const lOpt=document.getElementById('themeModeOptLight');
  if(dOpt) dOpt.classList.toggle('active', !isLight);
  if(lOpt) lOpt.classList.toggle('active', isLight);
}

// ============================================
// Who's Online
// ============================================
async function sendHeartbeat(){
  if(typeof CURRENT_USER==="undefined"||!CURRENT_USER) return;
  try{
    await fetch(CLOUD_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'heartbeat',username:CURRENT_USER})});
  }catch(e){ console.error('sendHeartbeat: failed',e); }
}

// Reusable: fetch (and cache) a user's avatar via getProfile. Ginagamit
// dito, sa birthdays.js, at kahit saan pa kailangan ng profile picture.
async function fetchUserAvatar(username){
  if(userAvatarCache[username]!==undefined) return userAvatarCache[username];
  try{
    const res=await fetch(CLOUD_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'getProfile',username})}).then(r=>r.json());
    const avatar=res&&res.avatar?res.avatar:null;
    userAvatarCache[username]=avatar;
    return avatar;
  }catch(e){
    return null;
  }
}

// Builds a round avatar (real photo kung meron, colored initials kung wala).
function buildAvatarCircleHTML(name,avatar,size){
  const initials=(name||"?").trim().substring(0,2).toUpperCase();
  const{bg,color}=getUserAvatarStyle(name);
  const common=`width:${size}px;height:${size}px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:700;overflow:hidden;box-sizing:border-box;`;
  if(avatar){
    return `<div style="${common}" ><img src="${avatar}" alt="${name}" style="width:100%;height:100%;object-fit:cover"></div>`;
  }
  return `<div style="${common}background:${bg};color:${color};font-size:${Math.round(size*0.4)}px">${initials}</div>`;
}

async function fetchOnlineUsers(){
  const listEl=document.getElementById('onlineUsersList');
  const countEl=document.getElementById('onlineUsersCount');
  try{
    const res=await fetch(CLOUD_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'getOnlineUsers'})}).then(r=>r.json());
    if(!res || !res.success || !Array.isArray(res.online)) return;
    if(countEl) countEl.textContent=res.online.length;
    if(!listEl) return;
    if(res.online.length===0){
      listEl.innerHTML='<div class="online-users-empty">Walang online ngayon.</div>';
      return;
    }
    // Preload avatars for everyone online bago mag-render
    await Promise.all(res.online.map(u=>fetchUserAvatar(u.username)));
    listEl.innerHTML=res.online.map(u=>{
      const isMe=u.username===CURRENT_USER;
      const avatarHTML=buildAvatarCircleHTML(u.username,userAvatarCache[u.username],28);
      return `<div class="online-user-row"><div class="online-user-avatar-wrap">${avatarHTML}<span class="online-user-dot"></span></div><div><div class="online-user-name">${u.username}${isMe?' (You)':''}</div><div class="online-user-role">${u.role}</div></div></div>`;
    }).join('');
  }catch(e){
    console.error('fetchOnlineUsers: failed',e);
    if(listEl) listEl.innerHTML='<div class="online-users-empty">Failed to load.</div>';
  }
}

function toggleOnlineUsersPanel(){
  const panel=document.getElementById('onlineUsersPanel');
  if(!panel) return;
  const willShow=!panel.classList.contains('show');
  panel.classList.toggle('show', willShow);
  if(willShow) fetchOnlineUsers();
}

document.addEventListener('click',(e)=>{
  const wrap=document.getElementById('onlineUsersWrap');
  const panel=document.getElementById('onlineUsersPanel');
  if(!wrap || !panel || !panel.classList.contains('show')) return;
  if(!wrap.contains(e.target)) panel.classList.remove('show');
});


function previewAccent(color){currentAccentColor=color;RPT_THEME.apply(color);const btn=document.getElementById('accentPreviewBtn');if(btn)btn.style.background=color;buildAccentPresets();}

function selectAccent(color){previewAccent(color);const picker=document.getElementById('accentCustomPicker');if(picker)picker.value=color;}

async function saveProfileData(){RPT_THEME.set(currentAccentColor);const btn=document.getElementById('accentPreviewBtn');btn.disabled=true;btn.textContent='Saving…';try{const res=await safeFetch(CLOUD_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'saveProfile',username:CURRENT_USER,avatar:currentAvatarBase64||null,accentColor:currentAccentColor})});if(res.error){alert('Error: '+res.error);return;}sessionStorage.setItem('rpt_avatar',currentAvatarBase64||'');userAvatarCache[CURRENT_USER]=currentAvatarBase64||null;showToast('✅ Theme saved!');}catch(e){alert('Error: '+e.message);}finally{btn.disabled=false;btn.style.background=currentAccentColor;btn.textContent='💾 Save Theme';}}

async function loadProfileFromServer(){try{const res=await safeFetch(CLOUD_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'getProfile',username:CURRENT_USER})});if(res.avatar){currentAvatarBase64=res.avatar;sessionStorage.setItem('rpt_avatar',res.avatar);applyAvatarToSidebar(res.avatar);userAvatarCache[CURRENT_USER]=res.avatar;}if(res.accentColor){currentAccentColor=res.accentColor;sessionStorage.setItem('rpt_accent',res.accentColor);RPT_THEME.apply(res.accentColor);}}catch(e){}}

function handleAvatarFile(file){if(!file||!file.type.startsWith('image/'))return;const reader=new FileReader();reader.onload=e=>{const img=new Image();img.onload=()=>{const MAX=200,canvas=document.createElement('canvas');let w=img.width,h=img.height;if(w>h){if(w>MAX){h=h*MAX/w;w=MAX;}}else{if(h>MAX){w=w*MAX/h;h=MAX;}}canvas.width=w;canvas.height=h;canvas.getContext('2d').drawImage(img,0,0,w,h);setSettingsAvatarPreview(canvas.toDataURL('image/jpeg',0.8));};img.src=e.target.result;};reader.readAsDataURL(file);}

function setSettingsAvatarPreview(src){currentAvatarBase64=src;const imgEl=document.getElementById('settingsAvatarImg'),initials=document.getElementById('settingsAvatarInitials');imgEl.src=src;imgEl.style.display='block';initials.style.display='none';document.getElementById('avatarDelBtn').style.display='inline-flex';applyAvatarToSidebar(src);}

function clearSettingsAvatar(){currentAvatarBase64='';const imgEl=document.getElementById('settingsAvatarImg'),initials=document.getElementById('settingsAvatarInitials');imgEl.src='';imgEl.style.display='none';initials.style.display='';document.getElementById('avatarDelBtn').style.display='none';document.getElementById('avatarFileInput').value='';applyAvatarToSidebar(null);}

function applyAvatarToSidebar(src){const el=document.getElementById('avatarEl');if(!el)return;el.innerHTML=src?`<img src="${src}" alt="avatar">`:`<span id="avatarInitials">${CURRENT_USER.substring(0,2)}</span>`;}

function togglePin(){sidebarPinned=!sidebarPinned;document.getElementById("sidebar").classList.toggle("pinned",sidebarPinned);document.getElementById("pinBtn").style.color=sidebarPinned?"var(--accent)":"";sessionStorage.setItem("sidebar_pinned",sidebarPinned?"1":"0");}

function showView(name){document.querySelectorAll(".view").forEach(v=>v.classList.remove("active"));document.querySelectorAll(".nav-item").forEach(n=>n.classList.remove("active"));document.getElementById("view-"+name).classList.add("active");const nav=document.getElementById("nav-"+name);if(nav)nav.classList.add("active");document.getElementById("topbarTitle").textContent=viewTitles[name]||name;currentView=name;if(name==="liaison")loadMyLiaisonRecords();if(name==="released"&&!sectionState.released.loaded)loadReleased();if(name==="slli"&&!sectionState.slli.loaded)loadSLLI();if(name==="slrdi"&&!sectionState.slrdi.loaded)loadSLRDI();if(name==="lotinv")lotinvGoPage(1);if(name==="subdivmon"&&!sectionState.subdivmon.loaded)subdivmonGoPage(1);if(name==="orissuance"&&!sectionState.orissuance.loaded)loadOrIssuance();if(name==="asmc"&&!sectionState.asmc.loaded)loadAsmc();}

function handleRefresh(){if(currentView==="dashboard"){renderListFromCloud();return;}if(currentView==="computation"){applyFilterAndRender();return;}if(currentView==="liaison"){loadMyLiaisonRecords();return;}sessionStorage.removeItem("rpt_cache_"+currentView);sectionState[currentView].loaded=false;if(currentView==="released")loadReleased();if(currentView==="slli")loadSLLI();if(currentView==="slrdi")loadSLRDI();if(currentView==="orissuance")loadOrIssuance();if(currentView==="asmc")asmcLoad(1);}

async function checkAutoBackup(){
  try{
    const st=await fetch(BACKUP_URL+"?action=status").then(r=>r.json());
    if(st && st.needsAuto){
      const res=await fetch(BACKUP_URL+"?action=run").then(r=>r.json());
      if(res && res.success)showToast("✅ Auto-backup done: "+res.backup.file);
    }
  }catch(e){/* backup.php not reachable yet — silent fail, walang epekto sa app */}
}

async function loadBackupPanel(){
  const listEl=document.getElementById("backupList"),statusEl=document.getElementById("backupStatusText");
  statusEl.textContent="Checking status...";
  try{
    const [st,lst]=await Promise.all([
      fetch(BACKUP_URL+"?action=status").then(r=>r.json()),
      fetch(BACKUP_URL+"?action=list").then(r=>r.json())
    ]);
    statusEl.textContent=st.lastBackup?`Huling backup: ${st.lastBackup.date} (${(st.lastBackup.size/1024).toFixed(1)} KB) · ${st.totalBackups} total`:"No backups yet. Click Backup Now.";
    const backups=(lst.backups||[]).slice(0,10);
    listEl.innerHTML=backups.length?backups.map(b=>`<div style="display:flex;align-items:center;justify-content:space-between;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:8px 10px;font-size:11px">
      <div><div style="font-weight:600;color:var(--text)">${b.file}</div><div style="color:var(--muted)">${b.date} · ${(b.size/1024).toFixed(1)} KB</div></div>
      <div style="display:flex;gap:4px"><a class="btn btn-ghost" style="padding:4px 8px;font-size:11px;text-decoration:none" href="${BACKUP_URL}?action=download&file=${encodeURIComponent(b.file)}">⬇️</a>
      <button class="btn btn-ghost" style="padding:4px 8px;font-size:11px;color:#f87171" onclick="deleteBackupFile('${b.file}')">✕</button></div>
    </div>`).join(""):'<p style="font-size:11px;color:var(--muted)">No backup files yet.</p>';
  }catch(e){statusEl.textContent="Couldn't check backup status. Verify that backup.php is uploaded to the server.";}
}

async function runManualBackup(){
  const btn=document.getElementById("backupNowBtn");
  btn.disabled=true;btn.textContent="⏳ Nagba-backup...";
  try{
    const res=await fetch(BACKUP_URL+"?action=run").then(r=>r.json());
    if(res.error){alert(res.error);}else{showToast("✅ Backed up: "+res.backup.file);}
    loadBackupPanel();
  }catch(e){alert("Backup error. Verify that backup.php is uploaded to the server.");}
  btn.disabled=false;btn.textContent="💾 Backup Now";
}

async function deleteBackupFile(file){
  if(!await showConfirm(`Delete backup file "${file}"?`,{title:"Delete Backup",okLabel:"Delete",danger:true}))return;
  try{
    await fetch(BACKUP_URL+"?action=delete&file="+encodeURIComponent(file));
    loadBackupPanel();
  }catch(e){alert("Error deleting backup.");}
}

function logout(){try{fetch(CLOUD_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'logout'}),keepalive:true});}catch(e){}sessionStorage.clear();window.location.href="index.html";}

function goToNewForm(){sessionStorage.setItem("rpt_form_mode","new");sessionStorage.removeItem("rpt_edit_row");sessionStorage.removeItem("rpt_edit_data");window.location.href="form.html";}

function toggleAdmin(){const p=document.getElementById("adminPanel");p.style.display=p.style.display==="none"?"block":"none";}


function showToast(msg){const t=document.getElementById("toast");t.textContent=msg;t.style.display="block";setTimeout(()=>t.style.display="none",3000);}

function pbStart(){const bar=document.getElementById("topProgressBar");if(!bar)return;clearInterval(_pbTimer);_pbVal=0;bar.style.width="0%";bar.classList.add("active");_pbTimer=setInterval(()=>{_pbVal+=(90-_pbVal)*0.08+0.5;if(_pbVal>90)_pbVal=90;bar.style.width=_pbVal+"%";},120);}

function pbDone(){const bar=document.getElementById("topProgressBar");if(!bar)return;clearInterval(_pbTimer);bar.style.width="100%";setTimeout(()=>{bar.classList.remove("active");setTimeout(()=>{bar.style.width="0%";},300);},250);}

async function withProgress(fn){pbStart();try{await fn();}finally{pbDone();}}

function runWithProgress(fn){pbStart();setTimeout(()=>{try{fn();}finally{pbDone();}},50);}

function skeletonRows(colspan,rows=6,widths=null){
  let html="";
  for(let r=0;r<rows;r++){
    let cells="";
    for(let c=0;c<colspan;c++){
      const wcls=widths&&widths[c]?widths[c]:(c%3===0?'w-sm':c%3===1?'w-lg':'w-md');
      cells+=`<td><div class="skel skel-bar ${wcls}" style="animation-delay:${(r*0.06+c*0.03).toFixed(2)}s"></div></td>`;
    }
    html+=`<tr class="skel-row">${cells}</tr>`;
  }
  return html;
}

function showAlertModal(message,options={}){
  const overlay=document.getElementById("genAlertModal"),titleEl=document.getElementById("genAlertTitle"),msgEl=document.getElementById("genAlertMsg"),okBtn=document.getElementById("genAlertOkBtn");
  titleEl.textContent=(options.danger?"⚠️ ":"")+(options.title||"Notice");
  msgEl.textContent=message;
  okBtn.style.cssText=options.danger?"background:#7f1d1d;color:#fca5a5;border:1px solid #ef4444":"";
  okBtn.className=options.danger?"btn":"btn btn-primary";
  overlay.classList.add("show");
  return new Promise(resolve=>{
    function cleanup(){overlay.classList.remove("show");okBtn.removeEventListener("click",onOk);overlay.removeEventListener("click",onOverlay);resolve();}
    function onOk(){cleanup();}
    function onOverlay(e){if(e.target===overlay)cleanup();}
    okBtn.addEventListener("click",onOk);
    overlay.addEventListener("click",onOverlay);
  });
}

function showConfirm(message,options={}){
  const overlay=document.getElementById("genConfirmModal"),titleEl=document.getElementById("genConfirmTitle"),msgEl=document.getElementById("genConfirmMsg"),okBtn=document.getElementById("genConfirmOkBtn"),cancelBtn=document.getElementById("genConfirmCancelBtn");
  titleEl.textContent=(options.danger?"⚠️ ":"")+(options.title||"Please Confirm");
  msgEl.textContent=message;
  okBtn.textContent=options.okLabel||"OK";
  okBtn.style.cssText=options.danger?"background:#7f1d1d;color:#fca5a5;border:1px solid #ef4444":"";
  okBtn.className=options.danger?"btn":"btn btn-primary";
  overlay.classList.add("show");
  return new Promise(resolve=>{
    function cleanup(result){overlay.classList.remove("show");okBtn.removeEventListener("click",onOk);cancelBtn.removeEventListener("click",onCancel);overlay.removeEventListener("click",onOverlay);resolve(result);}
    function onOk(){cleanup(true);}
    function onCancel(){cleanup(false);}
    function onOverlay(e){if(e.target===overlay)cleanup(false);}
    okBtn.addEventListener("click",onOk);
    cancelBtn.addEventListener("click",onCancel);
    overlay.addEventListener("click",onOverlay);
  });
}

function openSettings(){const isAdmin=sessionStorage.getItem('rpt_is_admin')==='1';document.getElementById('settingsAvatarInitials').textContent=CURRENT_USER.substring(0,2);document.getElementById('settingsUsername').textContent=CURRENT_USER;document.getElementById('settingsRole').textContent=isAdmin?'Administrator':'Staff';document.getElementById('adminSettingsSection').style.display=isAdmin?'block':'none';if(isAdmin){loadAccountsList();loadBackupPanel();}const imgEl=document.getElementById('settingsAvatarImg'),initials=document.getElementById('settingsAvatarInitials');if(currentAvatarBase64){imgEl.src=currentAvatarBase64;imgEl.style.display='block';initials.style.display='none';document.getElementById('avatarDelBtn').style.display='inline-flex';}else{imgEl.src='';imgEl.style.display='none';initials.style.display='';document.getElementById('avatarDelBtn').style.display='none';}const picker=document.getElementById('accentCustomPicker'),previewBtn=document.getElementById('accentPreviewBtn');if(picker)picker.value=currentAccentColor;if(previewBtn)previewBtn.style.background=currentAccentColor;buildAccentPresets();if(typeof loadMyBirthday==="function")loadMyBirthday();if(typeof updateThemeToggleUI==="function")updateThemeToggleUI();document.getElementById('pwCurrent').value='';document.getElementById('pwNew').value='';document.getElementById('pwConfirm').value='';document.getElementById('settingsModal').classList.add('show');}

function closeSettings(){document.getElementById('settingsModal').classList.remove('show');}

async function loadAccountsList(){try{const data=await safeFetch(CLOUD_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'getUsers'})});const list=document.getElementById('accountsList');if(!data||!data.users){list.innerHTML='<p style="color:var(--muted);font-size:12px">No accounts data.</p>';return;}list.innerHTML=data.users.map(u=>`<div class="account-row"><div class="account-avatar">${u.username.substring(0,2)}</div><div class="account-info"><div class="aname">${u.username}</div><div class="arole">${u.role==='admin'?'Administrator':(u.role==='viewer'?'Viewer (view-only)':'Staff')}</div></div>${u.username!==CURRENT_USER?`<div class="account-actions"><button class="acc-btn acc-btn-del" onclick="deleteAccount('${u.username}')">Remove</button></div>`:'<div style="font-size:11px;color:var(--muted)">You</div>'}</div>`).join('');}catch(e){document.getElementById('accountsList').innerHTML='<p style="color:var(--muted);font-size:12px">Could not load accounts.</p>';}}

async function addAccount(){const username=document.getElementById('newUsername').value.trim().toUpperCase(),password=document.getElementById('newPassword').value.trim(),role=document.getElementById('newRole').value;if(!username||!password){alert('Username and password are required.');return;}try{const res=await safeFetch(CLOUD_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'addUser',username,password,role})});if(res.error){alert('Error: '+res.error);return;}showToast('✅ Account created: '+username);document.getElementById('newUsername').value='';document.getElementById('newPassword').value='';loadAccountsList();}catch(e){alert('Error: '+e.message);}}

async function deleteAccount(username){if(!await showConfirm('Remove account: '+username+'?',{title:"Remove Account",okLabel:"Remove",danger:true}))return;try{const res=await safeFetch(CLOUD_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'deleteUser',username})});if(res.error){alert('Error: '+res.error);return;}showToast('Account removed: '+username);loadAccountsList();}catch(e){alert('Error: '+e.message);}}

async function changePassword(){const cur=document.getElementById('pwCurrent').value,nw=document.getElementById('pwNew').value,cf=document.getElementById('pwConfirm').value;if(!cur||!nw||!cf){alert('Please fill in all fields.');return;}if(nw!==cf){alert('New password does not match.');return;}if(nw.length<4){alert('Password must be at least 4 characters.');return;}try{const res=await safeFetch(CLOUD_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'changePassword',username:CURRENT_USER,currentPassword:cur,newPassword:nw})});if(res.error){alert('❌ '+res.error);return;}showToast('✅ Password updated successfully!');document.getElementById('pwCurrent').value='';document.getElementById('pwNew').value='';document.getElementById('pwConfirm').value='';}catch(e){alert('Error: '+e.message);}}

function onSearchInput(){clearTimeout(window._mainSearchTimer);window._mainSearchTimer=setTimeout(()=>{currentPage=1;applyFilterAndRender();},150);}

function clearFilters(){document.getElementById("filterStart").value="";document.getElementById("filterEnd").value="";document.getElementById("filterUser").value="";currentPage=1;applyFilterAndRender();}

// ============================================
// Generic reusable table enhancements (works on any module's table)
// Sortable headers: sorts the rows currently in the DOM (current page).
// Density toggle: Comfortable/Compact, remembered per-table via localStorage.
// ============================================
function sortTableByColumn(th){
  const table=th.closest('table');
  const tbody=table.querySelector('tbody');
  if(!tbody) return;
  const ths=[...th.parentElement.children];
  const colIndex=ths.indexOf(th);
  const curDir=th.dataset.dir==='asc'?'asc':'desc';
  const newDir=th.classList.contains('sort-active')?(curDir==='asc'?'desc':'asc'):'asc';
  ths.forEach(t=>{t.classList.remove('sort-active');delete t.dataset.dir;const a=t.querySelector('.sort-arrow');if(a)a.textContent='▲';});
  th.classList.add('sort-active');th.dataset.dir=newDir;
  const arrow=th.querySelector('.sort-arrow');if(arrow)arrow.textContent=newDir==='asc'?'▲':'▼';
  const rows=[...tbody.querySelectorAll(':scope > tr')].filter(r=>!r.classList.contains('skel-row')&&!r.querySelector('.empty-state'));
  const dirMul=newDir==='asc'?1:-1;
  rows.sort((r1,r2)=>{
    const c1=(r1.children[colIndex]?.innerText||'').trim();
    const c2=(r2.children[colIndex]?.innerText||'').trim();
    const n1=parseFloat(c1.replace(/[₱,\s]/g,''));
    const n2=parseFloat(c2.replace(/[₱,\s]/g,''));
    if(!isNaN(n1)&&!isNaN(n2)&&/\d/.test(c1)&&/\d/.test(c2)){return (n1-n2)*dirMul;}
    const d1=Date.parse(c1),d2=Date.parse(c2);
    if(!isNaN(d1)&&!isNaN(d2)&&/\d{4}|\d\/\d/.test(c1)){return (d1-d2)*dirMul;}
    return c1.localeCompare(c2)*dirMul;
  });
  rows.forEach(r=>tbody.appendChild(r));
}
function toggleTableDensity(btn,wrapId){
  const wrap=document.getElementById(wrapId);
  if(!wrap) return;
  btn.parentElement.querySelectorAll('.density-btn').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
  const mode=btn.dataset.density;
  wrap.classList.toggle('density-compact',mode==='compact');
  localStorage.setItem('rpt_density_'+wrapId,mode);
}
function restoreTableDensity(wrapId){
  const mode=localStorage.getItem('rpt_density_'+wrapId);
  const wrap=document.getElementById(wrapId);
  if(!wrap) return;
  if(mode==='compact'){
    wrap.classList.add('density-compact');
    document.querySelectorAll(`[data-density-for="${wrapId}"] .density-btn`).forEach(b=>b.classList.toggle('active',b.dataset.density==='compact'));
  }
}

let compSortField=0,compSortDir=-1; // default: Date Saved, descending

function applyFilterAndRender(){const q=document.getElementById("searchBar").value.toUpperCase().trim(),fs=document.getElementById("filterStart").value,fe=document.getElementById("filterEnd").value,fu=document.getElementById("filterUser").value.toUpperCase();filteredData=window._cloudData.filter(r=>{if(q&&!`${r[1]} ${r[2]}`.toUpperCase().includes(q))return false;if(fu&&(r[2]||"").toUpperCase()!==fu)return false;if(fs||fe){const d=new Date(r[0]);if(fs&&d<new Date(fs))return false;if(fe&&d>new Date(fe+"T23:59:59"))return false;}return true;});
  filteredData.sort((a,b)=>{
    let av=a[compSortField],bv=b[compSortField];
    if(compSortField===0){av=new Date(av).getTime()||0;bv=new Date(bv).getTime()||0;}
    else if(compSortField===3){av=parseNum(av)||0;bv=parseNum(bv)||0;}
    else{av=(av||"").toString().toUpperCase();bv=(bv||"").toString().toUpperCase();}
    if(av<bv)return -1*compSortDir;if(av>bv)return 1*compSortDir;return 0;
  });
  document.querySelectorAll('#compTableWrap th.sortable').forEach(th=>{
    const isActive=Number(th.dataset.sort)===compSortField;
    th.classList.toggle('sort-active',isActive);
    const arrow=th.querySelector('.sort-arrow');
    if(arrow)arrow.textContent=isActive?(compSortDir===1?'▲':'▼'):'▲';
  });
  totalPages=Math.max(1,Math.ceil(filteredData.length/PAGE_SIZE));if(currentPage>totalPages)currentPage=totalPages;renderPage();}

function setCompSort(field){
  if(compSortField===field){compSortDir*=-1;}else{compSortField=field;compSortDir=field===0?-1:1;}
  applyFilterAndRender();
}
function setCompDensity(mode,btn){
  document.querySelectorAll('#compDensityToggle .density-btn').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('compTableWrap').classList.toggle('density-compact',mode==='compact');
  localStorage.setItem('rpt_comp_density',mode);
}

function goPage(p){if(p<1||p>totalPages)return;currentPage=p;renderPage();}

function renderPage(){
  const list=document.getElementById("savedList"),bar=document.getElementById("paginationBar");
  list.innerHTML="";
  if(!filteredData.length){list.innerHTML='<tr><td colspan="7" class="empty-state">No records found.</td></tr>';bar.style.display="none";return;}
  const start=(currentPage-1)*PAGE_SIZE,pageRows=filteredData.slice(start,start+PAGE_SIZE);
  const frag=document.createDocumentFragment();
  pageRows.forEach(row=>{
    const tr=document.createElement("tr");
    tr.innerHTML=`<td style="color:var(--sub);font-size:12px">${formatPHDate(row[0])}</td><td><span class="lot-name">${row[1]}</span></td><td>${buildPrepCell(row[2])}</td><td style="text-align:right"><span class="amount">&#8369;${fcy(parseNum(row[3]))}</span></td><td style="font-size:12px">${row[6]?row[6]:'<span style="opacity:.4">—</span>'}</td><td style="font-size:12px">${row[7]?row[7]:'<span style="opacity:.4">—</span>'}</td><td><div class="action-cell"><button class="edit-btn" data-row="${row[5]}"><svg width="12" height="12" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit</button><button class="del-btn" data-row="${row[5]}" data-lot="${row[1]}"><svg width="12" height="12" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>Delete</button><button class="btn btn-ghost" style="padding:4px 8px;font-size:11px" onclick="openRecordActivityLog(${row[5]},'${(row[1]||'').replace(/'/g,"\\'")}')" title="View activity log">🕒</button></div></td>`;
    frag.appendChild(tr);
  });
  list.appendChild(frag);
  attachEditListeners();
  bar.style.display="flex";
  const from=start+1,to=Math.min(start+PAGE_SIZE,filteredData.length);
  document.getElementById("pageInfo").textContent=`Showing ${from}–${to} of ${filteredData.length} records`;
  document.getElementById("btnFirst").disabled=currentPage===1;document.getElementById("btnPrev").disabled=currentPage===1;document.getElementById("btnNext").disabled=currentPage===totalPages;document.getElementById("btnLast").disabled=currentPage===totalPages;
  const nums=document.getElementById("pageNumbers");nums.innerHTML="";
  let startP=Math.max(1,currentPage-2),endP=Math.min(totalPages,startP+4);
  if(endP-startP<4)startP=Math.max(1,endP-4);
  for(let i=startP;i<=endP;i++){const b=document.createElement("button");b.className="page-num-btn"+(i===currentPage?" active":"");b.textContent=i;b.onclick=()=>goPage(i);nums.appendChild(b);}
}

function renderTableFromData(data){window._cloudData=data||[];currentPage=1;applyFilterAndRender();}

async function renderListFromCloud(){
  const list=document.getElementById("savedList");
  list.innerHTML=skeletonRows(5,8);
  document.getElementById("paginationBar").style.display="none";
  hideDebug();
  try{
    const data=await safeFetch(CLOUD_URL);
    window._cloudData=data||[];
    try{sessionStorage.setItem("rpt_cached_data",JSON.stringify(data));}catch(e){}
    updateStats(data);
    currentPage=1;
    applyFilterAndRender();
    prefetchUserAvatars(data).then(()=>applyFilterAndRender());
  }catch(e){
    list.innerHTML=`<tr><td colspan="5" class="empty-state" style="color:#f87171">Error: ${e.message}</td></tr>`;
  }
}

async function autoRefreshCloud(){
  try{
    const data=await safeFetch(CLOUD_URL);
    if(!data||!data.length)return;
    const hasNew=data.length!==window._cloudData.length||JSON.stringify(data.map(r=>r[5]))!==JSON.stringify(window._cloudData.map(r=>r[5]));
    if(hasNew){
      window._cloudData=data;
      try{sessionStorage.setItem("rpt_cached_data",JSON.stringify(data));}catch(e){}
      updateStats(data);
      applyFilterAndRender();
      prefetchUserAvatars(data).then(()=>applyFilterAndRender());
      showToast("🔄 Records updated!");
    }
    const el=document.getElementById("liveIndicator");
    if(el){const now=new Date();el.textContent="🟢 Live · "+now.toLocaleTimeString("en-US",{hour:"2-digit",minute:"2-digit",hour12:true});}
  }catch(e){}
}

function attachEditListeners(){
  document.querySelectorAll(".edit-btn").forEach(btn=>{btn.onclick=function(){const rowNum=this.dataset.row,found=window._cloudData.find(r=>String(r[5])===String(rowNum));if(!found){alert("Record not found.");return;}let fd=found[4];if(typeof fd==="object")fd=JSON.stringify(fd);if(typeof fd==="string"&&fd.startsWith('"'))try{fd=JSON.parse(fd);}catch(e){}sessionStorage.setItem("rpt_form_mode","edit");sessionStorage.setItem("rpt_edit_row",rowNum);sessionStorage.setItem("rpt_edit_data",typeof fd==="string"?fd:JSON.stringify(fd));window.location.href="form.html";};});
  window._pendingDeleteId=null;window._pendingDeleteLot="";
  document.querySelectorAll(".del-btn").forEach(btn=>{btn.onclick=function(){window._pendingDeleteId=this.dataset.row;window._pendingDeleteLot=this.dataset.lot;document.getElementById("deleteLotName").textContent=this.dataset.lot;document.getElementById("deleteModal").classList.add("show");};});
}

function closeDeleteModal(){document.getElementById("deleteModal").classList.remove("show");window._pendingDeleteId=null;}

async function confirmDelete(){const id=window._pendingDeleteId,lot=window._pendingDeleteLot;if(!id)return;closeDeleteModal();try{const result=await safeFetch(CLOUD_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete',id,changedBy:CURRENT_USER})});if(result.error){alert("Delete failed: "+result.error);return;}try{await safeFetch(CHAT_URL+"?type=chat",{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'chat',sender:'SYSTEM',message:`🗑️ SYSTEM: ${CURRENT_USER} DELETED record for Lot: ${lot}`})});loadChats(true);}catch(e){}showToast("Record deleted.");await renderListFromCloud();}catch(e){alert("Error: "+e.message);}}

function updateStats(data){if(!data||!data.length){document.getElementById("totalRecords").textContent="0";return;}const now=new Date();const thisMonth=now.getMonth(),thisYear=now.getFullYear();let sum=0,monthCount=0,monthSum=0,withOr=0,estimate=0;data.forEach(r=>{const amt=parseFloat((r[3]||"0").toString().replace(/[₱,]/g,""))||0;sum+=amt;const d=new Date(r[0]);if(d.getMonth()===thisMonth&&d.getFullYear()===thisYear){monthCount++;monthSum+=amt;}let jd;try{jd=typeof r[4]==="string"?JSON.parse(r[4]):r[4];}catch{jd={};}const wLand=jd?.wLand||[];if(wLand.length>0&&wLand[0][0]!=="")withOr++;else estimate++;});document.getElementById("totalRecords").textContent=data.length.toLocaleString();document.getElementById("sumTotal").textContent="₱"+sum.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});document.getElementById("monthRecords").textContent=monthCount.toLocaleString();document.getElementById("monthAmount").textContent="₱"+monthSum.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});document.getElementById("withOrCount").textContent=withOr.toLocaleString();document.getElementById("estimateCount").textContent=estimate.toLocaleString()+" Estimate";}

function escapeHtml(s){return s.replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));}

function makeThumbnailDataURL(file,maxDim){
  return new Promise((resolve,reject)=>{
    const url=URL.createObjectURL(file);
    const img=new Image();
    img.onload=()=>{
      let w=img.width,h=img.height;
      if(w>maxDim||h>maxDim){
        if(w>h){h=Math.round(h*maxDim/w);w=maxDim;}else{w=Math.round(w*maxDim/h);h=maxDim;}
      }
      const canvas=document.createElement("canvas");
      canvas.width=w;canvas.height=h;
      canvas.getContext("2d").drawImage(img,0,0,w,h);
      URL.revokeObjectURL(url);
      resolve(canvas.toDataURL("image/jpeg",0.82));
    };
    img.onerror=()=>{URL.revokeObjectURL(url);reject(new Error("Cannot load image"));};
    img.src=url;
  });
}

function clickAddExtraAttachment(){
  const liaisonId=document.getElementById("lm-id").value;
  if(!liaisonId){
    document.getElementById("lm-extra-status").textContent="Save the record first, then you can add more attachments.";
    document.getElementById("lm-extra-status").style.color="#f59e0b";
    return;
  }
  document.getElementById("lm-extraFile").click();
}

function handleExtraDragOver(event){
  event.preventDefault();
  const liaisonId=document.getElementById("lm-id").value;
  if(!liaisonId) return;
  event.currentTarget.style.borderColor="var(--accent,#22c55e)";
  event.currentTarget.style.background="rgba(34,197,94,.06)";
}

function handleExtraDragLeave(event){
  event.currentTarget.style.borderColor="var(--border,#333)";
  event.currentTarget.style.background="transparent";
}

function handleExtraDrop(event){
  event.preventDefault();
  event.currentTarget.style.borderColor="var(--border,#333)";
  event.currentTarget.style.background="transparent";
  const liaisonId=document.getElementById("lm-id").value;
  if(!liaisonId){
    document.getElementById("lm-extra-status").textContent="Save the record first, then you can add more attachments.";
    document.getElementById("lm-extra-status").style.color="#f59e0b";
    return;
  }
  const files=event.dataTransfer.files;
  if(files && files.length) handleAddExtraAttachment(files);
}

async function handleAddExtraAttachment(fileList){
  const files=Array.from(fileList||[]);
  if(!files.length) return;
  const liaisonId=document.getElementById("lm-id").value;
  const statusEl=document.getElementById("lm-extra-status");
  const allowed=['image/jpeg','image/png','image/webp','application/pdf'];
  let okCount=0, failCount=0;
  for(const file of files){
    if(!allowed.includes(file.type)){failCount++;continue;}
    statusEl.textContent=`Uploading ${file.name}...`;statusEl.style.color="var(--muted)";
    try{
      const fd=new FormData();
      fd.append("image",file);
      fd.append("liaisonId",liaisonId);
      const res=await fetch("upload_liaison_image.php",{method:"POST",body:fd}).then(r=>r.json());
      if(res.error){failCount++;continue;}
      const addRes=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"addLiaisonAttachment",liaisonId,filePath:res.path})}).then(r=>r.json());
      if(addRes.error){failCount++;continue;}
      okCount++;
    }catch(e){failCount++;}
  }
  document.getElementById("lm-extraFile").value="";
  statusEl.textContent=failCount?`✓ ${okCount} added, ${failCount} failed.`:`✓ ${okCount} attachment(s) added.`;
  statusEl.style.color=failCount?"#f59e0b":"#22c55e";
  await loadExtraAttachments(liaisonId);
}

async function loadExtraAttachments(liaisonId){
  const wrap=document.getElementById("lm-extra-attachments");
  if(!liaisonId){wrap.innerHTML="";lmExtraAttachments=[];return;}
  try{
    const data=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"getLiaisonAttachments",liaisonId})}).then(r=>r.json());
    lmExtraAttachments=data.rows||[];
  }catch(e){lmExtraAttachments=[];}
  renderExtraAttachments();
}

function renderExtraAttachments(){
  const wrap=document.getElementById("lm-extra-attachments");
  if(!lmExtraAttachments.length){wrap.innerHTML="";return;}
  wrap.innerHTML=lmExtraAttachments.map(a=>{
    const isPdf=/\.pdf$/i.test(a.file_path);
    const icon=isPdf?"📄":"🖼️";
    return `<div style="display:flex;align-items:center;gap:8px;padding:6px 8px;background:var(--surface2,#1a1a1a);border:1px solid var(--border,#333);border-radius:6px;font-size:12px">
      <a href="${a.file_path}" target="_blank" style="flex:1;color:inherit;text-decoration:none">${icon} ${a.file_path.split('/').pop()}</a>
      <button type="button" onclick="deleteExtraAttachment(${a.id})" style="background:none;border:none;color:#f87171;cursor:pointer;font-size:14px;padding:0 4px">✕</button>
    </div>`;
  }).join("");
}

async function deleteExtraAttachment(id){
  const liaisonId=document.getElementById("lm-id").value;
  try{
    await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"deleteLiaisonAttachment",id})}).then(r=>r.json());
  }catch(e){}
  await loadExtraAttachments(liaisonId);
}

async function openAllAttachmentsModal(){
  document.getElementById("allAttachmentsModal").classList.add("show");
  document.getElementById("allAttach-search").value="";
  document.getElementById("allAttach-list").innerHTML='<div class="empty-state">Loading...</div>';
  try{
    const data=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"getAllLiaisonAttachments"})}).then(r=>r.json());
    allAttachmentsData=data.rows||[];
  }catch(e){allAttachmentsData=[];}
  renderAllAttachments();
}

function closeAllAttachmentsModal(){document.getElementById("allAttachmentsModal").classList.remove("show");}

async function openLiaisonActivityLog(liaisonId,label){
  document.getElementById("activityLogRecordLabel").textContent=label?`— ${label}`:"";
  const list=document.getElementById("activityLogList");
  list.innerHTML=`<div style="padding:30px 0;text-align:center;color:var(--muted);font-size:12px">Loading history…</div>`;
  document.getElementById("liaisonActivityModal").classList.add("show");
  try{
    const res=await safeFetch(CLOUD_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'getLiaisonActivityLog',liaisonId})});
    if(res.error){list.innerHTML=`<div class="empty-state" style="color:#f87171">${res.error}</div>`;return;}
    renderActivityLog(res.log||[],res.labels||{});
  }catch(e){list.innerHTML=`<div class="empty-state" style="color:#f87171">Error loading activity log.</div>`;}
}

function closeLiaisonActivityLog(){document.getElementById("liaisonActivityModal").classList.remove("show");}

async function openRecordActivityLog(recordId,label){
  document.getElementById("activityLogRecordLabel").textContent=label?`— ${label}`:"";
  const list=document.getElementById("activityLogList");
  list.innerHTML=`<div class="empty-state">Loading...</div>`;
  document.getElementById("liaisonActivityModal").classList.add("show");
  try{
    const res=await safeFetch(CLOUD_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'getRecordActivityLog',recordId})});
    renderActivityLog(res.log||[],res.labels||{});
  }catch(e){list.innerHTML=`<div class="empty-state" style="color:#f87171">Error loading activity log.</div>`;}
}

function renderActivityLog(log,labels){
  const list=document.getElementById("activityLogList");
  if(!log.length){list.innerHTML=`<div class="empty-state">No activity recorded yet.</div>`;return;}
  list.innerHTML=log.map(entry=>{
    let bodyHtml="";
    const who=`<span class="activity-actor">${entry.changed_by||"Unknown"}</span>`;
    if(entry.action==="created"){
      bodyHtml=`${who} created this record. ${entry.note?`<span class="activity-diff">${entry.note}</span>`:""}`;
    }else if(entry.action==="deleted"){
      bodyHtml=`${who} deleted this record. ${entry.note?`<span class="activity-diff">${entry.note}</span>`:""}`;
    }else if(!entry.old_value&&!entry.new_value&&entry.note){
      const fieldLabel=labels[entry.field_name]||entry.field_name||"field";
      bodyHtml=`${who} updated <span class="activity-field">${fieldLabel}</span>: <span class="activity-diff">${entry.note}</span>`;
    }else{
      const fieldLabel=labels[entry.field_name]||entry.field_name||"field";
      const oldV=(entry.old_value||"").trim()||"(blank)";
      const newV=(entry.new_value||"").trim()||"(blank)";
      bodyHtml=`${who} updated <span class="activity-field">${fieldLabel}</span>: <span class="activity-diff"><span class="old">${oldV}</span> → <span class="new">${newV}</span></span>`;
    }
    let t="";
    if(entry.changed_at){try{const d=new Date(entry.changed_at.replace(" ","T")+"+08:00");t=d.toLocaleString([],{month:"short",day:"numeric",year:"numeric",hour:"2-digit",minute:"2-digit",hour12:true});}catch(e){}}
    return `<div class="activity-item"><div class="activity-dot ${entry.action}"></div><div class="activity-body">${bodyHtml}<div class="activity-time">${t}</div></div></div>`;
  }).join("");
}

function renderAllAttachments(){
  const q=(document.getElementById("allAttach-search").value||"").trim().toUpperCase();
  const list=document.getElementById("allAttach-list");
  const filtered=!q?allAttachmentsData:allAttachmentsData.filter(a=>`${a.ra_no||""} ${a.buyer||""} ${a.liaison_name||""}`.toUpperCase().includes(q));
  if(!filtered.length){list.innerHTML='<div class="empty-state">No attachments found.</div>';return;}
  list.innerHTML=filtered.map(a=>{
    const isPdf=/\.pdf$/i.test(a.file_path);
    const icon=isPdf?"📄":"🖼️";
    const fname=a.file_path.split('/').pop();
    return `<div style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--surface2,#1a1a1a);border:1px solid var(--border,#333);border-radius:8px">
      <div style="font-size:22px">${icon}</div>
      <div style="flex:1;min-width:0">
        <a href="${a.file_path}" target="_blank" style="color:inherit;text-decoration:underline;font-size:13px;word-break:break-all">${fname}</a>
        <div style="font-size:11px;color:var(--muted);margin-top:2px">RA# ${a.ra_no||"---"} • ${a.buyer||"---"} • ${a.liaison_name?"Liaison: "+a.liaison_name+" • ":""}${a.uploaded_at||""}</div>
      </div>
      <button type="button" class="btn btn-ghost" style="font-size:11px;padding:5px 10px" onclick="openLiaisonRecordById(${a.liaison_id})">Open Record</button>
    </div>`;
  }).join("");
}

function parseFlexDate(str){
  if(!str)return null;
  str=String(str).trim();
  if(!str)return null;
  let m=str.match(/^(\d{4})-(\d{1,2})-(\d{1,2})/); // yyyy-mm-dd
  if(m)return new Date(+m[1],+m[2]-1,+m[3]);
  m=str.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})/); // mm/dd/yy or mm/dd/yyyy
  if(m){
    let yr=+m[3];
    if(yr<100)yr+=(yr<70?2000:1900);
    return new Date(yr,+m[1]-1,+m[2]);
  }
  const d=new Date(str);
  return isNaN(d.getTime())?null:d;
}

function toISODateInput(str){
  const d=parseFlexDate(str);
  if(!d)return "";
  const yr=d.getFullYear(),mo=String(d.getMonth()+1).padStart(2,"0"),dy=String(d.getDate()).padStart(2,"0");
  return `${yr}-${mo}-${dy}`;
}


// ============================================
// Shared Excel export helper — builds a workbook with
// Sta. Lucia Realty & Sta. Lucia Land logos in the header
// ============================================
async function buildLogoWorkbook(title, columns, rows, opts){
  opts=opts||{};
  const wb=new ExcelJS.Workbook();
  wb.creator="RPT System";
  wb.created=new Date();
  const ws=wb.addWorksheet(opts.sheetName||title.slice(0,31));

  const colCount=columns.length;
  const headerRowIdx=5; // logos occupy rows 1-3, subtitle row 4, blank row spacing, table header at row 5

  // Logos
  const realtyImgId=wb.addImage({base64:RPT_LOGO_REALTY_B64,extension:"png"});
  const landImgId=wb.addImage({base64:RPT_LOGO_LAND_B64,extension:"png"});
  ws.addImage(realtyImgId,{tl:{col:0,row:0},ext:{width:150,height:83}});
  ws.addImage(landImgId,{tl:{col:colCount-2,row:0},ext:{width:150,height:82}});
  ws.getRow(1).height=20; ws.getRow(2).height=20; ws.getRow(3).height=20;

  // Title row
  ws.mergeCells(4,1,4,colCount);
  const titleCell=ws.getCell(4,1);
  titleCell.value=`${title} - Generated: ${new Date().toLocaleString()}`;
  titleCell.font={bold:true,size:12,color:{argb:"FF1F3D2B"}};
  titleCell.alignment={horizontal:"center"};
  ws.getRow(4).height=20;

  if(opts.subtitle){
    ws.mergeCells(5,1,5,colCount);
    const subCell=ws.getCell(5,1);
    subCell.value=opts.subtitle;
    subCell.font={italic:true,size:10,color:{argb:"FF555555"}};
    subCell.alignment={horizontal:"center"};
    ws.getRow(5).height=16;
  }

  const tableHeaderRow=opts.subtitle?7:6;
  ws.getRow(tableHeaderRow-1).height=6;

  ws.columns=columns.map(c=>({key:c.key,width:c.width}));
  const hRow=ws.getRow(tableHeaderRow);
  columns.forEach((c,i)=>{
    const cell=hRow.getCell(i+1);
    cell.value=c.header;
    cell.font={bold:true,color:{argb:"FFFFFFFF"}};
    cell.fill={type:"pattern",pattern:"solid",fgColor:{argb:"FF1A5D2B"}};
    cell.alignment={horizontal:"center",vertical:"middle"};
  });
  hRow.height=18;

  rows.forEach(r=>{
    const row=[];
    columns.forEach(c=>row.push(r[c.key]));
    ws.addRow(row);
  });

  ws.views=[{state:"frozen",ySplit:tableHeaderRow}];
  return wb;
}