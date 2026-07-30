// ============================================
// released.js — extracted from home.html
// ============================================

async function openReleasedActivityLog(releasedId,label){
  document.getElementById("activityLogRecordLabel").textContent=label?`— ${label}`:"";
  const list=document.getElementById("activityLogList");
  list.innerHTML=`<div class="empty-state">Loading...</div>`;
  document.getElementById("liaisonActivityModal").classList.add("show");
  try{
    const res=await safeFetch(CLOUD_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'getReleasedActivityLog',releasedId})});
    if(res.error){list.innerHTML=`<div class="empty-state" style="color:#f87171">${res.error}</div>`;return;}
    renderActivityLog(res.log||[],res.labels||{});
  }catch(e){list.innerHTML=`<div class="empty-state" style="color:#f87171">Error loading activity log.</div>`;}
}

function releasedSearch(){clearTimeout(releasedTimer);releasedTimer=setTimeout(()=>releasedGoPage(1),300);}

function releasedGoPage(p){if(p<1)p=1;if(p>releasedTotalPages)p=releasedTotalPages;releasedCurrentPage=p;_doReleasedSearch();}

async function loadReleased(){sectionState.released.loaded=true;releasedGoPage(1);}

async function _doReleasedSearch(){
  const q=document.getElementById("released-search").value.trim();
  const dateFromStr=document.getElementById("released-date-from").value;
  const dateToStr=document.getElementById("released-date-to").value;
  const hasDateFilter=!!(dateFromStr||dateToStr);
  const dateFrom=dateFromStr?new Date(dateFromStr+"T00:00:00"):null;
  const dateTo=dateToStr?new Date(dateToStr+"T23:59:59"):null;
  const tbody=document.getElementById("released-body");
  const bar=document.getElementById("releasedPaginationBar");
  tbody.innerHTML=skeletonRows(12,6);
  document.getElementById("released-debug").textContent="⚡ Loading...";
  try{
    // Kapag may active date filter, kunin muna LAHAT ng tugma sa search (server-side),
    // tapos i-filter at i-paginate na sa client gamit ang best-effort date parsing
    // (dahil free-text pa rin ang Date Released ng mga lumang records).
    const fetchPage=hasDateFilter?1:releasedCurrentPage;
    const fetchPageSize=hasDateFilter?5000:RELEASED_PAGE_SIZE;
    const data=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"searchReleasedTitles",query:q,page:fetchPage,pageSize:fetchPageSize})}).then(r=>r.json());
    if(data.error){tbody.innerHTML=`<tr><td colspan="12" class="empty-state" style="color:#f87171">${data.error}</td></tr>`;document.getElementById("released-debug").textContent="Error.";bar.style.display="none";return;}

    let rows,total;
    if(hasDateFilter){
      const allRows=(data.rows||[]).filter(r=>{
        const rd=parseFlexDate(r.date_released);
        if(!rd)return false; // hindi ma-parse na petsa (hal. "OCT. 28-31") -> hindi kasama sa filtered result
        if(dateFrom && rd<dateFrom)return false;
        if(dateTo && rd>dateTo)return false;
        return true;
      });
      total=allRows.length;
      releasedTotalPages=Math.max(1,Math.ceil(total/RELEASED_PAGE_SIZE));
      if(releasedCurrentPage>releasedTotalPages)releasedCurrentPage=releasedTotalPages;
      const startIdx=(releasedCurrentPage-1)*RELEASED_PAGE_SIZE;
      rows=allRows.slice(startIdx,startIdx+RELEASED_PAGE_SIZE);
    } else {
      rows=data.rows||[];
      total=data.total||0;
      releasedTotalPages=data.totalPages||1;
      releasedCurrentPage=data.page||1;
    }

    document.getElementById("released-count").textContent=total;
    releasedRowsCache={};
    rows.forEach(r=>{releasedRowsCache[r.id]=r;});
    tbody.innerHTML=rows.length?rows.map(r=>`<tr>
      <td>${r.date_released||"---"}</td><td>${r.year||"---"}</td><td>${r.ra_no||"---"}</td><td>${r.subd||"---"}</td><td>${r.ph||"---"}</td><td>${r.blk||"---"}</td><td>${r.lot||"---"}</td>
      <td>${r.buyer||"---"}</td><td>${r.transferred_title||"---"}</td><td>${r.original_title||"---"}</td><td>${r.owner||"---"}</td>
      <td style="text-align:center;white-space:nowrap"><button class="btn btn-ghost" style="padding:4px 8px;font-size:11px" onclick="openReleasedModal(${r.id})">Edit</button>
      <button class="btn btn-ghost" style="padding:4px 8px;font-size:11px;color:#f87171" onclick="deleteReleasedRecordUI(${r.id})">Del</button>
      <button class="btn btn-ghost" style="padding:4px 8px;font-size:11px" onclick="openReleasedActivityLog(${r.id},'${(r.ra_no||r.buyer||'').replace(/'/g,"\\'")}')" title="View activity log">🕒</button></td>
    </tr>`).join(""):`<tr><td colspan="12" class="empty-state">${(q||hasDateFilter)?"No matches found.":"No records yet."}</td></tr>`;
    document.getElementById("released-debug").textContent=rows.length?`Showing page ${releasedCurrentPage} of ${releasedTotalPages} (${total} total record${total===1?"":"s"}).`:"No results.";
    if(!rows.length && releasedCurrentPage===1){bar.style.display="none";return;}
    bar.style.display="flex";
    const from=(releasedCurrentPage-1)*RELEASED_PAGE_SIZE+1,to=Math.min(releasedCurrentPage*RELEASED_PAGE_SIZE,total);
    document.getElementById("releasedPageInfo").textContent=`Showing ${from}–${to} of ${total} records`;
    document.getElementById("releasedBtnFirst").disabled=releasedCurrentPage===1;
    document.getElementById("releasedBtnPrev").disabled=releasedCurrentPage===1;
    document.getElementById("releasedBtnNext").disabled=releasedCurrentPage===releasedTotalPages;
    document.getElementById("releasedBtnLast").disabled=releasedCurrentPage===releasedTotalPages;
    const nums=document.getElementById("releasedPageNumbers");nums.innerHTML="";
    let startP=Math.max(1,releasedCurrentPage-2),endP=Math.min(releasedTotalPages,startP+4);
    startP=Math.max(1,endP-4);
    for(let i=startP;i<=endP;i++){const b=document.createElement("button");b.className="page-num-btn"+(i===releasedCurrentPage?" active":"");b.textContent=i;b.onclick=()=>releasedGoPage(i);nums.appendChild(b);}
  }catch(e){
    tbody.innerHTML='<tr><td colspan="12" class="empty-state" style="color:#f87171">Error loading.</td></tr>';
    document.getElementById("released-debug").textContent="Error.";
    bar.style.display="none";
  }
}

