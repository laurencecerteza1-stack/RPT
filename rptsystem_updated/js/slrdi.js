// ============================================
// slrdi.js — extracted from home.html
// ============================================

async function openSlrdiActivityLog(slrdiId,label){
  document.getElementById("activityLogRecordLabel").textContent=label?`— ${label}`:"";
  const list=document.getElementById("activityLogList");
  list.innerHTML=`<div class="empty-state">Loading...</div>`;
  document.getElementById("liaisonActivityModal").classList.add("show");
  try{
    const res=await safeFetch(CLOUD_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'getSlrdiActivityLog',slrdiId})});
    if(res.error){list.innerHTML=`<div class="empty-state" style="color:#f87171">${res.error}</div>`;return;}
    renderActivityLog(res.log||[],res.labels||{});
  }catch(e){list.innerHTML=`<div class="empty-state" style="color:#f87171">Error loading activity log.</div>`;}
}

async function loadSLRDI(){
  const st=sectionState.slrdi;
  document.getElementById("slrdi-debug").textContent="Loading...";
  document.getElementById("slrdi-body").innerHTML=skeletonRows(11,8);
  if(!slrdiSubdListLoaded)loadSlrdiSubdOptions();
  try{
    const res=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"getSlrdiRecords"})}).then(r=>r.json());
    slrdiData=res.records||[];
    slrdiData.forEach(r=>{r._searchKey=`${r.ra_no||""} ${r.subd||""} ${r.ph||""} ${r.blk||""} ${r.lot||""} ${r.buyer||""} ${r.tra_no||""} ${r.description||""} ${r.remarks||""}`.toUpperCase();});
    st.loaded=true;
    document.getElementById("slrdi-debug").textContent="Loaded: "+slrdiData.length+" rows.";
    slrdiSearch();
  }catch(e){
    document.getElementById("slrdi-debug").textContent="Error.";
    document.getElementById("slrdi-body").innerHTML='<tr><td colspan="11" class="empty-state" style="color:#f87171">Error loading.</td></tr>';
  }
}

async function loadSlrdiSubdOptions(){
  try{
    const res=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"getLotInventorySubdivisions"})}).then(r=>r.json());
    const subs=res.subdivisions||[];
    slrdiSubdListLoaded=true;
    const filterSel=document.getElementById("slrdi-subd-filter");
    filterSel.innerHTML='<option value="">All Subd.</option>'+subs.map(s=>`<option value="${s}">${s}</option>`).join("");
    const datalist=document.getElementById("sm-subd-list");
    if(datalist)datalist.innerHTML=subs.map(s=>`<option value="${s}">`).join("");
  }catch(e){}
}

function slrdiSearch(){clearTimeout(slrdiTimer);slrdiTimer=setTimeout(()=>slrdiGoPage(1),200);}

function slrdiGoPage(p){if(p<1)p=1;if(p>slrdiTotalPages)p=slrdiTotalPages;slrdiCurrentPage=p;_slrdiRender();}

