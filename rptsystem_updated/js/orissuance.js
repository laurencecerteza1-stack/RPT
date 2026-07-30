// ============================================
// orissuance.js — OR Issuance module
// MC#, OR Number, From/To Quarter, Amount, OR Date
// Linked to a Lot via Subd/Ph/Blk/Lot (Subdivision Monitor / Lot Inventory)
// ============================================

const OR_QUARTER_LABELS={1:"1st Quarter",2:"2nd Quarter",3:"3rd Quarter",4:"4th Quarter"};

async function openOrIssuanceActivityLog(orId,label){
  document.getElementById("activityLogRecordLabel").textContent=label?`— ${label}`:"";
  const list=document.getElementById("activityLogList");
  list.innerHTML=`<div class="empty-state">Loading...</div>`;
  document.getElementById("liaisonActivityModal").classList.add("show");
  try{
    const res=await safeFetch(CLOUD_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'getOrIssuanceActivityLog',orId})});
    if(res.error){list.innerHTML=`<div class="empty-state" style="color:#f87171">${res.error}</div>`;return;}
    renderActivityLog(res.log||[],res.labels||{});
  }catch(e){list.innerHTML=`<div class="empty-state" style="color:#f87171">Error loading activity log.</div>`;}
}

async function loadOrIssuance(){
  const st=sectionState.orissuance;
  document.getElementById("or-debug").textContent="Loading...";
  document.getElementById("or-body").innerHTML=skeletonRows(12,8);
  if(!orSubdListLoaded)loadOrSubdOptions();
  try{
    const res=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"getOrIssuanceRecords"})}).then(r=>r.json());
    orData=res.records||[];
    orData.forEach(r=>{r._searchKey=`${r.ra_number||""} ${r.as_number||""} ${r.subd||""} ${r.ph||""} ${r.blk||""} ${r.lot||""} ${r.buyer||""} ${r.mc_no||""} ${r.or_number||""}`.toUpperCase();});
    st.loaded=true;
    document.getElementById("or-debug").textContent="Loaded: "+orData.length+" rows.";
    orSearch();
  }catch(e){
    document.getElementById("or-debug").textContent="Error.";
    document.getElementById("or-body").innerHTML='<tr><td colspan="10" class="empty-state" style="color:#f87171">Error loading.</td></tr>';
  }
}

async function loadOrSubdOptions(){
  try{
    const res=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"getLotInventorySubdivisions"})}).then(r=>r.json());
    const subs=res.subdivisions||[];
    orSubdListLoaded=true;
    const filterSel=document.getElementById("or-subd-filter");
    filterSel.innerHTML='<option value="">All Subd.</option>'+subs.map(s=>`<option value="${s}">${s}</option>`).join("");
    const datalist=document.getElementById("orm-subd-list");
    if(datalist)datalist.innerHTML=subs.map(s=>`<option value="${s}">`).join("");
  }catch(e){}
}

function orSearch(){clearTimeout(orTimer);orTimer=setTimeout(()=>orGoPage(1),200);}

function orGoPage(p){if(p<1)p=1;if(p>orTotalPages)p=orTotalPages;orCurrentPage=p;_orRender();}

