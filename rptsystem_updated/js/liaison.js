// ============================================
// liaison.js — extracted from home.html
// ============================================

function openLiaisonModal(record){
  document.getElementById("liaisonModalTitle").textContent=record?"Edit Liaison Record":"Add Liaison Record";
  document.getElementById("lm-id").value=record?record.id:"";
  LM_FIELDS.forEach(f=>{const el=document.getElementById("lm-"+f);if(!el)return;const dbKey=f.replace(/([A-Z])/g,"_$1").toLowerCase();el.value=record?(record[dbKey]??""):"";});
  document.getElementById("lm-imagePath").value=record&&record.image_path?record.image_path:"";
  document.getElementById("lm-imageFile").value="";
  document.getElementById("lm-image-status").textContent="";
  document.getElementById("lm-ra-status").textContent="";
  document.getElementById("lm-ra-duplicate").style.display="none";
  showLiaisonImagePreview(record&&record.image_path?record.image_path:"");
  document.getElementById("lm-extra-status").textContent="";
  loadExtraAttachments(record?record.id:"");
  document.getElementById("liaisonModalOverlay").classList.add("show");
}

function closeLiaisonModal(){document.getElementById("liaisonModalOverlay").classList.remove("show");}

function clearLiaisonModalFields(){LM_FIELDS.forEach(f=>{const el=document.getElementById("lm-"+f);if(el)el.value="";});document.getElementById("lm-ra-status").textContent="";document.getElementById("lm-ra-duplicate").style.display="none";document.getElementById("lm-imagePath").value="";document.getElementById("lm-imageFile").value="";document.getElementById("lm-image-status").textContent="";showLiaisonImagePreview("");document.getElementById("lm-extraFile").value="";document.getElementById("lm-extra-status").textContent="";lmExtraAttachments=[];document.getElementById("lm-extra-attachments").innerHTML="";}

function showLiaisonImagePreview(path){
  const wrap=document.getElementById("lm-image-preview-wrap");
  const img=document.getElementById("lm-image-preview");
  const pdfLink=document.getElementById("lm-pdf-preview");
  if(!path){img.src="";img.style.display="none";pdfLink.style.display="none";wrap.style.display="none";return;}
  const isPdf=/\.pdf($|\?)/i.test(path)||path.startsWith("data:application/pdf");
  if(isPdf){
    img.style.display="none";img.src="";
    pdfLink.href=path;pdfLink.style.display="inline-block";
  }else{
    pdfLink.style.display="none";
    img.src=path;img.style.display="block";
  }
  wrap.style.display="block";
}

function removeLiaisonImage(){
  document.getElementById("lm-imagePath").value="";
  document.getElementById("lm-imageFile").value="";
  document.getElementById("lm-image-status").textContent="";
  showLiaisonImagePreview("");
}

async function previewLiaisonImage(event){
  const file=event.target.files[0];
  const statusEl=document.getElementById("lm-image-status");
  if(!file)return;
  const allowed=["image/jpeg","image/png","image/webp","application/pdf"];
  if(!allowed.includes(file.type)){alert("Only image (jpg, png, webp) or PDF files are allowed.");event.target.value="";return;}
  if(file.size>5*1024*1024){alert("File is too large (max 5MB).");event.target.value="";return;}
  if(file.type==="application/pdf"){
    showLiaisonImagePreview(URL.createObjectURL(file));
  }else{
    try{
      const thumbUrl=await makeThumbnailDataURL(file,480);
      showLiaisonImagePreview(thumbUrl);
    }catch(e){
      const reader=new FileReader();
      reader.onload=e=>showLiaisonImagePreview(e.target.result);
      reader.readAsDataURL(file);
    }
  }
  statusEl.textContent="Attachment selected — it will be uploaded when the record is saved.";
  statusEl.style.color="var(--muted)";
}

