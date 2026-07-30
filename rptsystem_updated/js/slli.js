// ============================================
// slli.js — extracted from home.html
// ============================================

async function openSlliActivityLog(slliId,label){
  document.getElementById("activityLogRecordLabel").textContent=label?`— ${label}`:"";
  const list=document.getElementById("activityLogList");
  list.innerHTML=`<div class="empty-state">Loading...</div>`;
  document.getElementById("liaisonActivityModal").classList.add("show");
  try{
    const res=await safeFetch(CLOUD_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'getSlliActivityLog',slliId})});
    if(res.error){list.innerHTML=`<div class="empty-state" style="color:#f87171">${res.error}</div>`;return;}
    renderActivityLog(res.log||[],res.labels||{});
  }catch(e){list.innerHTML=`<div class="empty-state" style="color:#f87171">Error loading activity log.</div>`;}
}

async function loadSLLI(){
  const st=sectionState.slli;
  document.getElementById("slli-debug").textContent="Loading...";
  document.getElementById("slli-body").innerHTML=skeletonRows(12,8);
  if(!slliSubdListLoaded)loadSlliSubdOptions();
  try{
    const res=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"getSlliRecords"})}).then(r=>r.json());
    slliData=res.records||[];
    slliData.forEach(r=>{r._searchKey=`${r.ra_no||""} ${r.subd||""} ${r.ph||""} ${r.blk||""} ${r.lot||""} ${r.buyer||""} ${r.tra_no||""} ${r.description||""} ${r.remarks||""}`.toUpperCase();});
    st.loaded=true;
    document.getElementById("slli-debug").textContent="Loaded: "+slliData.length+" rows.";
    slliSearch();
  }catch(e){
    document.getElementById("slli-debug").textContent="Error.";
    document.getElementById("slli-body").innerHTML='<tr><td colspan="12" class="empty-state" style="color:#f87171">Error loading.</td></tr>';
  }
}

async function loadSlliSubdOptions(){
  try{
    const res=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"getLotInventorySubdivisions"})}).then(r=>r.json());
    const subs=res.subdivisions||[];
    slliSubdListLoaded=true;
    const filterSel=document.getElementById("slli-subd-filter");
    filterSel.innerHTML='<option value="">All Subd.</option>'+subs.map(s=>`<option value="${s}">${s}</option>`).join("");
    const datalist=document.getElementById("slm-subd-list");
    if(datalist)datalist.innerHTML=subs.map(s=>`<option value="${s}">`).join("");
  }catch(e){}
}

function slliSearch(){clearTimeout(slliTimer);slliTimer=setTimeout(()=>slliGoPage(1),200);}

function slliGoPage(p){if(p<1)p=1;if(p>slliTotalPages)p=slliTotalPages;slliCurrentPage=p;_slliRender();}