function _orRender(){
  const query=(document.getElementById("or-search").value||"").toUpperCase().trim();
  const subdFilter=document.getElementById("or-subd-filter").value;
  const asFilter=(document.getElementById("or-as-filter").value||"").toUpperCase().trim();
  const tbody=document.getElementById("or-body");
  const bar=document.getElementById("orPaginationBar");
  orFiltered=orData.filter(r=>{
    if(subdFilter && (r.subd||"")!==subdFilter)return false;
    if(asFilter && !(r.as_number||"").toUpperCase().includes(asFilter))return false;
    if(query && !(r._searchKey||"").includes(query))return false;
    return true;
  });
  document.getElementById("or-count").textContent=orFiltered.length;
  if(!orFiltered.length){
    tbody.innerHTML=`<tr><td colspan="11" class="empty-state">${orData.length?"No matches found.":'No records yet. Click "+ Issue OR".'}</td></tr>`;
    bar.style.display="none";
    return;
  }
  orTotalPages=Math.max(1,Math.ceil(orFiltered.length/OR_PAGE_SIZE));
  if(orCurrentPage>orTotalPages)orCurrentPage=orTotalPages;
  const startIdx=(orCurrentPage-1)*OR_PAGE_SIZE;
  const shown=orFiltered.slice(startIdx,startIdx+OR_PAGE_SIZE);
  tbody.innerHTML=shown.map(r=>`<tr>
    <td>${r.ra_number||"---"}</td>
    <td>${r.as_number||"---"}</td>
    <td>${r.subd||"---"} ${r.ph?("Ph"+r.ph):""} ${r.blk?("Blk"+r.blk):""} ${r.lot?("Lot"+r.lot):""}</td>
    <td>${r.buyer||"---"}</td><td>${r.mc_no||"---"}</td><td>${r.or_number||"---"}</td>
    <td>${r.yr||"---"}</td>
    <td>${r.from_quarter_label||"---"}</td><td>${r.to_quarter_label||"---"}</td>
    <td style="text-align:right">${r.amount!=null && r.amount!=="" ? fcy(parseNum(r.amount)) : "---"}</td>
    <td>${r.or_date||"---"}</td>
    <td style="text-align:center;white-space:nowrap"><button class="btn btn-ghost" style="padding:4px 8px;font-size:11px" onclick='openOrIssuanceModal(${JSON.stringify(r).replace(/'/g,"&apos;")})'>Edit</button>
    <button class="btn btn-ghost" style="padding:4px 8px;font-size:11px;color:#f87171" onclick="deleteOrIssuanceRecordUI(${r.id})">Del</button>
    <button class="btn btn-ghost" style="padding:4px 8px;font-size:11px" onclick="openOrIssuanceActivityLog(${r.id},'${(r.or_number||r.buyer||'').replace(/'/g,"\\'")}')" title="View activity log">🕒</button></td>
  </tr>`).join("");
  bar.style.display="flex";
  const from=startIdx+1,to=Math.min(startIdx+OR_PAGE_SIZE,orFiltered.length);
  document.getElementById("orPageInfo").textContent=`Showing ${from}–${to} of ${orFiltered.length} records`;
  document.getElementById("orBtnFirst").disabled=orCurrentPage===1;
  document.getElementById("orBtnPrev").disabled=orCurrentPage===1;
  document.getElementById("orBtnNext").disabled=orCurrentPage===orTotalPages;
  document.getElementById("orBtnLast").disabled=orCurrentPage===orTotalPages;
  const nums=document.getElementById("orPageNumbers");nums.innerHTML="";
  let startP=Math.max(1,orCurrentPage-2),endP=Math.min(orTotalPages,startP+4);
  startP=Math.max(1,endP-4);
  for(let i=startP;i<=endP;i++){const b=document.createElement("button");b.className="page-num-btn"+(i===orCurrentPage?" active":"");b.textContent=i;b.onclick=()=>orGoPage(i);nums.appendChild(b);}
}

function openOrIssuanceModal(record){
  document.getElementById("orModalTitle").textContent=record?"Edit OR Record":"Issue OR";
  clearOrModalFields();
  if(record){
    document.getElementById("orm-id").value=record.id;
    document.getElementById("orm-raNumber").value=record.ra_number||"";
    document.getElementById("orm-asNumber").value=record.as_number||"";
    document.getElementById("orm-subd").value=record.subd||"";
    document.getElementById("orm-ph").value=record.ph||"";
    document.getElementById("orm-blk").value=record.blk||"";
    document.getElementById("orm-lot").value=record.lot||"";
    document.getElementById("orm-buyer").value=record.buyer||"";
    document.getElementById("orm-mcNo").value=record.mc_no||"";
    document.getElementById("orm-orNumber").value=record.or_number||"";
    document.getElementById("orm-yr").value=record.yr||"";
    document.getElementById("orm-fromQuarter").value=record.from_quarter||"";
    document.getElementById("orm-toQuarter").value=record.to_quarter||"";
    document.getElementById("orm-amount").value=record.amount!=null?record.amount:"";
    document.getElementById("orm-orDate").value=record.or_date||"";
  }
  document.getElementById("orModalOverlay").classList.add("show");
}

function closeOrIssuanceModal(){document.getElementById("orModalOverlay").classList.remove("show");}

