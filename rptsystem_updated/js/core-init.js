// ============================================
// core-init.js — shared state, constants, and page
// initialization. Loads LAST so all module functions
// above are already defined by the time this runs.
// ============================================

const CLOUD_URL="api.php",CHAT_URL="api.php",BACKUP_URL="backup.php";
const SLLI_URL="https://script.google.com/macros/s/AKfycbyXO_Hu5s363AAJDl7Wc09jICRySrGMPfhFrJE1n8gfZvGw5yZaM0LBK7ziD6SfGV5xvA/exec";
const SLRDI_URL="https://script.google.com/macros/s/AKfycbyGUEiXnAy2o83cAt92HHrJV8vUGf7Q_3_gI1ophh4IJZ4j_PrafGllIBp_u07iFiVQ/exec";

let CURRENT_USER="",CURRENT_ROLE="staff",currentView="dashboard",chatLoadFailed=false;
// ── FIX 2: Chat — gamitin ang message count bilang change detector, hindi timestamp ──
let chatOpen=false,unreadCount=0,sidebarPinned=false;
let lastChatCount=0,lastChatIds="";
let chatUsersList=[],lastMentionNotifiedKey="",mentionActiveIndex=-1;
window._cloudData=[];
const userAvatarCache={};

const sectionState={released:{index:[],limit:100,loaded:false},slli:{index:[],limit:100,loaded:false},slrdi:{index:[],limit:100,loaded:false},subdivmon:{index:[],limit:100,loaded:false},orissuance:{index:[],limit:100,loaded:false},asmc:{loaded:false}};
const fcy=n=>new Intl.NumberFormat("en-US",{minimumFractionDigits:2,maximumFractionDigits:2}).format(n);
const parseNum=s=>parseFloat(String(s).replace(/,/g,""))||0;

const AVATAR_COLORS=[['#1e3a5f','#3b82f6'],['#14291e','#22c55e'],['#3d1a2e','#ec4899'],['#2d1b2e','#8b5cf6'],['#1c1a10','#f59e0b'],['#1a2e2e','#14b8a6'],['#2e1a1a','#ef4444'],['#1e2e1e','#4ade80']];




let tooltipTimer;












const ACCENT_PRESETS=[{color:'#3b82f6',label:'Blue'},{color:'#6366f1',label:'Indigo'},{color:'#8b5cf6',label:'Purple'},{color:'#ec4899',label:'Pink'},{color:'#ef4444',label:'Red'},{color:'#f59e0b',label:'Amber'},{color:'#22c55e',label:'Green'},{color:'#14b8a6',label:'Teal'},{color:'#0ea5e9',label:'Sky'}];
let currentAccentColor=sessionStorage.getItem('rpt_accent')||'#3b82f6';
let currentAvatarBase64='';









document.addEventListener('paste',function(e){if(!document.getElementById('settingsModal').classList.contains('show'))return;const items=e.clipboardData&&e.clipboardData.items;if(!items)return;for(let i=0;i<items.length;i++){if(items[i].type.startsWith('image/')){handleAvatarFile(items[i].getAsFile());break;}}});


const viewTitles={dashboard:"Dashboard",computation:"Computation",liaison:"Liaison",released:"Released",slli:"DOCS REQ TD - SLLI",slrdi:"DOCS REQ TD - SLRDI",lotinv:"Lot Inventory",subdivmon:"Subdivision Monitor",orissuance:"OR Issuance",asmc:"AS with MC"};