async function loadReleasedSubdOptions(){
  if(releasedSubdListLoaded)return;
  try{
    const res=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"getLotInventorySubdivisions"})}).then(r=>r.json());
    const subs=res.subdivisions||[];
    releasedSubdListLoaded=true;
    const datalist=document.getElementById("rlm-subd-list");
    if(datalist)datalist.innerHTML=subs.map(s=>`<option value="${s}">`).join("");
  }catch(e){}
}

function openReleasedModal(id){
  clearReleasedModalFields();
  loadReleasedSubdOptions();
  document.getElementById("releasedModalTitle").textContent=id?"Edit Released Title Record":"Add Released Title Record";
  if(id && releasedRowsCache[id]){
    const r=releasedRowsCache[id];
    document.getElementById("rlm-id").value=r.id;
    document.getElementById("rlm-dateReleased").value=toISODateInput(r.date_released);
    document.getElementById("rlm-year").value=r.year||"";
    document.getElementById("rlm-raNo").value=r.ra_no||"";
    document.getElementById("rlm-subd").value=r.subd||"";
    document.getElementById("rlm-ph").value=r.ph||"";
    document.getElementById("rlm-blk").value=r.blk||"";
    document.getElementById("rlm-lot").value=r.lot||"";
    document.getElementById("rlm-buyer").value=r.buyer||"";
    document.getElementById("rlm-owner").value=r.owner||"";
    document.getElementById("rlm-transferredTitle").value=r.transferred_title||"";
    document.getElementById("rlm-originalTitle").value=r.original_title||"";
  }
  document.getElementById("releasedModalOverlay").classList.add("show");
}

function closeReleasedModal(){document.getElementById("releasedModalOverlay").classList.remove("show");}

function clearReleasedModalFields(){
  ["id","dateReleased","year","raNo","subd","ph","blk","lot","buyer","owner","transferredTitle","originalTitle"].forEach(f=>{
    const el=document.getElementById("rlm-"+f);if(el)el.value="";
  });
  document.getElementById("rlm-lookup-status").textContent="";
}