async function uploadLiaisonImageIfNeeded(liaisonId){
  const fileInput=document.getElementById("lm-imageFile");
  const file=fileInput.files[0];
  if(!file) return document.getElementById("lm-imagePath").value; // walang bagong file, ibalik yung existing path (or "" kung inalis)
  const statusEl=document.getElementById("lm-image-status");
  statusEl.textContent="Uploading image...";
  statusEl.style.color="var(--muted)";
  const fd=new FormData();
  fd.append("image",file);
  fd.append("liaisonId",liaisonId||"0");
  const res=await fetch("upload_liaison_image.php",{method:"POST",body:fd}).then(r=>r.json());
  if(res.error){statusEl.textContent="Upload error: "+res.error;statusEl.style.color="#f87171";throw new Error(res.error);}
  statusEl.textContent="✓ Image uploaded.";
  statusEl.style.color="#22c55e";
  return res.path;
}

async function openLiaisonRecordById(id){
  let existing=myLiaisonData.find(r=>r.id==id);
  closeAllAttachmentsModal();
  if(!existing){
    await loadMyLiaisonRecords();
    existing=myLiaisonData.find(r=>r.id==id);
  }
  if(existing) openLiaisonModal(existing);
}

function lookupRA(){
  clearTimeout(raLookupTimer);
  raLookupTimer=setTimeout(_doLookupRA,300);
}

async function _doLookupRA(){
  const statusEl=document.getElementById("lm-ra-status");
  const dupEl=document.getElementById("lm-ra-duplicate");
  const q=document.getElementById("lm-raNo").value.trim();
  if(!q){statusEl.textContent="";dupEl.style.display="none";return;}
  statusEl.textContent="Looking up...";statusEl.style.color="var(--muted)";
  dupEl.style.display="none";
  try{
    const res=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"getLotInventoryByRA",raNo:q})}).then(r=>r.json());
    if(res && !res.notFound && !res.error){
      document.getElementById("lm-buyer").value=res.buyers_name||"";
      document.getElementById("lm-subd").value=res.sub||"";
      document.getElementById("lm-ph").value=res.ph||"";
      document.getElementById("lm-blk").value=res.blk||"";
      document.getElementById("lm-lot").value=res.lot||"";
      document.getElementById("lm-description").value=q;
      document.getElementById("lm-tct").value=res.tct_no||"";
      document.getElementById("lm-tdNo").value=res.td_no_latest||res.td_no_old||"";
      document.getElementById("lm-owner").value=res.lot_owner||"";
      statusEl.textContent="✓ Match found sa Lot Inventory — fields auto-filled.";
      statusEl.style.color="#22c55e";
    }else{
      statusEl.textContent="No match found for this RA# — fill in manually.";
      statusEl.style.color="var(--muted)";
    }
  }catch(e){
    statusEl.textContent="No match found for this RA# — fill in manually.";
    statusEl.style.color="var(--muted)";
  }
  try{
    const excludeId=parseInt(document.getElementById("lm-id").value)||0;
    const dup=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"getLiaisonRecordsByRA",raNo:q,excludeId})}).then(r=>r.json());
    if(dup && dup.total>0){
      lmDuplicateRows=dup.rows;
      const items=dup.rows.slice(0,3).map((r,i)=>`<div class="lm-dup-item" style="cursor:pointer;text-decoration:underline" onclick="openLiaisonModal(lmDuplicateRows[${i}])">• ${r.date_requested||"---"} — ${r.liaison_name||"---"} — ₱${parseFloat(r.amount||0).toLocaleString(undefined,{minimumFractionDigits:2})}${r.or_no?" — OR# "+r.or_no:""}${r.or_yr_covered?" — OR Yr "+r.or_yr_covered:""}${r.status_remarks?" — "+r.status_remarks:""}</div>`).join("");
      const more=dup.total>3?`<div>+${dup.total-3} more</div>`:"";
      dupEl.innerHTML=`⚠ ${dup.total} existing liaison record(s) found for this RA# (click to open):${items}${more}`;
      dupEl.style.display="block";
    }else{
      dupEl.style.display="none";
    }
  }catch(e){dupEl.style.display="none";}
}

