// ============================================
// birthdays.js — Birthday Celebrants notification
// Pulls data from rpt_birthdays (via API), which is
// linked to rpt_users — kaya mga may account lang ang
// lalabas dito. Renders on Dashboard load.
// Standalone module; hindi umaasa sa core-init.js vars,
// kaya safe kahit anong pagkaka-order ng script tags.
// ============================================

function _bdayNextOccurrence(m,d,today){
  let year=today.getFullYear();
  let next=new Date(year,m-1,d);
  const t=new Date(today.getFullYear(),today.getMonth(),today.getDate());
  if(next<t) next=new Date(year+1,m-1,d);
  return next;
}

// Fetch (and cache) a user's avatar via the existing profile system,
// reusing userAvatarCache/getUserAvatarStyle from shared.js kung meron.
async function _bdayGetAvatar(username){
  if(typeof userAvatarCache!=="undefined" && userAvatarCache[username]!==undefined){
    return userAvatarCache[username];
  }
  try{
    const res=await fetch(CLOUD_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'getProfile',username})}).then(r=>r.json());
    const avatar=res&&res.avatar?res.avatar:null;
    if(typeof userAvatarCache!=="undefined") userAvatarCache[username]=avatar;
    return avatar;
  }catch(e){
    return null;
  }
}

// Renders a round avatar: real photo kung meron, colored initials circle kung wala.
function _bdayAvatarHTML(name,avatar,size){
  const initials=(name||"?").trim().substring(0,2).toUpperCase();
  let bg="#3d2a1a", color="#f59e0b";
  if(typeof getUserAvatarStyle==="function"){
    const style=getUserAvatarStyle(name);
    bg=style.bg; color=style.color;
  }
  const common=`width:${size}px;height:${size}px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:700;overflow:hidden;box-sizing:border-box;`;
  if(avatar){
    return `<div style="${common}border:2px solid rgba(255,255,255,.35)"><img src="${avatar}" alt="${name}" style="width:100%;height:100%;object-fit:cover"></div>`;
  }
  return `<div style="${common}background:${bg};color:${color};font-size:${Math.round(size*0.38)}px;border:2px solid rgba(255,255,255,.15)">${initials}</div>`;
}

async function renderBirthdayBanner(){
  const el=document.getElementById("birthdayBanner");
  if(!el) return;

  let celebrants=[];
  try{
    const res=await fetch(CLOUD_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'getBirthdays'})}).then(r=>r.json());
    if(res && res.success && Array.isArray(res.birthdays)) celebrants=res.birthdays;
  }catch(e){
    console.error('renderBirthdayBanner: failed to load birthdays',e);
    return;
  }
  if(celebrants.length===0){el.style.display="none";return;}

  const today=new Date();
  const todayKey=`${today.getMonth()+1}/${today.getDate()}`;

  const todayCelebrants=[];
  const upcoming=[];

  celebrants.forEach(c=>{
    // birth_date comes back as "YYYY-MM-DD"
    const [y,m,d]=c.birth_date.split("-").map(Number);
    const key=`${m}/${d}`;
    if(key===todayKey){
      const age=today.getFullYear()-y;
      todayCelebrants.push({name:c.full_name,username:c.username,age});
    }else{
      const next=_bdayNextOccurrence(m,d,today);
      const diffDays=Math.ceil((next-today)/(1000*60*60*24));
      if(diffDays<=30){
        upcoming.push({name:c.full_name,username:c.username,date:next,diffDays});
      }
    }
  });

  upcoming.sort((a,b)=>a.diffDays-b.diffDays);

  if(todayCelebrants.length===0 && upcoming.length===0){
    el.style.display="none";
    return;
  }

  // Preload avatars for everyone we're about to render (today + upcoming, capped)
  const shortUpcoming=upcoming.slice(0,6);
  const allPeople=[...todayCelebrants,...shortUpcoming];
  const avatars={};
  await Promise.all(allPeople.map(async c=>{
    avatars[c.username]=await _bdayGetAvatar(c.username);
  }));

  let html='<div class="bday-banner-wrap">';

  if(todayCelebrants.length>0){
    const cards=todayCelebrants.map(c=>{
      const avatarHTML=_bdayAvatarHTML(c.name,avatars[c.username],44);
      return `<div class="bday-today-card">
        ${avatarHTML}
        <div class="bday-today-info">
          <div class="bday-today-name">${c.name}</div>
          <div class="bday-today-age">Turning ${c.age} today 🎉</div>
        </div>
      </div>`;
    }).join("");
    html+=`<div class="bday-today-row">
      <div class="bday-today-header">🎂 Happy Birthday!</div>
      <div class="bday-today-cards">${cards}</div>
    </div>`;
  }

  if(shortUpcoming.length>0){
    const opts={month:"short",day:"numeric"};
    const items=shortUpcoming.map(c=>{
      const dstr=c.date.toLocaleDateString("en-US",opts);
      const when=c.diffDays===1?"tomorrow":`in ${c.diffDays} days`;
      const avatarHTML=_bdayAvatarHTML(c.name,avatars[c.username],28);
      return `<div class="bday-upcoming-chip">
        ${avatarHTML}
        <div class="bday-upcoming-text">
          <span class="bday-upcoming-name">${c.name}</span>
          <span class="bday-upcoming-date">${dstr} · ${when}</span>
        </div>
      </div>`;
    }).join("");
    html+=`<div class="bday-upcoming-row">
      <div class="bday-upcoming-header">🎈 Upcoming Birthdays <span class="bday-upcoming-sub">(next 30 days)</span></div>
      <div class="bday-upcoming-list">${items}</div>
    </div>`;
  }

  html+="</div>";
  el.innerHTML=html;
  el.style.display="block";
}