function lookupReleasedRA(){clearTimeout(releasedRaLookupTimer);releasedRaLookupTimer=setTimeout(_doReleasedRALookup,300);}

async function _doReleasedRALookup(){
  const statusEl=document.getElementById("rlm-lookup-status");
  const q=document.getElementById("rlm-raNo").value.trim();
  if(!q){statusEl.textContent="";return;}
  statusEl.textContent="Looking up RA#...";statusEl.style.color="var(--muted)";
  try{
    const res=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"getLotInventoryByRA",raNo:q})}).then(r=>r.json());
    if(res && !res.notFound && !res.error){
      document.getElementById("rlm-subd").value=res.sub||"";
      document.getElementById("rlm-ph").value=res.ph||"";
      document.getElementById("rlm-blk").value=res.blk||"";
      document.getElementById("rlm-lot").value=res.lot||"";
      document.getElementById("rlm-buyer").value=res.buyers_name||"";
      document.getElementById("rlm-owner").value=res.lot_owner||"";
      document.getElementById("rlm-originalTitle").value=res.tct_no||"";
      if(res.transferred_tct)document.getElementById("rlm-transferredTitle").value=res.transferred_tct;
      statusEl.textContent="✓ Match found sa Lot Inventory — Subd/Ph/Blk/Lot/Buyer/Owner/Original Title (TCT) auto-filled.";
      statusEl.style.color="#22c55e";
    }else{
      statusEl.textContent="No match found for this RA# — fill in manually.";
      statusEl.style.color="var(--muted)";
    }
  }catch(e){
    statusEl.textContent="No match found for this RA# — fill in manually.";
    statusEl.style.color="var(--muted)";
  }
}

function lookupReleasedLot(){clearTimeout(releasedLookupTimer);releasedLookupTimer=setTimeout(_doReleasedLookup,300);}

async function _doReleasedLookup(){
  const subd=document.getElementById("rlm-subd").value.trim();
  const ph=document.getElementById("rlm-ph").value.trim();
  const blk=document.getElementById("rlm-blk").value.trim();
  const lot=document.getElementById("rlm-lot").value.trim();
  const statusEl=document.getElementById("rlm-lookup-status");
  if(!subd && !ph && !blk && !lot){statusEl.textContent="";return;}
  statusEl.textContent="Looking up Lot Inventory...";statusEl.style.color="var(--muted)";
  try{
    const res=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"lookupLotInventoryByLot",subd,ph,blk,lot})}).then(r=>r.json());
    if(res && !res.notFound && !res.error){
      document.getElementById("rlm-buyer").value=res.buyers_name||"";
      document.getElementById("rlm-owner").value=res.lot_owner||"";
      document.getElementById("rlm-originalTitle").value=res.tct_no||"";
      if(res.transferred_tct)document.getElementById("rlm-transferredTitle").value=res.transferred_tct;
      statusEl.textContent="✓ Match found sa Lot Inventory — Buyer/Owner/Original Title (TCT) auto-filled.";
      statusEl.style.color="#22c55e";
    }else{
      statusEl.textContent="No match found sa Lot Inventory — fill in manually.";
      statusEl.style.color="var(--muted)";
    }
  }catch(e){
    statusEl.textContent="No match found sa Lot Inventory — fill in manually.";
    statusEl.style.color="var(--muted)";
  }
}

async function saveReleasedRecordUI(){
  const body={
    action:"saveReleasedRecord",
    id:document.getElementById("rlm-id").value||0,
    dateReleased:document.getElementById("rlm-dateReleased").value,
    year:document.getElementById("rlm-year").value,
    raNo:document.getElementById("rlm-raNo").value,
    subd:document.getElementById("rlm-subd").value,
    ph:document.getElementById("rlm-ph").value,
    blk:document.getElementById("rlm-blk").value,
    lot:document.getElementById("rlm-lot").value,
    buyer:document.getElementById("rlm-buyer").value,
    owner:document.getElementById("rlm-owner").value,
    transferredTitle:document.getElementById("rlm-transferredTitle").value,
    originalTitle:document.getElementById("rlm-originalTitle").value,
    createdBy:CURRENT_USER
  };
  try{
    const res=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(body)}).then(r=>r.json());
    if(res.error){showAlertModal(res.error);return;}
    closeReleasedModal();
    releasedGoPage(releasedCurrentPage);
  }catch(e){showAlertModal("Error saving record.");}
}