async function saveLiaisonRecordUI(){
  const body={action:"saveLiaisonRecord",id:document.getElementById("lm-id").value,createdBy:CURRENT_USER};
  LM_FIELDS.forEach(f=>{const el=document.getElementById("lm-"+f);body[f]=el?el.value.trim():"";});
  if(!body.raNo && !body.buyer){alert("RA# o Buyer's Name ay required.");return;}
  const existingImagePath=document.getElementById("lm-imagePath").value;
  const hasNewFile=document.getElementById("lm-imageFile").files.length>0;
  body.removeImage=(!existingImagePath && !hasNewFile)?true:false;
  try{
    if(hasNewFile){
      body.imagePath=await uploadLiaisonImageIfNeeded(body.id);
    }else{
      body.imagePath=existingImagePath||"";
    }
    const res=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(body)}).then(r=>r.json());
    if(res.error){alert(res.error);return;}
    clearLiaisonModalFields();
    document.getElementById("lm-id").value="";
    document.getElementById("liaisonModalTitle").textContent="Add Liaison Record";
    const statusEl=document.getElementById("lm-image-status");
    statusEl.textContent="✓ Record saved. You can add the next one.";
    statusEl.style.color="#22c55e";
    document.getElementById("lm-raNo").focus();
    loadMyLiaisonRecords();
    if(typeof subdivmonNotifyDataChanged==="function")subdivmonNotifyDataChanged();
  }catch(e){alert("Error saving record.");}
}

function _parseCsv(text){
  const rows=[];let row=[],field="",inQuotes=false;
  for(let i=0;i<text.length;i++){
    const c=text[i];
    if(inQuotes){
      if(c==='"'){ if(text[i+1]==='"'){field+='"';i++;} else inQuotes=false; }
      else field+=c;
    }else{
      if(c==='"') inQuotes=true;
      else if(c===','){row.push(field);field="";}
      else if(c==='\r'){}
      else if(c==='\n'){row.push(field);rows.push(row);row=[];field="";}
      else field+=c;
    }
  }
  if(field.length||row.length){row.push(field);rows.push(row);}
  return rows.filter(r=>r.length>1||r[0]!=="");
}

async function handleLiaisonCsvImport(event){
  const file=event.target.files[0];
  if(!file)return;
  const text=await file.text();
  const rows=_parseCsv(text);
  if(!rows.length){alert("CSV is empty.");event.target.value="";return;}
  const header=rows[0].map(h=>h.trim().toLowerCase());
  const records=[];
  for(let i=1;i<rows.length;i++){
    const r=rows[i];
    if(!r.length)continue;
    const rec={createdBy:CURRENT_USER};
    header.forEach((h,idx)=>{const key=CSV_TO_BODY_KEY[h];if(key)rec[key]=(r[idx]||"").trim();});
    if(!rec.raNo && !rec.buyer)continue;
    records.push(rec);
  }
  if(!records.length){alert("No valid rows found in the CSV.");event.target.value="";return;}
  if(!await showConfirm(`Import ${records.length} records into My Liaison Requests. Continue?`,{title:"Import CSV",okLabel:"Import"})){event.target.value="";return;}
  const BATCH=500;let imported=0;
  for(let i=0;i<records.length;i+=BATCH){
    const batch=records.slice(i,i+BATCH);
    try{
      const res=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"bulkImportLiaisonRecords",records:batch})}).then(r=>r.json());
      if(res && res.inserted)imported+=res.inserted;
    }catch(e){}
  }
  alert(`Import complete. ${imported} of ${records.length} records saved.`);
  event.target.value="";
  loadMyLiaisonRecords();
}