function _slliRender(){
  const query=(document.getElementById("slli-search").value||"").toUpperCase().trim();
  const subdFilter=document.getElementById("slli-subd-filter").value;
  const tbody=document.getElementById("slli-body");
  const bar=document.getElementById("slliPaginationBar");
  slliFiltered=slliData.filter(r=>{
    if(subdFilter && (r.subd||"")!==subdFilter)return false;
    if(query && !(r._searchKey||"").includes(query))return false;
    return true;
  });
  document.getElementById("slli-count").textContent=slliFiltered.length;
  if(!slliFiltered.length){
    tbody.innerHTML=`<tr><td colspan="12" class="empty-state">${slliData.length?"No matches found.":'No records yet. Click "+ Add Record".'}</td></tr>`;
    bar.style.display="none";
    return;
  }
  slliTotalPages=Math.max(1,Math.ceil(slliFiltered.length/SLLI_PAGE_SIZE));
  if(slliCurrentPage>slliTotalPages)slliCurrentPage=slliTotalPages;
  const startIdx=(slliCurrentPage-1)*SLLI_PAGE_SIZE;
  const shown=slliFiltered.slice(startIdx,startIdx+SLLI_PAGE_SIZE);
  tbody.innerHTML=shown.map(r=>`<tr>
    <td>${r.ra_no||"---"}</td><td>${r.subd||"---"}</td><td>${r.ph||"---"}</td><td>${r.blk||"---"}</td><td>${r.lot||"---"}</td>
    <td>${r.description||"---"}</td><td>${r.buyer||"---"}</td><td>${r.tra_no||"---"}</td><td>${r.remarks||"---"}</td><td>${r.date_received||"---"}</td><td>${r.turnover_mars||"---"}</td>
    <td style="text-align:center;white-space:nowrap"><button class="btn btn-ghost" style="padding:4px 8px;font-size:11px" onclick='openSlliModal(${JSON.stringify(r).replace(/'/g,"&apos;")})'>Edit</button>
    <button class="btn btn-ghost" style="padding:4px 8px;font-size:11px;color:#f87171" onclick="deleteSlliRecordUI(${r.id})">Del</button>
    <button class="btn btn-ghost" style="padding:4px 8px;font-size:11px" onclick="openSlliActivityLog(${r.id},'${(r.ra_no||r.buyer||'').replace(/'/g,"\\'")}')" title="View activity log">🕒</button></td>
  </tr>`).join("");
  bar.style.display="flex";
  const from=startIdx+1,to=Math.min(startIdx+SLLI_PAGE_SIZE,slliFiltered.length);
  document.getElementById("slliPageInfo").textContent=`Showing ${from}–${to} of ${slliFiltered.length} records`;
  document.getElementById("slliBtnFirst").disabled=slliCurrentPage===1;
  document.getElementById("slliBtnPrev").disabled=slliCurrentPage===1;
  document.getElementById("slliBtnNext").disabled=slliCurrentPage===slliTotalPages;
  document.getElementById("slliBtnLast").disabled=slliCurrentPage===slliTotalPages;
  const nums=document.getElementById("slliPageNumbers");nums.innerHTML="";
  let startP=Math.max(1,slliCurrentPage-2),endP=Math.min(slliTotalPages,startP+4);
  startP=Math.max(1,endP-4);
  for(let i=startP;i<=endP;i++){const b=document.createElement("button");b.className="page-num-btn"+(i===slliCurrentPage?" active":"");b.textContent=i;b.onclick=()=>slliGoPage(i);nums.appendChild(b);}
}

function openSlliModal(record){
  document.getElementById("slliModalTitle").textContent=record?"Edit SLLI Record":"Add SLLI Record";
  clearSlliModalFields();
  if(record){
    document.getElementById("slm-id").value=record.id;
    document.getElementById("slm-raNo").value=record.ra_no||"";
    document.getElementById("slm-subd").value=record.subd||"";
    document.getElementById("slm-ph").value=record.ph||"";
    document.getElementById("slm-blk").value=record.blk||"";
    document.getElementById("slm-lot").value=record.lot||"";
    document.getElementById("slm-buyer").value=record.buyer||"";
    document.getElementById("slm-description").value=record.description||"";
    document.getElementById("slm-traNo").value=record.tra_no||"";
    document.getElementById("slm-dateReceived").value=record.date_received||"";
    document.getElementById("slm-turnoverMars").value=record.turnover_mars||"";
    document.getElementById("slm-remarks").value=record.remarks||"";
  }
  document.getElementById("slliModalOverlay").classList.add("show");
}

function closeSlliModal(){document.getElementById("slliModalOverlay").classList.remove("show");}

function clearSlliModalFields(){
  ["id","raNo","subd","ph","blk","lot","buyer","description","traNo","dateReceived","turnoverMars","remarks"].forEach(f=>{
    const el=document.getElementById("slm-"+f);if(el)el.value="";
  });
  document.getElementById("slm-lookup-status").textContent="";
  const hist=document.getElementById("slm-ra-history");hist.style.display="none";hist.innerHTML="";
}