// ── Viewer role: disable add/edit/delete/import controls, keep view/search/export ──
const VIEWER_SAFE_FN=/activitylog|preview|print|pdf|excel|export|search|filter|gopage|toggle|show|close|cancel|logout|changepassword|profile|avatar|chat|read/i;
const VIEWER_LOCK_FN=/^(open.*(modal|edit|delete)|delete|remove|edit|add|save|import|upload|create|insert|update)/i;
function applyViewerLockdown(){
  if(CURRENT_ROLE!=="viewer")return;
  document.querySelectorAll("button,a").forEach(el=>{
    if(el.dataset.viewerChecked)return;
    const oc=el.getAttribute("onclick")||"";
    const m=oc.match(/([A-Za-z0-9_]+)\s*\(/);
    const fn=m?m[1]:"";
    if(fn && VIEWER_LOCK_FN.test(fn) && !VIEWER_SAFE_FN.test(fn)){
      el.classList.add("viewer-locked");
      el.disabled=true;
      el.title="View-only account: hindi pwede mag-add/edit/delete.";
    }
    el.dataset.viewerChecked="1";
  });
  document.querySelectorAll(".edit-btn,.del-btn").forEach(el=>{
    el.classList.add("viewer-locked");
    el.disabled=true;
    el.title="View-only account: hindi pwede mag-add/edit/delete.";
  });
  document.querySelectorAll("input[type=file]").forEach(el=>{
    if(!el.dataset.viewerChecked){el.disabled=true;el.dataset.viewerChecked="1";}
  });
}

// ── FIX 1 + FIX 2 combined in init ──
// NOTE: deferred via setTimeout(init,0) instead of calling immediately,
// because several `let` vars used inside (currentPage, notifPermissionAsked,
// lotinvTotalPages, releasedTotalPages, slliSubdListLoaded, orSubdListLoaded)
// are declared FURTHER DOWN in this same file. Calling init() synchronously
// here hits them while still in the temporal dead zone -> ReferenceError.
// setTimeout pushes the call to after this whole script has finished
// executing top-to-bottom, so every later declaration is ready by then.
function init(){
  const u=sessionStorage.getItem("rpt_user");
  if(!u){window.location.href="index.html";return;}
  CURRENT_USER=u;
  if(typeof checkBirthdayPopup==="function")checkBirthdayPopup(u);
  if(typeof updateThemeToggleUI==="function")updateThemeToggleUI();
  document.getElementById("userNameEl").textContent=u;
  document.getElementById("avatarInitials").textContent=u.substring(0,2);
  const savedAccent=sessionStorage.getItem('rpt_accent');
  if(savedAccent){currentAccentColor=savedAccent;RPT_THEME.apply(savedAccent);}
  const savedAvatar=sessionStorage.getItem('rpt_avatar');
  if(savedAvatar){currentAvatarBase64=savedAvatar;applyAvatarToSidebar(savedAvatar);userAvatarCache[u]=savedAvatar;}
  if(sessionStorage.getItem("sidebar_pinned")==="1"){sidebarPinned=true;document.getElementById("sidebar").classList.add("pinned");document.getElementById("pinBtn").style.color="var(--accent)";}
  if(sessionStorage.getItem("rpt_is_admin")==="1"){document.getElementById("adminPanel").style.display="block";document.getElementById("adminNavItem").style.display="block";}

  CURRENT_ROLE=sessionStorage.getItem("rpt_role")||"staff";
  if(CURRENT_ROLE==="viewer"){
    document.body.classList.add("role-viewer");
    applyViewerLockdown();
    new MutationObserver(()=>applyViewerLockdown()).observe(document.body,{childList:true,subtree:true});
  }

  // ── FIX 1: Dashboard — gamitin palagi ang cache, background refresh lang ──
  const comingFromForm = sessionStorage.getItem("rpt_skip_reload") === "1";
  sessionStorage.removeItem("rpt_skip_reload");

  const cached = sessionStorage.getItem("rpt_cached_data");
  if (cached) {
    try {
      const data = JSON.parse(cached);
      window._cloudData = data;
      updateStats(data);
      currentPage = 1;
      applyFilterAndRender();
      // avatars sa background
      prefetchUserAvatars(data).then(() => applyFilterAndRender());
    } catch(e) {
      renderListFromCloud();
    }
  } else {
    renderListFromCloud();
  }

  // Kung hindi galing sa form, mag background refresh para updated
  if (!comingFromForm) {
    setTimeout(() => autoRefreshCloud(), 800);
  }

  // ── FIX 2: Chat — i-load agad sa init, hindi lang kapag may bagong message ──
  ensureNotifPermission();
  loadChats(true); // force first render
  setInterval(()=>{if(!document.hidden)loadChats();},3000);

  setTimeout(()=>{loadReleased();loadSLLI();loadSLRDI();},1500);
  setInterval(()=>{if(!document.hidden)autoRefreshCloud();},60000);
  loadProfileFromServer();
  document.addEventListener("visibilitychange",()=>{if(!document.hidden){loadChats(true);autoRefreshCloud();}});
  if(sessionStorage.getItem("rpt_is_admin")==="1")setTimeout(checkAutoBackup,4000);

  // ── Who's Online — heartbeat kada 30s, refresh ng count kada 30s ──
  if(typeof sendHeartbeat==="function"){
    sendHeartbeat();
    fetchOnlineUsers();
    setInterval(()=>{ if(!document.hidden){ sendHeartbeat(); fetchOnlineUsers(); } },30000);
  }

  // ── Direktang pumunta sa hiniling na view (e.g. galing form.html?view=computation) ──
  const requestedView=new URLSearchParams(window.location.search).get("view");
  if(requestedView && document.getElementById("view-"+requestedView)){
    setTimeout(()=>{ if(typeof showView==="function") showView(requestedView); },0);
    history.replaceState(null,"",window.location.pathname);
  }
}
setTimeout(init,0);











// ── Top progress bar (YouTube-style) for exports/long tasks ──
let _pbTimer=null,_pbVal=0;




// Generates skeleton <tr> rows for table loading states.




const alert=showAlertModal;





document.getElementById('settingsModal').addEventListener('click',function(e){if(e.target===this)closeSettings();});





const PAGE_SIZE=15;let currentPage=1,totalPages=1,filteredData=[];
















document.getElementById("deleteModal").addEventListener("click",function(e){if(e.target===this)closeDeleteModal();});



// ── Browser desktop notification + sound para sa bagong chat message ──
let notifPermissionAsked=false;




// ── FIX 2: Chat — ginawang forceRender=false default, pero first call ay true ──





// ===== @mention support =====


// Wraps @username tokens in a highlight span (only for known usernames) and flags if CURRENT_USER was mentioned

// Notify (toast) if the latest message mentions the current user and wasn't already notified

// Mention autocomplete dropdown while typing "@"



document.getElementById("chatInput").addEventListener("input",chatInputMentionCheck);
document.getElementById("chatInput").addEventListener("keydown",chatInputKeyNav);
loadChatUsersList();

document.getElementById("chatInput").addEventListener("keypress",e=>{if(e.key==="Enter"&&!document.getElementById("chatMentionList").classList.contains("show"))sendChat();});

// ===== Global keyboard shortcuts: "/" focus search, Esc close modal, Enter save =====
const KB_SEARCH_MAP={dashboard:"searchBar",liaison:"myliaison-search",released:"released-search",slli:"slli-search",slrdi:"slrdi-search",lotinv:"lotinv-search"};

document.addEventListener("keydown",function(e){
  const active=document.activeElement,tag=active?active.tagName:"",isTyping=tag==="INPUT"||tag==="TEXTAREA"||(active&&active.isContentEditable);

  if(e.key==="/"&&!isTyping){
    e.preventDefault();
    const id=KB_SEARCH_MAP[currentView]||"searchBar";
    const el=document.getElementById(id);
    if(el){el.focus();el.select();}
    return;
  }

  if(e.key==="Escape"){
    if(kbIsOpen("genConfirmModal","show")){document.getElementById("genConfirmCancelBtn").click();return;}
    if(kbIsOpen("genAlertModal","show")){document.getElementById("genAlertOkBtn").click();return;}
    if(kbIsOpen("liaisonModalOverlay","show")){closeLiaisonModal();return;}
    if(kbIsOpen("allAttachmentsModal","show")){closeAllAttachmentsModal();return;}
    if(kbIsOpen("liaisonActivityModal","show")){closeLiaisonActivityLog();return;}
    if(kbIsOpen("lotinvEditModal","show")){closeLotinvEditModal();return;}
    if(kbIsOpen("lotinvDeleteModal","show")){closeLotinvDeleteModal();return;}
    if(kbIsOpen("deleteModal","show")){closeDeleteModal();return;}
    if(kbIsOpen("settingsModal","show")){closeSettings();return;}
    if(kbIsOpen("avatarPreviewModal","show")){closeAvatarPreview();return;}
    if(kbIsOpen("asmcRowModalOverlay","show")){asmcCloseRowModal();return;}
    if(kbIsOpen("orModalOverlay","show")){closeOrIssuanceModal();return;}
    if(kbIsOpen("releasedModalOverlay","show")){closeReleasedModal();return;}
    if(kbIsOpen("slliModalOverlay","show")){closeSlliModal();return;}
    if(kbIsOpen("slrdiModalOverlay","show")){closeSlrdiModal();return;}
    if(typeof chatOpen!=="undefined"&&chatOpen){toggleChat();return;}
  }

  if(e.key==="Enter"&&!e.shiftKey&&tag!=="TEXTAREA"){
    if(kbIsOpen("liaisonModalOverlay","show")){e.preventDefault();saveLiaisonRecordUI();return;}
    if(kbIsOpen("lotinvEditModal","show")){e.preventDefault();saveLotinvEdit();return;}
    if(kbIsOpen("asmcRowModalOverlay","show")){e.preventDefault();asmcSaveRowModal();return;}
    if(kbIsOpen("orModalOverlay","show")){e.preventDefault();saveOrIssuanceRecordUI();return;}
    if(kbIsOpen("releasedModalOverlay","show")){e.preventDefault();saveReleasedRecordUI();return;}
    if(kbIsOpen("slliModalOverlay","show")){e.preventDefault();saveSlliRecordUI();return;}
    if(kbIsOpen("slrdiModalOverlay","show")){e.preventDefault();saveSlrdiRecordUI();return;}
  }
});


// ===== My Liaison Requests (local DB) =====
let myLiaisonData=[];
const LM_FIELDS=["liaisonName","dateRequested","raNo","buyer","subd","ph","blk","lot","description","tct","pinNo","tdNo","yrCovered","amount","owner","remarks","orNo","orYrCovered","orAmount","orDate","dateReceived","statusRemarks"];








let raLookupTimer;
let lmDuplicateRows=[];
let lmExtraAttachments=[];















let allAttachmentsData=[];



// ===== Liaison Activity Log (audit trail) =====


// ===== Released Title Activity Log (reuses the same audit-trail modal) =====

// ===== SLLI Activity Log (reuses the same audit-trail modal) =====

// ===== SLRDI Activity Log (reuses the same audit-trail modal) =====

// ===== Computation Record Activity Log (reuses the same audit-trail modal) =====








const CSV_TO_BODY_KEY={liaison_name:"liaisonName",date_requested:"dateRequested",ra_no:"raNo",buyer:"buyer",subd:"subd",ph:"ph",blk:"blk",lot:"lot",description:"description",tct:"tct",pin_no:"pinNo",td_no:"tdNo",yr_covered:"yrCovered",amount:"amount",owner:"owner",remarks:"remarks",or_no:"orNo",or_yr_covered:"orYrCovered",or_amount:"orAmount",or_date:"orDate",date_received:"dateReceived",status_remarks:"statusRemarks"};

const MYLIAISON_PAGE_SIZE=15;let myLiaisonCurrentPage=1,myLiaisonTotalPages=1,myLiaisonFiltered=[];

let myLiaisonSearchTimer;






// ===== Lot Inventory (master list) =====
const LOTINV_PAGE_SIZE=20;
let lotinvTimer,lotinvCurrentPage=1,lotinvTotalPages=1;
let lotinvPendingDeleteId=null,lotinvEditId=null;
let lotinvRowsCache={};







// --- Edit ---




// --- Delete ---





const RELEASED_PAGE_SIZE=100;
let releasedTimer,releasedCurrentPage=1,releasedTotalPages=1,releasedRowsCache={};




let releasedSubdListLoaded=false;




let releasedRaLookupTimer;


let releasedLookupTimer;






let slliData=[],slliFiltered=[],slliSubdListLoaded=false;
let orData=[],orFiltered=[],orSubdListLoaded=false;
let orTimer,orCurrentPage=1,orTotalPages=1;const OR_PAGE_SIZE=100;
let orLookupTimer;
let orRaLookupTimer;


let slliTimer,slliCurrentPage=1,slliTotalPages=1;const SLLI_PAGE_SIZE=100;


// Parses flexible date strings (mm/dd/yy, mm/dd/yyyy, yyyy-mm-dd, m/d/yy, etc.) into a comparable Date (or null if unparseable)

// Best-effort: converts old free-text date values (e.g. "OCT. 28-31", "10/28/25") into yyyy-mm-dd
// for use in <input type="date">. Returns "" if it can't be confidently parsed (old text stays as-is in the DB).





let slliRaLookupTimer;



let slliLookupTimer;






let slrdiData=[],slrdiFiltered=[],slrdiSubdListLoaded=false;


let slrdiTimer,slrdiCurrentPage=1,slrdiTotalPages=1;const SLRDI_PAGE_SIZE=100;






let slrdiRaLookupTimer;



let slrdiLookupTimer;