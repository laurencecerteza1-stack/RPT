// ============================================
// chat.js — extracted from home.html
// ============================================

function chatSkeleton(){
  const pattern=[false,true,false,false,true,false];
  return '<div class="chat-skel">'+pattern.map((me,i)=>`<div class="chat-skel-row${me?' me':''}"><div class="skel chat-skel-bubble" style="width:${120+((i*37)%90)}px;animation-delay:${(i*0.08).toFixed(2)}s"></div></div>`).join("")+'</div>';
}

function ensureNotifPermission(){
  if(!("Notification" in window))return;
  if(Notification.permission==="default"&&!notifPermissionAsked){
    notifPermissionAsked=true;
    Notification.requestPermission();
  }
}

function playNotifSound(){
  try{
    const ctx=new (window.AudioContext||window.webkitAudioContext)();
    const o=ctx.createOscillator(),g=ctx.createGain();
    o.connect(g);g.connect(ctx.destination);
    o.type="sine";o.frequency.value=880;
    g.gain.setValueAtTime(0.16,ctx.currentTime);
    g.gain.exponentialRampToValueAtTime(0.0001,ctx.currentTime+.35);
    o.start();o.stop(ctx.currentTime+.35);
  }catch(e){}
}

function notifyNewChatMessage(log){
  if(!log||log.sender===CURRENT_USER||log.sender==="SYSTEM")return;
  playNotifSound();
  if(document.title.indexOf("🔴")!==0)document.title="🔴 "+document.title;
  if("Notification" in window&&Notification.permission==="granted"&&(document.hidden||!chatOpen)){
    try{
      const n=new Notification("💬 "+log.sender,{body:(log.message||"").substring(0,120),tag:"rpt-chat",renotify:true});
      n.onclick=()=>{window.focus();if(!chatOpen)toggleChat();n.close();};
    }catch(e){}
  }
}

function toggleChat(){chatOpen=!chatOpen;document.getElementById("chatWindow").classList.toggle("open",chatOpen);if(chatOpen){unreadCount=0;document.getElementById("chatBadge").style.display="none";document.title=document.title.replace(/^🔴\s*/,"");const msgs=document.getElementById("chatMessages");setTimeout(()=>msgs.scrollTop=msgs.scrollHeight,50);document.getElementById("chatInput").focus();}}

async function sendChat(){const inp=document.getElementById("chatInput"),msg=inp.value.trim();if(!msg)return;inp.value="";inp.disabled=true;try{const res=await safeFetch(CHAT_URL+"?type=chat",{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'chat',sender:CURRENT_USER,message:msg})});if(res&&res.error){console.error('Chat send error:',res.error);showToast('❌ Failed to send: '+res.error);inp.value=msg;}else{await loadChats(true);}}catch(e){console.error('Chat send failed:',e);showToast('❌ Failed to send message. Check connection.');inp.value=msg;}finally{inp.disabled=false;inp.focus();}}

async function loadChats(forceRender=false){
  try{
    const logs=await safeFetch(CHAT_URL+"?type=chat");
    if(!logs||!Array.isArray(logs))return;
    checkMentionNotification(logs);

    // ── FIX 2: gumamit ng message count + last message key para detect changes ──
    // Mas maaasahan kaysa timestamp comparison
    const newCount=logs.length;
    const newKey=logs.length>0?(logs[logs.length-1].sender+"|"+logs[logs.length-1].message):"";
    const hasNew=forceRender||(newCount!==lastChatCount)||(newKey!==lastChatIds);
    if(!hasNew)return;

    const prevCount=lastChatCount;
    lastChatCount=newCount;
    lastChatIds=newKey;

    // Prefetch avatars for any chat senders we haven't cached yet, then re-render once loaded
    const chatSenders=[...new Set(logs.map(l=>l.sender).filter(s=>s&&s!=="SYSTEM"))];
    const missing=chatSenders.filter(s=>userAvatarCache[s]===undefined);
    if(missing.length){
      Promise.all(missing.map(async username=>{
        try{const res=await safeFetch(CLOUD_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'getProfile',username})});userAvatarCache[username]=res.avatar||null;}
        catch(e){userAvatarCache[username]=null;}
      })).then(()=>loadChats(true));
    }

    const box=document.getElementById("chatMessages");
    const wasAtBottom=box.scrollHeight-box.scrollTop-box.clientHeight<60;

    box.innerHTML=logs.map(log=>{
      const isMe=log.sender===CURRENT_USER,isSys=log.sender==="SYSTEM";
      // Format timestamp — compatible sa PHP DATE_FORMAT output
      let t="";
      if(log.timestamp){
        try{
          // PHP returns "2025-01-15T14:30:00" (walang Z) — dagdag natin +08:00
          const ts=log.timestamp.includes("T")?log.timestamp+"":log.timestamp;
          const d=new Date(ts.replace(" ","T")+(ts.includes("+")||ts.endsWith("Z")?"":"+08:00"));
          if(!isNaN(d))t=d.toLocaleTimeString([],{hour:"2-digit",minute:"2-digit",hour12:true});
        }catch(e){}
      }
      if(isSys)return`<div class="cmsg cmsg-sys"><div class="cmsg-bubble">${log.message}</div><div class="cmsg-time">${t}</div></div>`;
      const{bg,color}=getUserAvatarStyle(log.sender);
      const cachedAv=userAvatarCache[log.sender];
      const avatarInner=cachedAv?`<img src="${cachedAv}" alt="${log.sender}">`:log.sender.substring(0,2).toUpperCase();
      const avatarHtml=`<div class="cmsg-avatar" style="background:${bg};color:${color}" onclick="openAvatarPreview('${log.sender}')">${avatarInner}</div>`;
      const{mentionsMe,html:renderedMsg}=renderMentions(log.message);
      const bubbleHtml=`<div class="cmsg ${isMe?"cmsg-me":"cmsg-them"}">${!isMe?`<div class="cmsg-sender">${log.sender}</div>`:""}<div class="cmsg-bubble${mentionsMe&&!isMe?" mentions-me":""}">${renderedMsg}</div><div class="cmsg-time">${t}</div></div>`;
      return`<div class="cmsg-with-avatar${isMe?" me":""}">${avatarHtml}${bubbleHtml}</div>`;
    }).join("");

    // Badge para sa unread
    const isNewMsg=newCount>prevCount&&prevCount>0;
    if(isNewMsg){
      notifyNewChatMessage(logs[logs.length-1]);
    }
    if(isNewMsg&&!chatOpen&&!forceRender){
      unreadCount+=newCount-prevCount;
      const badge=document.getElementById("chatBadge");
      badge.textContent=unreadCount>9?"9+":unreadCount;
      badge.style.display="flex";
    }

    const sub=document.getElementById("chatOnlineStatus");
    if(sub)sub.textContent=`${logs.length} messages`;
    chatLoadFailed=false;

    if(chatOpen||wasAtBottom||forceRender)box.scrollTop=box.scrollHeight;
  }catch(e){
    console.error('Chat load failed:',e);
    const sub=document.getElementById("chatOnlineStatus");
    if(sub)sub.textContent="⚠️ Connection issue";
    // Show a visible error only once per failure streak, not every 3s poll
    if(!chatLoadFailed){chatLoadFailed=true;if(chatOpen)showToast('⚠️ Could not load messages. Check if the server/DB is running.');}
  }
}