async function loadMyLiaisonRecords(){
  const tbody=document.getElementById("myLiaison-body");
  tbody.innerHTML=skeletonRows(15,6);
  try{
    const res=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"getLiaisonRecords"})}).then(r=>r.json());
    const data=Array.isArray(res)?res:(res.records||[]);
    const avatarMap=Array.isArray(res)?{}:(res.avatars||{});
    myLiaisonData=data;
    myLiaisonData.forEach(r=>{r.uploader_avatar=r.created_by?(avatarMap[r.created_by]||null):null;});
    // Precompute searchable text ONCE per record (imbis na paulit-ulit kada keystroke)
    myLiaisonData.forEach(r=>{r._searchKey=`${r.ra_no||""} ${r.buyer||""} ${r.liaison_name||""} ${r.or_no||""} ${r.subd||""} ${r.date_received||""}`.toUpperCase();});
    myLiaisonCurrentPage=1;
    myLiaisonApplyFilter();
  }catch(e){tbody.innerHTML='<tr><td colspan="15" class="empty-state" style="color:#f87171">Error loading.</td></tr>';}
}

function myLiaisonSearch(){clearTimeout(myLiaisonSearchTimer);myLiaisonSearchTimer=setTimeout(()=>{myLiaisonCurrentPage=1;myLiaisonApplyFilter();},120);}

function myLiaisonApplyFilter(){
  const q=(document.getElementById("myliaison-search").value||"").toUpperCase().trim();
  myLiaisonFiltered=!q?myLiaisonData:myLiaisonData.filter(r=>(r._searchKey||"").includes(q));
  myLiaisonTotalPages=Math.max(1,Math.ceil(myLiaisonFiltered.length/MYLIAISON_PAGE_SIZE));
  if(myLiaisonCurrentPage>myLiaisonTotalPages)myLiaisonCurrentPage=myLiaisonTotalPages;
  renderMyLiaisonTable();
}

function myLiaisonGoPage(p){if(p<1||p>myLiaisonTotalPages)return;myLiaisonCurrentPage=p;renderMyLiaisonTable();}