function lookupSlliRA(){clearTimeout(slliRaLookupTimer);slliRaLookupTimer=setTimeout(_doSlliRALookup,300);}

function _checkSlliRAHistory(q){
  const hist=document.getElementById("slm-ra-history");
  const curId=document.getElementById("slm-id").value;
  const norm=s=>(s||"").toString().trim().toUpperCase();
  const matches=(slliData||[]).filter(r=>norm(r.ra_no)===norm(q) && String(r.id)!==String(curId));
  if(!q || !matches.length){hist.style.display="none";hist.innerHTML="";return;}
  const rows=matches.map(r=>`• ${r.subd||""} ${r.ph?("Ph"+r.ph):""} ${r.blk?("Blk"+r.blk):""} ${r.lot?("Lot"+r.lot):""} — ${r.buyer||"(no buyer)"} ${r.date_received?("— Received "+r.date_received):""}`).join("<br>");
  hist.innerHTML=`⚠️ May ${matches.length} narecord na history sa RA# na ito:<br>${rows}`;
  hist.style.display="block";
}

async function _doSlliRALookup(){
  const statusEl=document.getElementById("slm-lookup-status");
  const q=document.getElementById("slm-raNo").value.trim();
  _checkSlliRAHistory(q);
  if(!q){statusEl.textContent="";return;}
  statusEl.textContent="Looking up RA#...";statusEl.style.color="var(--muted)";
  try{
    const res=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"getLotInventoryByRA",raNo:q})}).then(r=>r.json());
    if(res && !res.notFound && !res.error){
      document.getElementById("slm-subd").value=res.sub||"";
      document.getElementById("slm-ph").value=res.ph||"";
      document.getElementById("slm-blk").value=res.blk||"";
      document.getElementById("slm-lot").value=res.lot||"";
      document.getElementById("slm-buyer").value=res.buyers_name||"";
      statusEl.textContent="✓ Match found sa Lot Inventory — Subd/Ph/Blk/Lot/Buyer auto-filled.";
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

function lookupSlliLot(){clearTimeout(slliLookupTimer);slliLookupTimer=setTimeout(_doSlliLookup,300);}

async function _doSlliLookup(){
  const subd=document.getElementById("slm-subd").value.trim();
  const ph=document.getElementById("slm-ph").value.trim();
  const blk=document.getElementById("slm-blk").value.trim();
  const lot=document.getElementById("slm-lot").value.trim();
  const statusEl=document.getElementById("slm-lookup-status");
  if(!subd && !ph && !blk && !lot){statusEl.textContent="";return;}
  statusEl.textContent="Looking up Lot Inventory...";statusEl.style.color="var(--muted)";
  try{
    const res=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"lookupLotInventoryByLot",subd,ph,blk,lot})}).then(r=>r.json());
    if(res && !res.notFound && !res.error){
      document.getElementById("slm-buyer").value=res.buyers_name||"";
      statusEl.textContent="✓ Match found sa Lot Inventory — Buyer's Name auto-filled.";
      statusEl.style.color="#22c55e";
    }else{
      statusEl.textContent="No match found sa Lot Inventory — fill in Buyer's Name manually.";
      statusEl.style.color="var(--muted)";
    }
  }catch(e){
    statusEl.textContent="No match found sa Lot Inventory — fill in Buyer's Name manually.";
    statusEl.style.color="var(--muted)";
  }
}