// Defer until DOM + CLOUD_URL (from core-init.js) are ready.
if(document.readyState==="loading"){
  document.addEventListener("DOMContentLoaded",()=>setTimeout(renderBirthdayBanner,0));
}else{
  setTimeout(renderBirthdayBanner,0);
}
// Auto-refresh ng banner kada 5 minuto habang naka-open ang tab, para
// makita agad ng ibang users ang bagong edit kahit hindi mag re-refresh.
setInterval(()=>{ if(!document.hidden) renderBirthdayBanner(); },5*60*1000);

// ============================================
// Settings — "My Birthday" (view/edit own record)
// ============================================
async function loadMyBirthday(){
  const nameEl=document.getElementById("bdayFullName");
  const dateEl=document.getElementById("bdayDate");
  if(!nameEl||!dateEl||typeof CURRENT_USER==="undefined"||!CURRENT_USER) return;
  nameEl.value="";
  dateEl.value="";
  try{
    const res=await fetch(CLOUD_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'getBirthdays'})}).then(r=>r.json());
    if(res && res.success && Array.isArray(res.birthdays)){
      const mine=res.birthdays.find(b=>b.username===CURRENT_USER);
      if(mine){
        nameEl.value=mine.full_name||"";
        dateEl.value=mine.birth_date||"";
      }else{
        nameEl.value=CURRENT_USER;
      }
    }
  }catch(e){
    console.error('loadMyBirthday: failed to load',e);
  }
}

async function saveMyBirthday(){
  const nameEl=document.getElementById("bdayFullName");
  const dateEl=document.getElementById("bdayDate");
  if(!nameEl||!dateEl) return;
  const fullName=nameEl.value.trim();
  const birthDate=dateEl.value.trim();
  if(!fullName||!birthDate){
    if(typeof showToast==="function") showToast("⚠️ Fill in your full name and birth date first.");
    else alert("Fill in your full name and birth date first.");
    return;
  }
  try{
    const res=await fetch(CLOUD_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'saveBirthday',username:CURRENT_USER,full_name:fullName,birth_date:birthDate})}).then(r=>r.json());
    if(res && res.error){
      if(typeof showToast==="function") showToast("❌ "+res.error); else alert(res.error);
      return;
    }
    if(typeof showToast==="function") showToast("🎂 Birthday saved!");
    renderBirthdayBanner();
  }catch(e){
    console.error('saveMyBirthday: failed to save',e);
    if(typeof showToast==="function") showToast("❌ Failed to save birthday."); else alert("Failed to save birthday.");
  }
}

// ============================================
// Login birthday greeting popup — shows once per
// day, per user, kapag ngayon ang birthday nila.
// Called from core-init.js's init() right after
// CURRENT_USER is set, so may laman na siya.
// ============================================
async function checkBirthdayPopup(username){
  if(!username) return;
  const todayStr=new Date().toDateString();
  const seenKey="rpt_bday_popup_seen";
  if(sessionStorage.getItem(seenKey)===todayStr) return; // isang beses lang kada araw/session

  try{
    const res=await fetch(CLOUD_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'getBirthdays'})}).then(r=>r.json());
    if(!res || !res.success || !Array.isArray(res.birthdays)) return;
    const mine=res.birthdays.find(b=>b.username===username);
    if(!mine) return;

    const [,m,d]=mine.birth_date.split("-").map(Number);
    const today=new Date();
    if(m===today.getMonth()+1 && d===today.getDate()){
      sessionStorage.setItem(seenKey,todayStr);
      const nameEl=document.getElementById("bdayPopupName");
      const modal=document.getElementById("bdayPopupModal");
      if(nameEl) nameEl.textContent=(mine.full_name||username).split(" ")[0];
      if(modal) modal.classList.add("show");
      _bdaySpawnConfetti();
      _bdayStartFireworks();
    }
  }catch(e){
    console.error('checkBirthdayPopup: failed',e);
  }
}