function clearOrModalFields(){
  ["id","raNumber","asNumber","subd","ph","blk","lot","buyer","mcNo","orNumber","yr","fromQuarter","toQuarter","amount","orDate"].forEach(f=>{
    const el=document.getElementById("orm-"+f);if(el)el.value="";
  });
  document.getElementById("orm-lookup-status").textContent="";
  const hist=document.getElementById("orm-ra-history");if(hist){hist.style.display="none";hist.innerHTML="";}
}

function lookupOrRA(){clearTimeout(orRaLookupTimer);orRaLookupTimer=setTimeout(_doOrRALookup,300);}

function _checkOrRAHistory(q){
  const hist=document.getElementById("orm-ra-history");
  if(!hist)return;
  const curId=document.getElementById("orm-id").value;
  const norm=s=>(s||"").toString().trim().toUpperCase();
  const matches=(orData||[]).filter(r=>norm(r.ra_number)===norm(q) && String(r.id)!==String(curId));
  if(!q || !matches.length){hist.style.display="none";hist.innerHTML="";return;}
  const rows=matches.map(r=>`• ${r.subd||""} ${r.ph?("Ph"+r.ph):""} ${r.blk?("Blk"+r.blk):""} ${r.lot?("Lot"+r.lot):""} — ${r.buyer||"(no buyer)"} ${r.or_number?("— OR#"+r.or_number):""}`).join("<br>");
  hist.innerHTML=`⚠️ May ${matches.length} narecord na OR issuance history sa RA# na ito:<br>${rows}`;
  hist.style.display="block";
}

async function _doOrRALookup(){
  const statusEl=document.getElementById("orm-lookup-status");
  const q=document.getElementById("orm-raNumber").value.trim();
  _checkOrRAHistory(q);
  if(!q){statusEl.textContent="";return;}
  statusEl.textContent="Looking up RA#...";statusEl.style.color="var(--muted)";
  try{
    const res=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"getLotInventoryByRA",raNo:q})}).then(r=>r.json());
    if(res && !res.notFound && !res.error){
      document.getElementById("orm-subd").value=res.sub||"";
      document.getElementById("orm-ph").value=res.ph||"";
      document.getElementById("orm-blk").value=res.blk||"";
      document.getElementById("orm-lot").value=res.lot||"";
      document.getElementById("orm-buyer").value=res.buyers_name||"";
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

function lookupOrLot(){clearTimeout(orLookupTimer);orLookupTimer=setTimeout(_doOrLookup,300);}

async function _doOrLookup(){
  const subd=document.getElementById("orm-subd").value.trim();
  const ph=document.getElementById("orm-ph").value.trim();
  const blk=document.getElementById("orm-blk").value.trim();
  const lot=document.getElementById("orm-lot").value.trim();
  const statusEl=document.getElementById("orm-lookup-status");
  if(!subd && !ph && !blk && !lot){statusEl.textContent="";return;}
  statusEl.textContent="Looking up Lot Inventory...";statusEl.style.color="var(--muted)";
  try{
    const res=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"lookupLotInventoryByLot",subd,ph,blk,lot})}).then(r=>r.json());
    if(res && !res.notFound && !res.error){
      document.getElementById("orm-buyer").value=res.buyers_name||"";
      statusEl.textContent="✓ Match found sa Subdivision Monitor / Lot Inventory — Buyer's Name auto-filled.";
      statusEl.style.color="#22c55e";
    }else{
      statusEl.textContent="No match found sa Subdivision Monitor — fill in Buyer's Name manually.";
      statusEl.style.color="var(--muted)";
    }
  }catch(e){
    statusEl.textContent="No match found sa Subdivision Monitor — fill in Buyer's Name manually.";
    statusEl.style.color="var(--muted)";
  }
}