async function saveSlliRecordUI(){
  const isNew=!document.getElementById("slm-id").value;
  const body={
    action:"saveSlliRecord",
    id:document.getElementById("slm-id").value,
    raNo:document.getElementById("slm-raNo").value.trim(),
    subd:document.getElementById("slm-subd").value.trim(),
    ph:document.getElementById("slm-ph").value.trim(),
    blk:document.getElementById("slm-blk").value.trim(),
    lot:document.getElementById("slm-lot").value.trim(),
    buyer:document.getElementById("slm-buyer").value.trim(),
    description:document.getElementById("slm-description").value.trim(),
    traNo:document.getElementById("slm-traNo").value.trim(),
    dateReceived:document.getElementById("slm-dateReceived").value.trim(),
    turnoverMars:document.getElementById("slm-turnoverMars").value.trim(),
    remarks:document.getElementById("slm-remarks").value.trim(),
    createdBy:CURRENT_USER
  };
  if(!body.raNo && !body.subd && !body.buyer){showAlertModal("RA#, Subd., or Buyer's Name is required.");return;}
  try{
    const res=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(body)}).then(r=>r.json());
    if(res.error){showAlertModal(res.error);return;}
    sectionState.slli.loaded=false;
    await loadSLLI();
    if(isNew){
      showToast("✅ Record added!");
      clearSlliModalFields();
      document.getElementById("slliModalTitle").textContent="Add SLLI Record";
      document.getElementById("slm-raNo").focus();
    }else{
      closeSlliModal();
    }
  }catch(e){showAlertModal("Error saving record.");}
}

async function deleteSlliRecordUI(id){
  if(!await showConfirm("Delete this record?",{title:"Delete Record",okLabel:"Delete",danger:true}))return;
  try{
    const res=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"deleteSlliRecord",id,changedBy:CURRENT_USER})}).then(r=>r.json());
    if(res.error){showAlertModal(res.error);return;}
    sectionState.slli.loaded=false;
    loadSLLI();
  }catch(e){showAlertModal("Error deleting record.");}
}

function slliPDF(){const{jsPDF}=window.jspdf;if(!jsPDF)return;const doc=new jsPDF("l","mm","a4"),q=document.getElementById("slli-search").value;doc.setFontSize(11);doc.setTextColor(40,40,40);doc.text("Sta. Lucia Land, Inc.",14,10);doc.setFontSize(18);doc.setTextColor(26,115,232);doc.text("DOCS REQ TD W/ REG. FEE - SLLI",14,19);doc.setFontSize(10);doc.setTextColor(100,100,100);doc.text(`Keyword: ${q||"All"} | Records: ${document.getElementById("slli-count").textContent} | Generated: ${new Date().toLocaleString()}`,14,26);doc.autoTable({html:"#slliTable",startY:33,theme:"grid",headStyles:{fillColor:[26,115,232]},styles:{fontSize:8}});doc.save(`SLLI_${q||"Report"}.pdf`);}

async function slliExcel(){
  const q=document.getElementById("slli-search").value;
  const columns=[
    {header:"RA#",key:"ra_no",width:10},
    {header:"Subd.",key:"subd",width:16},
    {header:"Ph",key:"ph",width:6},
    {header:"Blk.",key:"blk",width:8},
    {header:"Lot",key:"lot",width:8},
    {header:"Description",key:"description",width:24},
    {header:"Buyer's Name",key:"buyer",width:22},
    {header:"Tra#",key:"tra_no",width:12},
    {header:"Remarks",key:"remarks",width:24},
    {header:"Date Received",key:"date_received",width:14},
    {header:"Turn-Over (Mars)",key:"turnover_mars",width:16}
  ];
  const data=slliFiltered.map(r=>({
    ra_no:r.ra_no||"",subd:r.subd||"",ph:r.ph||"",blk:r.blk||"",lot:r.lot||"",description:r.description||"",
    buyer:r.buyer||"",tra_no:r.tra_no||"",remarks:r.remarks||"",date_received:r.date_received||"",turnover_mars:r.turnover_mars||""
  }));
  const wb=await buildLogoWorkbook("DOCS REQ TD W/ REG. FEE - SLLI",columns,data,{sheetName:"SLLI",subtitle:`Keyword: ${q||"All"}`});
  const stamp=new Date().toISOString().slice(0,10);
  const buf=await wb.xlsx.writeBuffer();
  saveAs(new Blob([buf],{type:"application/octet-stream"}),`SLLI_${q||"Report"}_${stamp}.xlsx`);
}