function closeBdayPopup(){
  const modal=document.getElementById("bdayPopupModal");
  if(modal) modal.classList.remove("show");
  _bdayStopFireworks();
  const layer=document.getElementById("bdayConfettiLayer");
  if(layer) layer.innerHTML="";
}

// ============================================
// Falling confetti (pure CSS, cheap on perf)
// ============================================
function _bdaySpawnConfetti(){
  const layer=document.getElementById("bdayConfettiLayer");
  if(!layer) return;
  layer.innerHTML="";
  const pieces=["🎉","🎊","🎈","✨","🎁","⭐"];
  const count=45;
  for(let i=0;i<count;i++){
    const span=document.createElement("span");
    span.className="bday-confetti-piece";
    span.textContent=pieces[Math.floor(Math.random()*pieces.length)];
    span.style.left=(Math.random()*100)+"vw";
    const dur=(4+Math.random()*3.5).toFixed(2);
    const delay=(Math.random()*2.5).toFixed(2);
    span.style.fontSize=(14+Math.random()*16)+"px";
    span.style.animationDuration=dur+"s";
    span.style.animationDelay=delay+"s";
    layer.appendChild(span);
  }
}

// ============================================
// Fireworks — lightweight canvas particle engine,
// no external libraries needed.
// ============================================
let _bdayFwCtx=null, _bdayFwRaf=null, _bdayFwParticles=[], _bdayFwLastLaunch=0, _bdayFwRunning=false;

function _bdayFwResize(){
  const c=document.getElementById("bdayFireworksCanvas");
  if(!c) return;
  c.width=window.innerWidth;
  c.height=window.innerHeight;
}

function _bdayFwLaunch(){
  const c=document.getElementById("bdayFireworksCanvas");
  if(!c) return;
  const colors=["#f59e0b","#ec4899","#8b5cf6","#22c55e","#3b82f6","#ef4444","#14b8a6"];
  const color=colors[Math.floor(Math.random()*colors.length)];
  const x=c.width*(0.15+Math.random()*0.7);
  const y=c.height*(0.2+Math.random()*0.35);
  const particleCount=45+Math.floor(Math.random()*25);
  for(let i=0;i<particleCount;i++){
    const angle=(Math.PI*2*i)/particleCount + Math.random()*0.2;
    const speed=1.5+Math.random()*3.5;
    _bdayFwParticles.push({
      x, y,
      vx:Math.cos(angle)*speed,
      vy:Math.sin(angle)*speed,
      life:1,
      decay:0.008+Math.random()*0.012,
      color,
      size:1.5+Math.random()*2
    });
  }
}

function _bdayFwTick(ts){
  if(!_bdayFwRunning) return;
  const c=document.getElementById("bdayFireworksCanvas");
  if(!c || !_bdayFwCtx){ _bdayFwRaf=requestAnimationFrame(_bdayFwTick); return; }

  _bdayFwCtx.clearRect(0,0,c.width,c.height);

  if(ts-_bdayFwLastLaunch>750){
    _bdayFwLaunch();
    _bdayFwLastLaunch=ts;
  }

  for(let i=_bdayFwParticles.length-1;i>=0;i--){
    const p=_bdayFwParticles[i];
    p.vy+=0.035; // gravity
    p.x+=p.vx;
    p.y+=p.vy;
    p.life-=p.decay;
    if(p.life<=0){ _bdayFwParticles.splice(i,1); continue; }
    _bdayFwCtx.globalAlpha=Math.max(p.life,0);
    _bdayFwCtx.fillStyle=p.color;
    _bdayFwCtx.beginPath();
    _bdayFwCtx.arc(p.x,p.y,p.size,0,Math.PI*2);
    _bdayFwCtx.fill();
  }
  _bdayFwCtx.globalAlpha=1;

  _bdayFwRaf=requestAnimationFrame(_bdayFwTick);
}

function _bdayStartFireworks(){
  const c=document.getElementById("bdayFireworksCanvas");
  if(!c) return;
  _bdayFwCtx=c.getContext("2d");
  _bdayFwResize();
  window.addEventListener("resize",_bdayFwResize);
  _bdayFwParticles=[];
  _bdayFwLastLaunch=0;
  _bdayFwRunning=true;
  _bdayFwLaunch();
  _bdayFwRaf=requestAnimationFrame(_bdayFwTick);
}

function _bdayStopFireworks(){
  _bdayFwRunning=false;
  if(_bdayFwRaf) cancelAnimationFrame(_bdayFwRaf);
  _bdayFwRaf=null;
  _bdayFwParticles=[];
  window.removeEventListener("resize",_bdayFwResize);
  const c=document.getElementById("bdayFireworksCanvas");
  if(c && _bdayFwCtx) _bdayFwCtx.clearRect(0,0,c.width,c.height);
}