function _slrdiRender(){
  const query=(document.getElementById("slrdi-search").value||"").toUpperCase().trim();
  const subdFilter=document.getElementById("slrdi-subd-filter").value;
  const tbody=document.getElementById("slrdi-body");
  const bar=document.getElementById("slrdiPaginationBar");
  slrdiFiltered=slrdiData.filter(r=>{
    if(subdFilter && (r.subd||"")!==subdFilter)return false;
    if(query && !(r._searchKey||"").includes(query))return false;
    return true;
  });
  document.getElementById("slrdi-count").textContent=slrdiFiltered.length;
  if(!slrdiFiltered.length){
    tbody.innerHTML=`<tr><td colspan="11" class="empty-state">${slrdiData.length?"No matches found.":'No records yet. Click "+ Add Record".'}</td></tr>`;
    bar.style.display="none";
    return;
  }
  slrdiTotalPages=Math.max(1,Math.ceil(slrdiFiltered.length/SLRDI_PAGE_SIZE));
  if(slrdiCurrentPage>slrdiTotalPages)slrdiCurrentPage=slrdiTotalPages;
  const startIdx=(slrdiCurrentPage-1)*SLRDI_PAGE_SIZE;
  const shown=slrdiFiltered.slice(startIdx,startIdx+SLRDI_PAGE_SIZE);
  tbody.innerHTML=shown.map(r=>`<tr>
    <td>${r.ra_no||"---"}</td><td>${r.subd||"---"}</td><td>${r.ph||"---"}</td><td>${r.blk||"---"}</td><td>${r.lot||"---"}</td>
    <td>${r.description||"---"}</td><td>${r.buyer||"---"}</td><td>${r.tra_no||"---"}</td><td>${r.turn_over_date||"---"}</td><td>${r.remarks||"---"}</td>
    <td style="text-align:center;white-space:nowrap"><button class="btn btn-ghost" style="padding:4px 8px;font-size:11px" onclick='openSlrdiModal(${JSON.stringify(r).replace(/'/g,"&apos;")})'>Edit</button>
    <button class="btn btn-ghost" style="padding:4px 8px;font-size:11px;color:#f87171" onclick="deleteSlrdiRecordUI(${r.id})">Del</button>
    <button class="btn btn-ghost" style="padding:4px 8px;font-size:11px" onclick="openSlrdiActivityLog(${r.id},'${(r.ra_no||r.buyer||'').replace(/'/g,"\\'")}')" title="View activity log">🕒</button></td>
  </tr>`).join("");
  bar.style.display="flex";
  const from=startIdx+1,to=Math.min(startIdx+SLRDI_PAGE_SIZE,slrdiFiltered.length);
  document.getElementById("slrdiPageInfo").textContent=`Showing ${from}–${to} of ${slrdiFiltered.length} records`;
  document.getElementById("slrdiBtnFirst").disabled=slrdiCurrentPage===1;
  document.getElementById("slrdiBtnPrev").disabled=slrdiCurrentPage===1;
  document.getElementById("slrdiBtnNext").disabled=slrdiCurrentPage===slrdiTotalPages;
  document.getElementById("slrdiBtnLast").disabled=slrdiCurrentPage===slrdiTotalPages;
  const nums=document.getElementById("slrdiPageNumbers");nums.innerHTML="";
  let startP=Math.max(1,slrdiCurrentPage-2),endP=Math.min(slrdiTotalPages,startP+4);
  startP=Math.max(1,endP-4);
  for(let i=startP;i<=endP;i++){const b=document.createElement("button");b.className="page-num-btn"+(i===slrdiCurrentPage?" active":"");b.textContent=i;b.onclick=()=>slrdiGoPage(i);nums.appendChild(b);}
}

function openSlrdiModal(record){
  document.getElementById("slrdiModalTitle").textContent=record?"Edit SLRDI Record":"Add SLRDI Record";
  clearSlrdiModalFields();
  if(record){
    document.getElementById("sm-id").value=record.id;
    document.getElementById("sm-raNo").value=record.ra_no||"";
    document.getElementById("sm-subd").value=record.subd||"";
    document.getElementById("sm-ph").value=record.ph||"";
    document.getElementById("sm-blk").value=record.blk||"";
    document.getElementById("sm-lot").value=record.lot||"";
    document.getElementById("sm-buyer").value=record.buyer||"";
    document.getElementById("sm-description").value=record.description||"";
    document.getElementById("sm-traNo").value=record.tra_no||"";
    document.getElementById("sm-turnOverDate").value=record.turn_over_date||"";
    document.getElementById("sm-remarks").value=record.remarks||"";
  }
  document.getElementById("slrdiModalOverlay").classList.add("show");
}

function closeSlrdiModal(){document.getElementById("slrdiModalOverlay").classList.remove("show");}