async function deleteReleasedRecordUI(id){
  if(!await showConfirm("Are you sure you want to delete this record?",{title:"Delete Record",okLabel:"Delete",danger:true}))return;
  try{
    const res=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"deleteReleasedRecord",id,changedBy:CURRENT_USER})}).then(r=>r.json());
    if(res.error){showAlertModal(res.error);return;}
    releasedGoPage(releasedCurrentPage);
  }catch(e){showAlertModal("Error deleting record.");}
}

function releasedPDF(){const{jsPDF}=window.jspdf;if(!jsPDF)return;const doc=new jsPDF("l","mm","a4"),q=document.getElementById("released-search").value;const df=document.getElementById("released-date-from").value,dt=document.getElementById("released-date-to").value;const dateRangeTxt=(df||dt)?` | Date Released: ${df||"..."} to ${dt||"..."}`:"";doc.setFontSize(11);doc.setTextColor(40,40,40);doc.text("Sta. Lucia Realty & Development, Inc. / Sta. Lucia Land, Inc.",14,10);doc.setFontSize(18);doc.setTextColor(26,115,232);doc.text("Released Title Report",14,19);doc.setFontSize(10);doc.setTextColor(100,100,100);doc.text(`Keyword: ${q||"All"}${dateRangeTxt} | Records: ${document.getElementById("released-count").textContent} | Page: ${releasedCurrentPage} of ${releasedTotalPages} | Generated: ${new Date().toLocaleString()}`,14,26);doc.autoTable({html:"#releasedTable",startY:33,theme:"grid",headStyles:{fillColor:[26,115,232]},styles:{fontSize:7}});doc.save(`Released_${q||"Report"}.pdf`);}

async function releasedExcel(){
  const q=document.getElementById("released-search").value.trim();
  const dateFromStr=document.getElementById("released-date-from").value;
  const dateToStr=document.getElementById("released-date-to").value;
  const dateFrom=dateFromStr?new Date(dateFromStr+"T00:00:00"):null;
  const dateTo=dateToStr?new Date(dateToStr+"T23:59:59"):null;
  const dateRangeTxt=(dateFrom||dateTo)?` | Date Released: ${dateFromStr||"..."} to ${dateToStr||"..."}`:"";
  document.getElementById("released-debug").textContent="⚡ Preparing Excel export...";
  try{
    const data=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"searchReleasedTitles",query:q,page:1,pageSize:5000})}).then(r=>r.json());
    let sourceRows=data.rows||[];
    if(dateFrom||dateTo){
      sourceRows=sourceRows.filter(r=>{
        const rd=parseFlexDate(r.date_released);
        if(!rd)return false;
        if(dateFrom && rd<dateFrom)return false;
        if(dateTo && rd>dateTo)return false;
        return true;
      });
    }
    const columns=[
      {header:"Date Released",key:"date_released",width:16},
      {header:"Year",key:"year",width:8},
      {header:"RA#",key:"ra_no",width:20},
      {header:"Subd.",key:"subd",width:12},
      {header:"Ph",key:"ph",width:8},
      {header:"Blk.",key:"blk",width:8},
      {header:"Lot",key:"lot",width:8},
      {header:"Buyer's Name",key:"buyer",width:26},
      {header:"Transferred Title",key:"transferred_title",width:20},
      {header:"Original Title",key:"original_title",width:20},
      {header:"Owner",key:"owner",width:14}
    ];
    const rowsData=sourceRows.map(r=>({
      date_released:r.date_released||"",year:r.year||"",ra_no:r.ra_no||"",subd:r.subd||"",ph:r.ph||"",
      blk:r.blk||"",lot:r.lot||"",buyer:r.buyer||"",transferred_title:r.transferred_title||"",
      original_title:r.original_title||"",owner:r.owner||""
    }));
    if(!rowsData.length){showAlertModal("No results to export.");document.getElementById("released-debug").textContent="No results.";return;}
    const subtitle=`Keyword: ${q||"All"}${dateRangeTxt} | Records: ${rowsData.length}${data.total>5000?" (of "+data.total+" — export capped at 5000, narrow your search for more)":""}`;
    const wb=await buildLogoWorkbook("Released Title Report",columns,rowsData,{sheetName:"Released Title",subtitle});
    const stamp=new Date().toISOString().slice(0,10);
    const buf=await wb.xlsx.writeBuffer();
    saveAs(new Blob([buf],{type:"application/octet-stream"}),`Released_${q||"Report"}_${stamp}.xlsx`);
    document.getElementById("released-debug").textContent=`Exported ${rowsData.length} record(s).`;
  }catch(e){showAlertModal("Error exporting.");document.getElementById("released-debug").textContent="Error.";}
}