async function saveOrIssuanceRecordUI(){
  const isNew=!document.getElementById("orm-id").value;
  const body={
    action:"saveOrIssuanceRecord",
    id:document.getElementById("orm-id").value,
    raNumber:document.getElementById("orm-raNumber").value.trim(),
    asNumber:document.getElementById("orm-asNumber").value.trim(),
    subd:document.getElementById("orm-subd").value.trim(),
    ph:document.getElementById("orm-ph").value.trim(),
    blk:document.getElementById("orm-blk").value.trim(),
    lot:document.getElementById("orm-lot").value.trim(),
    buyer:document.getElementById("orm-buyer").value.trim(),
    mcNo:document.getElementById("orm-mcNo").value.trim(),
    orNumber:document.getElementById("orm-orNumber").value.trim(),
    yr:document.getElementById("orm-yr").value.trim(),
    fromQuarter:document.getElementById("orm-fromQuarter").value,
    toQuarter:document.getElementById("orm-toQuarter").value,
    amount:document.getElementById("orm-amount").value.trim(),
    orDate:document.getElementById("orm-orDate").value.trim(),
    createdBy:CURRENT_USER
  };
  if(!body.orNumber && !body.subd && !body.buyer){alert("OR Number, Subd., o Buyer's Name ay required.");return;}
  try{
    const res=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(body)}).then(r=>r.json());
    if(res.error){alert(res.error);return;}
    sectionState.orissuance.loaded=false;
    await loadOrIssuance();
    if(typeof subdivmonNotifyDataChanged==="function")subdivmonNotifyDataChanged();
    if(isNew){
      showToast("✅ OR issued!");
      clearOrModalFields();
      document.getElementById("orModalTitle").textContent="Issue OR";
      document.getElementById("orm-subd").focus();
    }else{
      closeOrIssuanceModal();
    }
  }catch(e){alert("Error saving record.");}
}

async function deleteOrIssuanceRecordUI(id){
  if(!await showConfirm("Delete this record?",{title:"Delete Record",okLabel:"Delete",danger:true}))return;
  try{
    const res=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"deleteOrIssuanceRecord",id,changedBy:CURRENT_USER})}).then(r=>r.json());
    if(res.error){alert(res.error);return;}
    sectionState.orissuance.loaded=false;
    loadOrIssuance();
    if(typeof subdivmonNotifyDataChanged==="function")subdivmonNotifyDataChanged();
  }catch(e){alert("Error deleting record.");}
}

function orIssuancePDF(){const{jsPDF}=window.jspdf;if(!jsPDF)return;const doc=new jsPDF("l","mm","a4"),q=document.getElementById("or-search").value;doc.setFontSize(11);doc.setTextColor(40,40,40);doc.text("Sta. Lucia Land, Inc.",14,10);doc.setFontSize(18);doc.setTextColor(26,115,232);doc.text("OR ISSUANCE",14,19);doc.setFontSize(10);doc.setTextColor(100,100,100);doc.text(`Keyword: ${q||"All"} | Records: ${document.getElementById("or-count").textContent} | Generated: ${new Date().toLocaleString()}`,14,26);doc.autoTable({html:"#orTable",startY:33,theme:"grid",headStyles:{fillColor:[26,115,232]},styles:{fontSize:8}});doc.save(`OR_Issuance_${q||"Report"}.pdf`);}

async function orIssuanceExcel(){
  const q=document.getElementById("or-search").value;
  const columns=[
    {header:"RA#",key:"ra_number",width:14},
    {header:"AS#",key:"as_number",width:16},
    {header:"Subd/Ph/Blk/Lot",key:"lotLabel",width:22},
    {header:"Buyer's Name",key:"buyer",width:22},
    {header:"MC#",key:"mc_no",width:12},
    {header:"OR Number",key:"or_number",width:14},
    {header:"Year",key:"yr",width:10},
    {header:"From",key:"from_quarter_label",width:14},
    {header:"To",key:"to_quarter_label",width:14},
    {header:"Amount",key:"amount",width:14},
    {header:"OR Date",key:"or_date",width:14}
  ];
  const data=orFiltered.map(r=>({
    ra_number:r.ra_number||"",
    as_number:r.as_number||"",
    lotLabel:`${r.subd||""} ${r.ph?("Ph"+r.ph):""} ${r.blk?("Blk"+r.blk):""} ${r.lot?("Lot"+r.lot):""}`.trim(),
    buyer:r.buyer||"",mc_no:r.mc_no||"",or_number:r.or_number||"",yr:r.yr||"",
    from_quarter_label:r.from_quarter_label||"",to_quarter_label:r.to_quarter_label||"",
    amount:r.amount!=null?r.amount:"",or_date:r.or_date||""
  }));
  const wb=await buildLogoWorkbook("OR ISSUANCE",columns,data,{sheetName:"OR Issuance",subtitle:`Keyword: ${q||"All"}`});
  const stamp=new Date().toISOString().slice(0,10);
  const buf=await wb.xlsx.writeBuffer();
  saveAs(new Blob([buf],{type:"application/octet-stream"}),`OR_Issuance_${q||"Report"}_${stamp}.xlsx`);
}