function renderMyLiaisonTable(){
  const tbody=document.getElementById("myLiaison-body");
  const bar=document.getElementById("myliaisonPaginationBar");
  let totalAmt=0;myLiaisonData.forEach(r=>totalAmt+=parseFloat(r.amount||0));
  document.getElementById("myliaison-count").textContent=myLiaisonData.length;
  document.getElementById("myliaison-total").textContent="₱"+totalAmt.toLocaleString(undefined,{minimumFractionDigits:2});
  if(!myLiaisonFiltered.length){
    tbody.innerHTML=`<tr><td colspan="16" class="empty-state">${myLiaisonData.length?"No matches found.":'No records yet. Click "+ Add Record".'}</td></tr>`;
    bar.style.display="none";
    return;
  }
  const start=(myLiaisonCurrentPage-1)*MYLIAISON_PAGE_SIZE,pageRows=myLiaisonFiltered.slice(start,start+MYLIAISON_PAGE_SIZE);
  tbody.innerHTML=pageRows.map((r,i)=>`<tr style="${i%2?'background:rgba(255,255,255,0.02)':''}">
    <td>${r.date_requested||"---"}</td><td><span class="liaison-badge">${r.liaison_name||"---"}</span></td><td>${r.ra_no||"---"}</td><td>${r.buyer||"---"}</td>
    <td>${r.subd||"---"}</td><td>${r.td_no||"---"}</td><td>${r.tct||"---"}</td>
    <td style="text-align:right;font-variant-numeric:tabular-nums">₱${parseFloat(r.amount||0).toLocaleString(undefined,{minimumFractionDigits:2})}</td>
    <td>${r.or_no||"---"}</td><td>${r.or_yr_covered||"---"}</td><td style="text-align:right;font-variant-numeric:tabular-nums;color:#f87171;font-weight:600">₱${parseFloat(r.or_amount||0).toLocaleString(undefined,{minimumFractionDigits:2})}</td>
    <td>${r.or_date||"---"}</td>
    <td>${r.status_remarks||r.remarks||"---"}</td>
    <td style="text-align:center">${(()=>{
      const extra=parseInt(r.extra_attachment_count||0);
      const total=(r.image_path?1:0)+extra;
      if(!total) return '<span style="color:var(--muted);font-size:11px">---</span>';
      const icon=r.image_path?(/\.pdf$/i.test(r.image_path)?"📄":"🖼️"):"📎";
      return `<span style="cursor:pointer" title="${total} attachment(s) — click to view" onclick="openLiaisonRecordById(${r.id})">${icon}${total>1?` <b>+${total-1}</b>`:""}</span>`;
    })()}</td>
    <td style="white-space:nowrap">${r.created_by?`<span style="display:inline-flex;align-items:center;gap:6px">${r.uploader_avatar?`<img src="${r.uploader_avatar}" style="width:22px;height:22px;border-radius:50%;object-fit:cover;border:1px solid var(--border,#333)">`:`<span style="width:22px;height:22px;border-radius:50%;background:rgba(59,130,246,.2);color:#60a5fa;font-size:10px;font-weight:700;display:inline-flex;align-items:center;justify-content:center">${r.created_by.charAt(0).toUpperCase()}</span>`}<span style="font-size:12px">${r.created_by}</span></span>`:'<span style="color:var(--muted);font-size:11px">---</span>'}</td>
    <td style="text-align:center;white-space:nowrap"><button class="btn btn-ghost" style="padding:4px 8px;font-size:11px" onclick='openLiaisonModal(${JSON.stringify(r).replace(/'/g,"&apos;")})'>Edit</button>
    <button class="btn btn-ghost" style="padding:4px 8px;font-size:11px" onclick="openLiaisonActivityLog(${r.id},'${(r.ra_no||r.buyer||'').replace(/'/g,"\\'")}')" title="View activity log">🕒</button>
    <button class="btn btn-ghost" style="padding:4px 8px;font-size:11px;color:#f87171" onclick="deleteMyLiaisonRecord(${r.id})">Del</button></td>
  </tr>`).join("");
  bar.style.display=myLiaisonFiltered.length>MYLIAISON_PAGE_SIZE?"flex":"none";
  document.getElementById("myliaisonPageInfo").textContent=`Showing ${start+1}-${Math.min(start+MYLIAISON_PAGE_SIZE,myLiaisonFiltered.length)} of ${myLiaisonFiltered.length}`;
  document.getElementById("myliaisonBtnFirst").disabled=myLiaisonCurrentPage===1;
  document.getElementById("myliaisonBtnPrev").disabled=myLiaisonCurrentPage===1;
  document.getElementById("myliaisonBtnNext").disabled=myLiaisonCurrentPage===myLiaisonTotalPages;
  document.getElementById("myliaisonBtnLast").disabled=myLiaisonCurrentPage===myLiaisonTotalPages;
  const numsEl=document.getElementById("myliaisonPageNumbers");numsEl.innerHTML="";
  const startP=Math.max(1,myLiaisonCurrentPage-2),endP=Math.min(myLiaisonTotalPages,startP+4);
  for(let i=startP;i<=endP;i++){const b=document.createElement("button");b.className="page-num-btn"+(i===myLiaisonCurrentPage?" active":"");b.textContent=i;b.onclick=()=>myLiaisonGoPage(i);numsEl.appendChild(b);}
}

async function deleteMyLiaisonRecord(id){
  if(!await showConfirm("Delete this record?",{title:"Delete Record",okLabel:"Delete",danger:true}))return;
  try{
    const res=await fetch(CLOUD_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"deleteLiaisonRecord",id,changedBy:CURRENT_USER})}).then(r=>r.json());
    if(res.error){alert(res.error);return;}
    loadMyLiaisonRecords();
    if(typeof subdivmonNotifyDataChanged==="function")subdivmonNotifyDataChanged();
  }catch(e){alert("Error deleting record.");}
}