async function loadChatUsersList(){
  try{
    const data=await safeFetch(CLOUD_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'getUsers'})});
    if(data&&data.users)chatUsersList=data.users.map(u=>u.username);
  }catch(e){}
}

function renderMentions(message){
  const safe=escapeHtml(message);
  let mentionsMe=false;
  const html=safe.replace(/@([A-Za-z0-9_]+)/g,(match,name)=>{
    const isKnown=chatUsersList.some(u=>u.toUpperCase()===name.toUpperCase());
    if(!isKnown)return match;
    if(name.toUpperCase()===CURRENT_USER.toUpperCase())mentionsMe=true;
    return `<span class="cmsg-mention">@${name}</span>`;
  });
  return{mentionsMe,html};
}

function checkMentionNotification(logs){
  if(!logs.length)return;
  const last=logs[logs.length-1];
  if(last.sender===CURRENT_USER||last.sender==="SYSTEM")return;
  const key=last.sender+"|"+last.message+"|"+last.timestamp;
  if(key===lastMentionNotifiedKey)return;
  const re=new RegExp("@"+CURRENT_USER.replace(/[.*+?^${}()|[\]\\]/g,"\\$&"),"i");
  if(re.test(last.message)){
    lastMentionNotifiedKey=key;
    showToast(`🔔 ${last.sender} mentioned you: "${last.message.substring(0,60)}"`);
    if(!chatOpen){
      const badge=document.getElementById("chatBadge");
      if(badge){badge.style.display="flex";badge.style.background="#f59e0b";}
    }
  }
}

function chatInputMentionCheck(){
  const inp=document.getElementById("chatInput"),list=document.getElementById("chatMentionList");
  const val=inp.value,pos=inp.selectionStart;
  const uptoCursor=val.substring(0,pos);
  const m=uptoCursor.match(/@([A-Za-z0-9_]*)$/);
  if(!m){list.classList.remove("show");list.innerHTML="";mentionActiveIndex=-1;return;}
  const query=m[1].toUpperCase();
  const matches=chatUsersList.filter(u=>u.toUpperCase().includes(query)).slice(0,6);
  if(!matches.length){list.classList.remove("show");list.innerHTML="";mentionActiveIndex=-1;return;}
  mentionActiveIndex=0;
  list.innerHTML=matches.map((u,i)=>{
    const{bg,color}=getUserAvatarStyle(u);
    return `<div class="chat-mention-item${i===0?" active":""}" data-user="${u}" onclick="selectMention('${u}')"><div class="cmsg-avatar" style="width:18px;height:18px;font-size:8px;background:${bg};color:${color}">${u.substring(0,2).toUpperCase()}</div>${u}</div>`;
  }).join("");
  list.classList.add("show");
}

function selectMention(username){
  const inp=document.getElementById("chatInput");
  const val=inp.value,pos=inp.selectionStart;
  const uptoCursor=val.substring(0,pos);
  const newUpto=uptoCursor.replace(/@([A-Za-z0-9_]*)$/,"@"+username+" ");
  inp.value=newUpto+val.substring(pos);
  document.getElementById("chatMentionList").classList.remove("show");
  inp.focus();
}

function chatInputKeyNav(e){
  const list=document.getElementById("chatMentionList");
  if(!list.classList.contains("show"))return;
  const items=[...list.querySelectorAll(".chat-mention-item")];
  if(!items.length)return;
  if(e.key==="ArrowDown"){e.preventDefault();mentionActiveIndex=(mentionActiveIndex+1)%items.length;}
  else if(e.key==="ArrowUp"){e.preventDefault();mentionActiveIndex=(mentionActiveIndex-1+items.length)%items.length;}
  else if(e.key==="Enter"||e.key==="Tab"){e.preventDefault();selectMention(items[mentionActiveIndex].dataset.user);return;}
  else if(e.key==="Escape"){list.classList.remove("show");return;}
  else return;
  items.forEach((it,i)=>it.classList.toggle("active",i===mentionActiveIndex));
}

function kbIsOpen(id,cls){const el=document.getElementById(id);return el&&el.classList.contains(cls);}

