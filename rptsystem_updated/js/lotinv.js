// ============================================
// lotinv.js — extracted from home.html
// ============================================

function lotinvSearch(){clearTimeout(lotinvTimer);lotinvTimer=setTimeout(()=>lotinvGoPage(1),300);}

function lotinvGoPage(p){
  if(p<1)return;
  if(p>lotinvTotalPages)p=lotinvTotalPages;
  lotinvCurrentPage=p;
  _doLotinvSearch();
}

async function _doLotinvSearch(){
  const q=document.getElementById("lotinv-search").value.trim();
  const tbody=document.getElementById("lotinv-body");
  const bar=document.getElementById("lotinvPaginationBar");
  tbody.innerHTML=skeletonRows(12,6);
  document.getElementById("lotinv-debug").textContent="⚡ Loading...";
  try{
    const data=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"searchLotInventory",query:q,page:lotinvCurrentPage,pageSize:LOTINV_PAGE_SIZE})}).then(r=>r.json());
    if(data.error){tbody.innerHTML=`<tr><td colspan="12" class="empty-state" style="color:#f87171">${data.error}</td></tr>`;document.getElementById("lotinv-debug").textContent="Error.";bar.style.display="none";return;}
    const rows=data.rows||[];
    lotinvTotalPages=data.totalPages||1;
    lotinvCurrentPage=data.page||1;
    document.getElementById("lotinv-count").textContent=data.total||0;
    lotinvRowsCache={};
    rows.forEach(r=>{lotinvRowsCache[r.id]=r;});

    tbody.innerHTML=rows.length?rows.map(r=>`<tr>
      <td>${r.class||"---"}</td><td>${r.sub||"---"}</td><td>${r.ph||"---"}</td><td>${r.blk||"---"}</td><td>${r.lot||"---"}</td>
      <td>${r.ra_number||"---"}</td><td>${r.lot_area||"---"}</td><td>${r.buyers_name||"---"}</td><td>${r.lot_owner||"---"}</td><td>${r.tct_no||"---"}</td><td>${r.td_no_latest||r.td_no_old||"---"}</td>
      <td style="text-align:center"><div class="action-cell"><button class="edit-btn" data-id="${r.id}" onclick="openLotinvEditModal(${r.id})"><svg width="12" height="12" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit</button><button class="del-btn" data-id="${r.id}" onclick="openLotinvDeleteModal(${r.id},'${(r.ra_number||"").replace(/'/g,"\\'")}')"><svg width="12" height="12" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>Delete</button></div></td>
    </tr>`).join(""):'<tr><td colspan="12" class="empty-state">No results.</td></tr>';

    document.getElementById("lotinv-debug").textContent=rows.length?`Showing page ${lotinvCurrentPage} of ${lotinvTotalPages} (${data.total} total record${data.total===1?"":"s"}).`:"No results.";

    if(!rows.length && lotinvCurrentPage===1){bar.style.display="none";return;}
    bar.style.display="flex";
    const from=(lotinvCurrentPage-1)*LOTINV_PAGE_SIZE+1,to=Math.min(lotinvCurrentPage*LOTINV_PAGE_SIZE,data.total||0);
    document.getElementById("lotinvPageInfo").textContent=`Showing ${from}–${to} of ${data.total||0} records`;
    document.getElementById("lotinvBtnFirst").disabled=lotinvCurrentPage===1;
    document.getElementById("lotinvBtnPrev").disabled=lotinvCurrentPage===1;
    document.getElementById("lotinvBtnNext").disabled=lotinvCurrentPage===lotinvTotalPages;
    document.getElementById("lotinvBtnLast").disabled=lotinvCurrentPage===lotinvTotalPages;
    const nums=document.getElementById("lotinvPageNumbers");nums.innerHTML="";
    let startP=Math.max(1,lotinvCurrentPage-2),endP=Math.min(lotinvTotalPages,startP+4);
    if(endP-startP<4)startP=Math.max(1,endP-4);
    for(let i=startP;i<=endP;i++){const b=document.createElement("button");b.className="page-num-btn"+(i===lotinvCurrentPage?" active":"");b.textContent=i;b.onclick=()=>lotinvGoPage(i);nums.appendChild(b);}
  }catch(e){
    tbody.innerHTML='<tr><td colspan="12" class="empty-state" style="color:#f87171">Error loading.</td></tr>';
    document.getElementById("lotinv-debug").textContent="Error.";
    bar.style.display="none";
  }
}