async function liaisonExportExcel(){
  if(typeof ExcelJS==="undefined"){alert("Excel export library not loaded yet. Please try again.");return;}
  const rows=(myLiaisonFiltered&&myLiaisonFiltered.length)?myLiaisonFiltered:myLiaisonData;
  if(!rows.length){alert("No data to export.");return;}
  const columns=[
    {header:"ID",key:"id",width:6},
    {header:"Date Requested",key:"date_requested",width:14},
    {header:"Liaison",key:"liaison_name",width:12},
    {header:"RA#",key:"ra_no",width:18},
    {header:"Buyer",key:"buyer",width:24},
    {header:"Subd",key:"subd",width:8},
    {header:"Ph",key:"ph",width:6},
    {header:"Blk",key:"blk",width:6},
    {header:"Lot",key:"lot",width:6},
    {header:"Description",key:"description",width:18},
    {header:"TCT",key:"tct",width:20},
    {header:"PIN#",key:"pin_no",width:16},
    {header:"TD#",key:"td_no",width:16},
    {header:"Yr Covered",key:"yr_covered",width:10},
    {header:"Amount",key:"amount",width:12},
    {header:"Owner",key:"owner",width:22},
    {header:"Remarks",key:"remarks",width:20},
    {header:"OR#",key:"or_no",width:12},
    {header:"OR Yr Covered",key:"or_yr_covered",width:14},
    {header:"OR Amount",key:"or_amount",width:12},
    {header:"OR Date",key:"or_date",width:12},
    {header:"Date Received",key:"date_received",width:14},
    {header:"Status / Remarks",key:"status_remarks",width:22},
    {header:"Attachment",key:"image_path",width:30},
    {header:"Created By",key:"created_by",width:14},
    {header:"Date Saved",key:"date_saved",width:16}
  ];
  const data=rows.map(r=>({
    id:r.id||"",date_requested:r.date_requested||"",liaison_name:r.liaison_name||"",ra_no:r.ra_no||"",
    buyer:r.buyer||"",subd:r.subd||"",ph:r.ph||"",blk:r.blk||"",lot:r.lot||"",description:r.description||"",
    tct:r.tct||"",pin_no:r.pin_no||"",td_no:r.td_no||"",yr_covered:r.yr_covered||"",amount:parseFloat(r.amount||0),
    owner:r.owner||"",remarks:r.remarks||"",or_no:r.or_no||"",or_yr_covered:r.or_yr_covered||"",
    or_amount:parseFloat(r.or_amount||0),or_date:r.or_date||"",date_received:r.date_received||"",
    status_remarks:r.status_remarks||"",image_path:r.image_path||"",created_by:r.created_by||"",date_saved:r.date_saved||""
  }));
  const wb=await buildLogoWorkbook("RPT Liaison Report",columns,data);
  const stamp=new Date().toISOString().slice(0,10);
  const buf=await wb.xlsx.writeBuffer();
  saveAs(new Blob([buf],{type:"application/octet-stream"}),`Liaison_Requests_${stamp}.xlsx`);
}

function liaisonPDF(){const{jsPDF}=window.jspdf;if(!jsPDF)return;const doc=new jsPDF("l","mm","a4");doc.setFontSize(11);doc.setTextColor(40,40,40);doc.text("Sta. Lucia Realty & Development, Inc. / Sta. Lucia Land, Inc.",14,10);doc.setFontSize(18);doc.setTextColor(26,115,232);doc.text("RPT Liaison Report",14,19);doc.setFontSize(10);doc.setTextColor(100,100,100);doc.text(`Generated: ${new Date().toLocaleString()}`,14,26);doc.autoTable({html:"#myLiaisonTable",startY:33,theme:"grid",headStyles:{fillColor:[26,115,232]},styles:{fontSize:8}});doc.save("Liaison_Report.pdf");}