function clearSlrdiModalFields(){
  ["id","raNo","subd","ph","blk","lot","buyer","description","traNo","turnOverDate","remarks"].forEach(f=>{
    const el=document.getElementById("sm-"+f);if(el)el.value="";
  });
  document.getElementById("sm-lookup-status").textContent="";
  const hist=document.getElementById("sm-ra-history");hist.style.display="none";hist.innerHTML="";
}

function lookupSlrdiRA(){clearTimeout(slrdiRaLookupTimer);slrdiRaLookupTimer=setTimeout(_doSlrdiRALookup,300);}

function _checkSlrdiRAHistory(q){
  const hist=document.getElementById("sm-ra-history");
  const curId=document.getElementById("sm-id").value;
  const norm=s=>(s||"").toString().trim().toUpperCase();
  const matches=(slrdiData||[]).filter(r=>norm(r.ra_no)===norm(q) && String(r.id)!==String(curId));
  if(!q || !matches.length){hist.style.display="none";hist.innerHTML="";return;}
  const rows=matches.map(r=>`• ${r.subd||""} ${r.ph?("Ph"+r.ph):""} ${r.blk?("Blk"+r.blk):""} ${r.lot?("Lot"+r.lot):""} — ${r.buyer||"(no buyer)"} ${r.turn_over_date?("— Turn Over "+r.turn_over_date):""}`).join("<br>");
  hist.innerHTML=`⚠️ May ${matches.length} narecord na history sa RA# na ito:<br>${rows}`;
  hist.style.display="block";
}

async function _doSlrdiRALookup(){
  const statusEl=document.getElementById("sm-lookup-status");
  const q=document.getElementById("sm-raNo").value.trim();
  _checkSlrdiRAHistory(q);
  if(!q){statusEl.textContent="";return;}
  statusEl.textContent="Looking up RA#...";statusEl.style.color="var(--muted)";
  try{
    const res=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"getLotInventoryByRA",raNo:q})}).then(r=>r.json());
    if(res && !res.notFound && !res.error){
      document.getElementById("sm-subd").value=res.sub||"";
      document.getElementById("sm-ph").value=res.ph||"";
      document.getElementById("sm-blk").value=res.blk||"";
      document.getElementById("sm-lot").value=res.lot||"";
      document.getElementById("sm-buyer").value=res.buyers_name||"";
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

function lookupSlrdiLot(){clearTimeout(slrdiLookupTimer);slrdiLookupTimer=setTimeout(_doSlrdiLookup,300);}

async function _doSlrdiLookup(){
  const subd=document.getElementById("sm-subd").value.trim();
  const ph=document.getElementById("sm-ph").value.trim();
  const blk=document.getElementById("sm-blk").value.trim();
  const lot=document.getElementById("sm-lot").value.trim();
  const statusEl=document.getElementById("sm-lookup-status");
  if(!subd && !ph && !blk && !lot){statusEl.textContent="";return;}
  statusEl.textContent="Looking up Lot Inventory...";statusEl.style.color="var(--muted)";
  try{
    const res=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"lookupLotInventoryByLot",subd,ph,blk,lot})}).then(r=>r.json());
    if(res && !res.notFound && !res.error){
      document.getElementById("sm-buyer").value=res.buyers_name||"";
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

async function saveSlrdiRecordUI(){
  const isNew=!document.getElementById("sm-id").value;
  const body={
    action:"saveSlrdiRecord",
    id:document.getElementById("sm-id").value,
    raNo:document.getElementById("sm-raNo").value.trim(),
    subd:document.getElementById("sm-subd").value.trim(),
    ph:document.getElementById("sm-ph").value.trim(),
    blk:document.getElementById("sm-blk").value.trim(),
    lot:document.getElementById("sm-lot").value.trim(),
    buyer:document.getElementById("sm-buyer").value.trim(),
    description:document.getElementById("sm-description").value.trim(),
    traNo:document.getElementById("sm-traNo").value.trim(),
    turnOverDate:document.getElementById("sm-turnOverDate").value.trim(),
    remarks:document.getElementById("sm-remarks").value.trim(),
    createdBy:CURRENT_USER
  };
  if(!body.raNo && !body.subd && !body.buyer){showAlertModal("RA#, Subd., or Buyer's Name is required.");return;}
  try{
    const res=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(body)}).then(r=>r.json());
    if(res.error){showAlertModal(res.error);return;}
    sectionState.slrdi.loaded=false;
    await loadSLRDI();
    if(isNew){
      showToast("✅ Record added!");
      clearSlrdiModalFields();
      document.getElementById("slrdiModalTitle").textContent="Add SLRDI Record";
      document.getElementById("sm-raNo").focus();
    }else{
      closeSlrdiModal();
    }
  }catch(e){showAlertModal("Error saving record.");}
}