function openLotinvEditModal(id){
  // Pull values from the cached row data (fetched with the last search) so fields not shown
  // in the table itself (like the two TD columns) are still available for editing.
  const r=lotinvRowsCache[id];
  if(!r){document.getElementById("lotinv-debug").textContent="Record not found in current view.";return;}
  lotinvEditId=id;
  document.getElementById("lei-class").value=r.class||"";
  document.getElementById("lei-sub").value=r.sub||"";
  document.getElementById("lei-ph").value=r.ph||"";
  document.getElementById("lei-blk").value=r.blk||"";
  document.getElementById("lei-lot").value=r.lot||"";
  document.getElementById("lei-ra").value=r.ra_number||"";
  document.getElementById("lei-area").value=r.lot_area||"";
  document.getElementById("lei-buyer").value=r.buyers_name||"";
  document.getElementById("lei-owner").value=r.lot_owner||"";
  document.getElementById("lei-tct").value=r.tct_no||"";
  document.getElementById("lei-tdlatest").value=r.td_no_latest||"";
  document.getElementById("lei-tdold").value=r.td_no_old||"";
  document.getElementById("lotinv-debug").textContent="System ready.";
  document.getElementById("lotinvEditModal").classList.add("show");
}

function closeLotinvEditModal(){document.getElementById("lotinvEditModal").classList.remove("show");lotinvEditId=null;}

async function saveLotinvEdit(){
  if(!lotinvEditId)return;
  const payload={
    action:"updateLotInventory",
    id:lotinvEditId,
    class:document.getElementById("lei-class").value.trim(),
    sub:document.getElementById("lei-sub").value.trim(),
    ph:document.getElementById("lei-ph").value.trim(),
    blk:document.getElementById("lei-blk").value.trim(),
    lot:document.getElementById("lei-lot").value.trim(),
    ra_number:document.getElementById("lei-ra").value.trim(),
    lot_area:document.getElementById("lei-area").value.trim(),
    buyers_name:document.getElementById("lei-buyer").value.trim(),
    lot_owner:document.getElementById("lei-owner").value.trim(),
    tct_no:document.getElementById("lei-tct").value.trim(),
    td_no_latest:document.getElementById("lei-tdlatest").value.trim(),
    td_no_old:document.getElementById("lei-tdold").value.trim()
  };
  try{
    const result=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(payload)}).then(r=>r.json());
    if(result.error){alert("Update failed: "+result.error);return;}
    closeLotinvEditModal();
    showToast("Record updated.");
    _doLotinvSearch();
  }catch(e){alert("Error: "+e.message);}
}

function openLotinvDeleteModal(id,label){
  lotinvPendingDeleteId=id;
  document.getElementById("lotinvDeleteName").textContent=label||("ID "+id);
  document.getElementById("lotinvDeleteModal").classList.add("show");
}

function closeLotinvDeleteModal(){document.getElementById("lotinvDeleteModal").classList.remove("show");lotinvPendingDeleteId=null;}

async function confirmLotinvDelete(){
  const id=lotinvPendingDeleteId;
  if(!id)return;
  closeLotinvDeleteModal();
  try{
    const result=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"deleteLotInventory",id})}).then(r=>r.json());
    if(result.error){alert("Delete failed: "+result.error);return;}
    showToast("Record deleted.");
    _doLotinvSearch();
  }catch(e){alert("Error: "+e.message);}
}