async function deleteSlrdiRecordUI(id){
  if(!await showConfirm("Delete this record?",{title:"Delete Record",okLabel:"Delete",danger:true}))return;
  try{
    const res=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"deleteSlrdiRecord",id,changedBy:CURRENT_USER})}).then(r=>r.json());
    if(res.error){showAlertModal(res.error);return;}
    sectionState.slrdi.loaded=false;
    loadSLRDI();
  }catch(e){showAlertModal("Error deleting record.");}
}

function slrdiPDF(){const{jsPDF}=window.jspdf;if(!jsPDF)return;const doc=new jsPDF("l","mm","a4"),q=document.getElementById("slrdi-search").value;doc.setFontSize(11);doc.setTextColor(40,40,40);doc.text("Sta. Lucia Realty & Development, Inc.",14,10);doc.setFontSize(18);doc.setTextColor(26,115,232);doc.text("DOCS REQ TD W/ REG. FEE - SLRDI",14,19);doc.setFontSize(10);doc.setTextColor(100,100,100);doc.text(`Keyword: ${q||"All"} | Records: ${document.getElementById("slrdi-count").textContent} | Generated: ${new Date().toLocaleString()}`,14,26);doc.autoTable({html:"#slrdiTable",startY:33,theme:"grid",headStyles:{fillColor:[26,115,232]},styles:{fontSize:8}});doc.save(`SLRDI_${q||"Report"}.pdf`);}

async function slrdiExcel(){
  const q=document.getElementById("slrdi-search").value;
  const columns=[
    {header:"RA#",key:"ra_no",width:10},
    {header:"Subd.",key:"subd",width:16},
    {header:"Ph",key:"ph",width:6},
    {header:"Blk.",key:"blk",width:8},
    {header:"Lot",key:"lot",width:8},
    {header:"Description",key:"description",width:24},
    {header:"Buyer's Name",key:"buyer",width:22},
    {header:"Tra#",key:"tra_no",width:12},
    {header:"Turn Over Date",key:"turn_over_date",width:16},
    {header:"Remarks",key:"remarks",width:24}
  ];
  const data=slrdiFiltered.map(r=>({
    ra_no:r.ra_no||"",subd:r.subd||"",ph:r.ph||"",blk:r.blk||"",lot:r.lot||"",description:r.description||"",
    buyer:r.buyer||"",tra_no:r.tra_no||"",turn_over_date:r.turn_over_date||"",remarks:r.remarks||""
  }));
  const wb=await buildLogoWorkbook("DOCS REQ TD W/ REG. FEE - SLRDI",columns,data,{sheetName:"SLRDI",subtitle:`Keyword: ${q||"All"}`});
  const stamp=new Date().toISOString().slice(0,10);
  const buf=await wb.xlsx.writeBuffer();
  saveAs(new Blob([buf],{type:"application/octet-stream"}),`SLRDI_${q||"Report"}_${stamp}.xlsx`);
}

