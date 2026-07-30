// ============================================
// subdivmon.js — Subdivision Monitor
// Tree view: Subdivision > Block > Lot > Liaison history
// Liaison records are auto-linked server-side (see api/subdivmon.php)
// whenever a Liaison record is saved.
// ============================================

const SUBDIVMON_PAGE_SIZE = 75;
let subdivmonCurrentPage = 1;
let subdivmonTotalPages = 1;
let subdivmonSearchTimer;
let subdivmonMunicipalsLoaded = false;
let subdivmonDelegationBound = false;
let subdivmonCurrentLot = null;
let subdivmonCurrentOrHistory = [];

// Delegated click handler (isang beses lang naka-bind sa tbody, hindi
// umaasa sa inline onclick="" — mas reliable ito kahit paulit-ulit
// nagre-render ang innerHTML ng table).
function subdivmonBindTableDelegation() {
  if (subdivmonDelegationBound) return;
  const tbody = document.getElementById("subdivmon-body");
  if (!tbody) return;
  tbody.addEventListener("click", function (ev) {
    const target = ev.target.closest("[data-lot-id]");
    if (!target) return;
    const lotId = parseInt(target.getAttribute("data-lot-id"), 10);
    if (!lotId) return;
    console.log("[subdivmon] opening lot detail for id:", lotId);
    subdivmonOpenLot(lotId);
  });
  subdivmonDelegationBound = true;
}

// Tinatawag mula sa Liaison at OR Issuance modules pagkatapos mag-save/delete
// ng record, para awtomatikong mag-refresh ang Subdivision Monitor grid
// (RPT Updated / ORS columns) kahit hindi pa nagpa-Refresh manually ang user.
function subdivmonNotifyDataChanged() {
  sectionState.subdivmon.loaded = false;
  if (typeof currentView !== "undefined" && currentView === "subdivmon") {
    const municipal = document.getElementById("subdivmon-municipal-filter");
    const subd = document.getElementById("subdivmon-subd-filter");
    if (municipal && subd && municipal.value && subd.value) {
      _doSubdivmonSearch();
    }
  }
}

async function subdivmonLoadMunicipals() {
  if (subdivmonMunicipalsLoaded) return;
  const sel = document.getElementById("subdivmon-municipal-filter");
  try {
    const res = await fetch(CLOUD_URL, {method:"POST", headers:{"Content-Type":"application/json"}, body:JSON.stringify({action:"getSubdivisionMonitorMunicipals"})});
    const data = await res.json();
    if (data.error) {
      console.error("[subdivmon] getSubdivisionMonitorMunicipals error:", data.error);
      document.getElementById("subdivmon-debug").textContent = "⚠️ Municipal filter failed to load: " + data.error;
      return;
    }
    (data.municipals || []).forEach(m => {
      const opt = document.createElement("option");
      opt.value = m; opt.textContent = m;
      sel.appendChild(opt);
    });
    subdivmonMunicipalsLoaded = true;
  } catch (e) {
    console.error("[subdivmon] Failed to load municipals:", e);
    document.getElementById("subdivmon-debug").textContent = "⚠️ Municipal filter failed to load: " + e.message;
  }
}

// Cascading dropdown: kapag pumili ng Municipal, i-populate ang Subdivision
// dropdown gamit lang ang mga subdivision sa loob ng municipal na iyon —
// hindi agad nagse-search hangga't wala pang napiling Subdivision, para
// hindi lahat ng lots ang lumalabas agad.
async function subdivmonOnMunicipalChange() {
  const municipal = document.getElementById("subdivmon-municipal-filter").value;
  const subdSel = document.getElementById("subdivmon-subd-filter");
  subdSel.innerHTML = '<option value="">Loading…</option>';
  subdSel.disabled = true;

  const statusSel = document.getElementById("subdivmon-f-status");
  if (statusSel) statusSel.innerHTML = '<option value="">All</option>';

  const tbody = document.getElementById("subdivmon-body");
  tbody.innerHTML = '<tr><td colspan="11" class="empty-state">Piliin ang Subdivision.</td></tr>';
  document.getElementById("subdivmon-count").textContent = "0";
  document.getElementById("subdivmonPaginationBar").style.display = "none";

  if (!municipal) {
    subdSel.innerHTML = '<option value="">Select Municipal first…</option>';
    tbody.innerHTML = '<tr><td colspan="11" class="empty-state">Piliin ang Municipal at Subdivision para makita ang mga lots.</td></tr>';
    return;
  }

  try {
    const data = await fetch(CLOUD_URL, {method:"POST", headers:{"Content-Type":"application/json"}, body:JSON.stringify({action:"getSubdivisionMonitorSubdivisions", municipal})}).then(r=>r.json());
    const subds = data.subdivisions || [];
    subdSel.innerHTML = '<option value="">Select Subdivision…</option>' + subds.map(s => `<option value="${subdivmonAttr(s)}">${subdivmonEsc2(s)}</option>`).join("");
    subdSel.disabled = false;
  } catch (e) {
    subdSel.innerHTML = '<option value="">Failed to load</option>';
    document.getElementById("subdivmon-debug").textContent = "⚠️ Subdivision list failed to load: " + e.message;
  }
}

// Flat search + pagination — parehong pattern ng Lot Master List (lots.php):
// isang search bar (Code/TD/Status/TD-Record/Municipal/Subdivision) sa itaas,
// flat results table sa ilalim, walang tree/drill-down. Kailangan munang
// mapili ang Municipal + Subdivision bago tumakbo ang search (para hindi
// lahat ng libu-libong lots ang lumalabas agad).
function subdivmonSearch() {
  clearTimeout(subdivmonSearchTimer);
  subdivmonSearchTimer = setTimeout(() => subdivmonGoPage(1), 300);
}

// I-populate ang Status dropdown gamit lang ang mga status na talagang
// nasa database para sa napiling Subdivision — iwas mali/kulang na resulta
// dahil sa typo o hindi eksaktong text (dating free-text field ito).
let subdivmonStatusLoadToken = 0;
async function subdivmonLoadStatuses(subd) {
  const sel = document.getElementById("subdivmon-f-status");
  if (!sel) return;
  const myToken = ++subdivmonStatusLoadToken;
  const prevValue = sel.value;
  sel.innerHTML = '<option value="">All</option>';
  if (!subd) return;
  try {
    const data = await fetch(CLOUD_URL, {method:"POST", headers:{"Content-Type":"application/json"}, body:JSON.stringify({action:"getSubdivisionMonitorStatuses", subd})}).then(r=>r.json());
    if (myToken !== subdivmonStatusLoadToken) return; // luma na ang response (nag-iba na ang subdivision)
    const statuses = data.statuses || [];
    sel.innerHTML = '<option value="">All</option>' + statuses.map(s => `<option value="${subdivmonAttr(s)}">${subdivmonEsc2(s)}</option>`).join("");
    if (statuses.includes(prevValue)) sel.value = prevValue;
  } catch (e) {
    console.error("[subdivmon] Failed to load statuses:", e);
  }
}

function subdivmonGoPage(p) {
  if (p < 1) return;
  if (p > subdivmonTotalPages) p = subdivmonTotalPages;
  subdivmonCurrentPage = p;
  _doSubdivmonSearch();
}

async function _doSubdivmonSearch() {
  subdivmonBindTableDelegation();
  await subdivmonLoadMunicipals();

  const municipal = document.getElementById("subdivmon-municipal-filter").value;
  const subd = document.getElementById("subdivmon-subd-filter").value;
  const tbody = document.getElementById("subdivmon-body");
  const bar = document.getElementById("subdivmonPaginationBar");

  if (!municipal || !subd) {
    tbody.innerHTML = '<tr><td colspan="11" class="empty-state">Piliin ang Municipal at Subdivision para makita ang mga lots.</td></tr>';
    document.getElementById("subdivmon-count").textContent = "0";
    bar.style.display = "none";
    document.getElementById("subdivmon-debug").textContent = "System ready.";
    return;
  }

  const code = document.getElementById("subdivmon-f-code").value.trim();
  const tdNo = document.getElementById("subdivmon-f-td").value.trim();
  const status = document.getElementById("subdivmon-f-status").value.trim();
  const hasTdRecord = document.getElementById("subdivmon-f-hastd").value;

  tbody.innerHTML = skeletonRows(10, 11);
  document.getElementById("subdivmon-debug").textContent = "⚡ Loading...";
  sectionState.subdivmon.loaded = true;

  try {
    const data = await fetch(CLOUD_URL, {method:"POST", headers:{"Content-Type":"application/json"}, body:JSON.stringify({
      action: "searchSubdivisionMonitorLots",
      code, tdNo, status, hasTdRecord, municipal, subd,
      page: subdivmonCurrentPage, pageSize: SUBDIVMON_PAGE_SIZE
    })}).then(r=>r.json());

    if (data.error) {
      tbody.innerHTML = `<tr><td colspan="11" class="empty-state" style="color:#f87171">${data.error}</td></tr>`;
      document.getElementById("subdivmon-debug").textContent = "Error.";
      bar.style.display = "none";
      return;
    }

    const lots = data.lots || [];
    subdivmonTotalPages = data.totalPages || 1;
    subdivmonCurrentPage = data.page || 1;
    document.getElementById("subdivmon-count").textContent = data.total || 0;

    tbody.innerHTML = lots.length ? lots.map(l => {
      const phBlkLot = [l.ph, l.blk, l.lot].filter(v => v !== null && v !== undefined && v !== "").join("/") || "---";
      const statusRaw = (l.status || "").trim();
      const statusLabel = statusRaw ? (l.latestOrDate ? `${statusRaw} ${l.latestOrDate}` : statusRaw) : "---";
      return `<tr>
        <td style="font-weight:600">${l.raNumber || "---"}</td>
        <td>${phBlkLot}</td>
        <td>${l.tdNo || "---"}</td>
        <td>${l.previousTdNo || "---"}</td>
        <td>${l.tctNo || "---"}</td>
        <td>${l.previousTctNo || "---"}</td>
        <td style="text-align:right">${l.assessedValue ? "₱" + Number(l.assessedValue).toLocaleString(undefined,{minimumFractionDigits:2}) : "---"}</td>
        <td>${statusRaw ? `<span class="liaison-badge" style="background:rgba(248,113,113,.12);color:#f87171">${subdivmonEsc2(statusLabel)}</span>` : "---"}</td>
        <td>${l.rptUpdated || "---"}</td>
        <td class="sdm-or-cell" data-lot-id="${l.id}" style="text-align:center;cursor:pointer;text-decoration:${l.orCount?'underline':'none'}" title="Click to view OR History">${l.orCount ? `<span class="liaison-badge" style="background:rgba(59,130,246,.12);color:#60a5fa">${l.orCount} OR${l.orCount>1?"s":""}</span>` : '<span style="color:var(--muted)">0</span>'}</td>
        <td style="text-align:center"><button class="btn btn-ghost sdm-view-btn" data-lot-id="${l.id}" style="padding:4px 10px;font-size:11px">View / Edit</button></td>
      </tr>`;
    }).join("") : '<tr><td colspan="11" class="empty-state">No results.</td></tr>';

    document.getElementById("subdivmon-debug").textContent = lots.length
      ? `Showing page ${subdivmonCurrentPage} of ${subdivmonTotalPages} (${data.total} total lot${data.total===1?"":"s"}).`
      : "No results.";

    if (!lots.length && subdivmonCurrentPage === 1) { bar.style.display = "none"; return; }
    bar.style.display = "flex";
    const from = (subdivmonCurrentPage-1)*SUBDIVMON_PAGE_SIZE+1, to = Math.min(subdivmonCurrentPage*SUBDIVMON_PAGE_SIZE, data.total||0);
    document.getElementById("subdivmonPageInfo").textContent = `Showing ${from}–${to} of ${data.total||0} lots`;
    document.getElementById("subdivmonBtnFirst").disabled = subdivmonCurrentPage===1;
    document.getElementById("subdivmonBtnPrev").disabled = subdivmonCurrentPage===1;
    document.getElementById("subdivmonBtnNext").disabled = subdivmonCurrentPage===subdivmonTotalPages;
    document.getElementById("subdivmonBtnLast").disabled = subdivmonCurrentPage===subdivmonTotalPages;
    const nums = document.getElementById("subdivmonPageNumbers"); nums.innerHTML = "";
    let startP = Math.max(1, subdivmonCurrentPage-2), endP = Math.min(subdivmonTotalPages, startP+4);
    if (endP-startP<4) startP = Math.max(1, endP-4);
    for (let i=startP; i<=endP; i++) {
      const b = document.createElement("button");
      b.className = "page-num-btn" + (i===subdivmonCurrentPage ? " active" : "");
      b.textContent = i;
      b.onclick = () => subdivmonGoPage(i);
      nums.appendChild(b);
    }
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="11" class="empty-state" style="color:#f87171">Failed to load: ${e.message}</td></tr>`;
    document.getElementById("subdivmon-debug").textContent = "Error.";
    bar.style.display = "none";
  }
}

function subdivmonAttr(s) { return String(s).replace(/"/g, "&quot;"); }

function subdivmonEsc2(s) {
  return String(s).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;");
}

async function subdivmonOpenLot(lotInventoryId) {
  const modal = document.getElementById("subdivmonLotModal");
  const info = document.getElementById("subdivmonLotInfo");
  const coverage = document.getElementById("subdivmonRptCoverage");
  const body = document.getElementById("subdivmonLiaisonBody");
  info.innerHTML = '<div class="skel skel-bar"></div>';
  coverage.innerHTML = "";
  body.innerHTML = skeletonRows(8, 7);
  modal.classList.add("show");
  try {
    const data = await fetch(CLOUD_URL, {method:"POST", headers:{"Content-Type":"application/json"}, body:JSON.stringify({action:"getSubdivisionMonitorLotDetail", lotInventoryId})}).then(r=>r.json());
    if (data.error) { info.innerHTML = `<p style="color:#f87171">${data.error}</p>`; coverage.innerHTML = ""; body.innerHTML = ""; return; }
    const lot = data.lot || {};
    const recs = data.orHistory || [];
    subdivmonCurrentLot = lot;
    subdivmonCurrentOrHistory = recs;

    document.getElementById("subdivmonLotModalTitle").textContent = `OR History — ${lot.ra_number || "---"}`;
    document.getElementById("subdivmonLotModalSub").textContent = `TD No.: ${lot.td_no_latest || lot.td_no_old || "---"}`;

    const fields = [
      ["Code", lot.code], ["Subdivision", lot.subdivision || lot.sub], ["Phase", lot.ph], ["Block", lot.blk], ["Lot", lot.lot],
      ["Buyer's Name", lot.buyers_name], ["Lot Owner", lot.lot_owner], ["Lot Status", lot.status],
      ["TCT No.", lot.tct_no], ["Previous TCT No.", lot.transferred_tct], ["PIN No.", lot.pin_no],
      ["Assessed Value", subdivmonAssessedValue(lot)],
    ];
    info.innerHTML = fields.map(([label, val]) => `<div style="background:var(--surface2,#181818);border:1px solid var(--border,#2a2a2a);border-radius:6px;padding:8px 10px"><div style="color:var(--muted);font-size:10.5px;text-transform:uppercase;letter-spacing:.3px">${label}</div><div style="margin-top:2px">${val || "---"}</div></div>`).join("");

    // RPT Coverage banner: "Updated up to <latest year>" + "Years paid: ..."
    const yearsCovered = [...new Set(recs.map(r => r.sortYear).filter(Boolean))].sort((a,b)=>a-b);
    const latestYear = yearsCovered.length ? yearsCovered[yearsCovered.length - 1] : null;
    coverage.innerHTML = `
      <div><strong>RPT Coverage:</strong> ${latestYear ? `Updated up to <strong>${subdivmonEsc2(latestYear)}</strong>` : "No coverage data yet"}</div>
      <div>•</div>
      <div>Years paid: ${yearsCovered.length ? subdivmonEsc2(yearsCovered.join(", ")) : "---"}</div>
    `;

    body.innerHTML = recs.length ? recs.map(r => {
      const orNo = r.orNumber ? subdivmonEsc2(r.orNumber) : "---";
      const actions = r.editable && r.id
        ? `<button class="btn btn-ghost" style="padding:3px 6px;font-size:10.5px" onclick='openLotOrHistoryEditModal(${JSON.stringify(r).replace(/'/g,"&apos;")})'>Edit</button>
           <button class="btn btn-ghost" style="padding:3px 6px;font-size:10.5px;color:#f87171" onclick="deleteLotOrHistoryUI(${r.id},${r.lotInventoryId})">Del</button>`
        : `<span style="color:var(--muted);font-size:11px">—</span>`;
      return `<tr>
        <td style="font-weight:600">${orNo}</td>
        <td>${r.date || "---"}</td>
        <td>${r.yearCovered || "---"}</td>
        <td>${subdivmonEsc2(r.particulars || "Real Property Tax")}</td>
        <td style="text-align:right">${r.amount ? "₱" + Number(r.amount).toLocaleString(undefined,{minimumFractionDigits:2}) : "₱0.00"}</td>
        <td><span class="liaison-badge" style="background:rgba(74,222,128,.12);color:#4ade80">${subdivmonEsc2(r.modeMc || "Cash")}</span></td>
        <td style="text-align:center">${r.hasFile ? '📎' : '—'}</td>
        <td style="text-align:center;white-space:nowrap;display:flex;gap:4px;justify-content:center">${actions}</td>
      </tr>`;
    }).join("") : '<tr><td colspan="8" class="empty-state">No OR history yet for this lot.</td></tr>';
  } catch (e) {
    info.innerHTML = `<p style="color:#f87171">Failed to load: ${e.message}</p>`;
    coverage.innerHTML = "";
    body.innerHTML = "";
  }
}

// Assessed Value: galing sa lot_inventory.assessed_value (na-populate ng
// import_crc_assessed_value.php mula sa Calamba - CRC.xlsx). "---" kung
// wala pang na-import na datos para sa specific na lot na ito.
function subdivmonAssessedValue(lot) {
  const v = lot.assessed_value || lot.av;
  return v ? "₱" + Number(v).toLocaleString(undefined,{minimumFractionDigits:2}) : null;
}

// Sinusubukang hanapin ang "MC# ..." o katulad na mode/reference sa
// remarks ng liaison record; kung wala, "Cash" ang default (parehong
// convention ng dating "OR History" reference na ipinakita).
function subdivmonExtractModeMc(remarks) {
  if (!remarks) return "Cash";
  const m = String(remarks).match(/MC#\s*[\w-]+/i);
  return m ? m[0].toUpperCase() : "Cash";
}

function closeSubdivmonLotModal() {
  document.getElementById("subdivmonLotModal").classList.remove("show");
}

// ============================================
// Import Lot Data: direktang mag-i-import ng lot_details.csv at
// or_history.csv (galing sa extract_lot_data.py) papunta sa
// import_lot_full.php, nang hindi umaalis sa page na ito.
// ============================================
function subdivmonOpenImportModal() {
  document.getElementById("subdivmon-import-result").textContent = "";
  document.getElementById("subdivmon-import-lotdetails").value = "";
  document.getElementById("subdivmon-import-orhistory").value = "";
  document.getElementById("subdivmonImportModal").classList.add("show");
}

function subdivmonCloseImportModal() {
  document.getElementById("subdivmonImportModal").classList.remove("show");
}

async function subdivmonImportOne(file, mode, resultEl) {
  const fd = new FormData();
  fd.append("csv", file);
  fd.append("mode", mode);
  fd.append("ajax", "1");
  const res = await fetch("import_lot_full.php", {method: "POST", body: fd}).then(r => r.json());
  if (!res.success) throw new Error(res.error || "Unknown error");
  resultEl.textContent += `✅ ${mode === "lot_details" ? "Lot Details" : "OR History"}: ${res.updated} row(s) updated. ${res.created || 0} bagong lot ang naidagdag sa Lot Inventory. ${res.notFound} hindi na-match/skip.\n`;
  if (res.notFoundList && res.notFoundList.length) {
    resultEl.textContent += "   Sample hindi na-match: " + res.notFoundList.slice(0, 10).join(", ") + "\n";
  }
}

async function subdivmonRunImport() {
  const lotFile = document.getElementById("subdivmon-import-lotdetails").files[0];
  const orFile = document.getElementById("subdivmon-import-orhistory").files[0];
  const resultEl = document.getElementById("subdivmon-import-result");
  const btn = document.getElementById("subdivmon-import-run-btn");

  if (!lotFile && !orFile) { resultEl.textContent = "⚠️ Pumili muna ng file(s) na iu-upload."; return; }

  btn.disabled = true;
  const originalText = btn.textContent;
  resultEl.textContent = "";
  try {
    if (lotFile) {
      btn.textContent = "Importing Lot Details...";
      resultEl.textContent += "⚡ Nag-i-import ng Lot Details...\n";
      await subdivmonImportOne(lotFile, "lot_details", resultEl);
    }
    if (orFile) {
      btn.textContent = "Importing OR History...";
      resultEl.textContent += "⚡ Nag-i-import ng OR History...\n";
      await subdivmonImportOne(orFile, "or_history", resultEl);
    }
    resultEl.textContent += "🎉 Tapos na ang import.\n";
    await subdivmonGoPage(subdivmonCurrentPage);
  } catch (e) {
    resultEl.textContent += "⚠️ Import failed: " + e.message + "\n";
  } finally {
    btn.disabled = false;
    btn.textContent = originalText;
  }
}


// record (needed once so OR#/Status show up in Subdivision Monitor).
// Safe to run multiple times — it's idempotent.
// ============================================
async function subdivmonRelinkAll() {
  const btn = document.getElementById("subdivmon-relink-btn");
  const debug = document.getElementById("subdivmon-debug");
  if (!confirm("I-relink ang lahat ng Liaison records papunta sa Lot Inventory? Isang beses lang ito dapat i-run pero safe siyang paulit-ulitin.")) return;
  btn.disabled = true;
  const originalText = btn.textContent;

  let offset = 0;
  let totalLinked = 0;
  let totalProcessed = 0;
  let grandTotal = null;
  let allUnmatched = [];

  try {
    while (true) {
      btn.textContent = grandTotal ? `🔗 Re-linking... (${totalProcessed}/${grandTotal})` : "🔗 Re-linking...";
      debug.textContent = grandTotal
        ? `⚡ Re-linking... ${totalProcessed}/${grandTotal} processed, ${totalLinked} linked so far.`
        : "⚡ Re-linking Liaison records to Lot Inventory...";

      const data = await fetch(CLOUD_URL, {method:"POST", headers:{"Content-Type":"application/json"}, body:JSON.stringify({action:"relinkAllLiaisonRecords", offset, limit: 500})}).then(r=>r.json());
      if (data.error) { debug.textContent = "⚠️ Re-link failed: " + data.error; break; }

      totalLinked += data.linked || 0;
      totalProcessed += data.processed || 0;
      grandTotal = data.grandTotal;
      if (data.unmatched && data.unmatched.length) allUnmatched = allUnmatched.concat(data.unmatched);

      if (data.done || !data.processed) {
        debug.textContent = `✅ Re-link done: ${totalLinked}/${totalProcessed} Liaison record(s) linked.${allUnmatched.length ? " " + allUnmatched.length + " hindi na-match (walang katugmang lot)." : ""}`;
        if (allUnmatched.length) console.warn("[subdivmon] Unmatched liaison records (no matching lot_inventory row):", allUnmatched);
        await subdivmonGoPage(subdivmonCurrentPage);
        break;
      }
      offset = data.nextOffset;
    }
  } catch (e) {
    debug.textContent = "⚠️ Re-link failed: " + e.message;
  } finally {
    btn.disabled = false;
    btn.textContent = originalText;
  }
}

// ============================================
// Export to Excel — parehong logo (Sta. Lucia Realty / Sta. Lucia Land)
// gamit ang shared buildLogoWorkbook() helper (js/shared.js), tulad ng
// export ng Liaison/Released/SLLI/SLRDI modules.
// ============================================

// Export ng buong Subdivision Monitor list (lahat ng pages, base sa
// kasalukuyang filters — hindi lang yung ipinapakita sa isang page).
async function subdivmonExportExcel() {
  if (typeof ExcelJS === "undefined") { alert("Excel export library not loaded yet. Please try again."); return; }

  const municipal = document.getElementById("subdivmon-municipal-filter").value;
  const subd = document.getElementById("subdivmon-subd-filter").value;
  if (!municipal || !subd) { alert("Piliin muna ang Municipal at Subdivision bago mag-export."); return; }

  const code = document.getElementById("subdivmon-f-code").value.trim();
  const tdNo = document.getElementById("subdivmon-f-td").value.trim();
  const status = document.getElementById("subdivmon-f-status").value.trim();
  const hasTdRecord = document.getElementById("subdivmon-f-hastd").value;

  const debug = document.getElementById("subdivmon-debug");
  const originalDebug = debug.textContent;

  try {
    let allLots = [];
    let page = 1, totalPages = 1;
    do {
      debug.textContent = `⚡ Naghahanda ng export... (page ${page}${totalPages > 1 ? "/" + totalPages : ""})`;
      const data = await fetch(CLOUD_URL, {method:"POST", headers:{"Content-Type":"application/json"}, body:JSON.stringify({
        action: "searchSubdivisionMonitorLots",
        code, tdNo, status, hasTdRecord, municipal, subd,
        page, pageSize: 100
      })}).then(r=>r.json());
      if (data.error) { alert("Export failed: " + data.error); debug.textContent = originalDebug; return; }
      allLots = allLots.concat(data.lots || []);
      totalPages = data.totalPages || 1;
      page++;
    } while (page <= totalPages);

    if (!allLots.length) { alert("Walang data na i-eexport."); debug.textContent = originalDebug; return; }

    // Kunin ang buong OR History ng BAWAT lot (isa-isang tawag sa
    // getSubdivisionMonitorLotDetail, tulad ng ginagawa pag pinipindot
    // ang OR badge), tapos pagsamahin sa IISANG flat na sheet — isang row
    // bawat OR entry, may kasamang lot info sa bawat row (hindi na
    // hiwalay na sheet). Kung walang OR history ang isang lot, isang row
    // pa rin ang ilalagay para dito (blangko na lang ang OR columns).
    const flatRows = [];
    for (let i = 0; i < allLots.length; i++) {
      const l = allLots[i];
      debug.textContent = `⚡ Kinukuha ang OR history... (${i + 1}/${allLots.length})`;
      const lotBase = {
        sub: l.sub || subd, ph: l.ph || "", blk: l.blk || "", lot: l.lot || "",
        raNumber: l.raNumber || "", buyersName: l.buyersName || "", lotOwner: l.lotOwner || "",
        tdNo: l.tdNo || "", tctNo: l.tctNo || "", previousTctNo: l.previousTctNo || "",
        lotStatus: l.lotStatus || "",
        rptUpdated: l.rptUpdated || "",
      };
      try {
        const detail = await fetch(CLOUD_URL, {method:"POST", headers:{"Content-Type":"application/json"}, body:JSON.stringify({
          action: "getSubdivisionMonitorLotDetail", lotInventoryId: l.id
        })}).then(r=>r.json());
        const orHistory = detail.orHistory || [];
        if (!orHistory.length) {
          flatRows.push({...lotBase, orNumber:"", orDate:"", yearCovered:"", particulars:"", amount:"", modeMc:""});
        } else {
          orHistory.forEach(r => {
            flatRows.push({
              ...lotBase,
              orNumber: r.orNumber || "", orDate: r.date || "", yearCovered: r.yearCovered || "",
              particulars: r.particulars || "", amount: parseFloat(r.amount || 0), modeMc: r.modeMc || "",
            });
          });
        }
      } catch (e) {
        console.warn("[subdivmon] Failed to fetch OR history for lot", l.id, e);
        flatRows.push({...lotBase, orNumber:"", orDate:"", yearCovered:"", particulars:"", amount:"", modeMc:""});
      }
    }

    const columns = [
      {header:"Subdivision", key:"sub", width:10},
      {header:"Phase", key:"ph", width:8},
      {header:"Block", key:"blk", width:8},
      {header:"Lot", key:"lot", width:8},
      {header:"RA Number", key:"raNumber", width:16},
      {header:"Buyer's Name", key:"buyersName", width:26},
      {header:"Lot Owner", key:"lotOwner", width:26},
      {header:"TD No.", key:"tdNo", width:16},
      {header:"TCT No.", key:"tctNo", width:16},
      {header:"Previous TCT No.", key:"previousTctNo", width:16},
      {header:"Status", key:"lotStatus", width:16},
      {header:"RPT Updated", key:"rptUpdated", width:12},
      {header:"OR Number", key:"orNumber", width:16},
      {header:"OR Date", key:"orDate", width:16},
      {header:"Year Covered", key:"yearCovered", width:16},
      {header:"Particulars", key:"particulars", width:24},
      {header:"Amount", key:"amount", width:14},
      {header:"Mode / MC No.", key:"modeMc", width:16},
    ];

    const wb = await buildLogoWorkbook("Subdivision Monitor Report", columns, flatRows, {
      subtitle: `Municipal: ${municipal}  •  Subdivision: ${subd}`
    });

    const stamp = new Date().toISOString().slice(0, 10);
    const buf = await wb.xlsx.writeBuffer();
    saveAs(new Blob([buf], {type: "application/octet-stream"}), `Subdivision_Monitor_${subd}_${stamp}.xlsx`);
    debug.textContent = originalDebug;
  } catch (e) {
    alert("Export failed: " + e.message);
    debug.textContent = originalDebug;
  }
}

// Export ng OR History ng isang lot lang (yung bukas na modal)
async function subdivmonExportLotOrHistory() {
  if (typeof ExcelJS === "undefined") { alert("Excel export library not loaded yet. Please try again."); return; }
  const lot = subdivmonCurrentLot;
  const recs = subdivmonCurrentOrHistory;
  if (!lot || !recs || !recs.length) { alert("Walang OR history na i-eexport para sa lot na ito."); return; }

  const columns = [
    {header:"OR Number", key:"orNumber", width:16},
    {header:"Date", key:"date", width:16},
    {header:"Year Covered", key:"yearCovered", width:16},
    {header:"Particulars", key:"particulars", width:24},
    {header:"Amount", key:"amount", width:14},
    {header:"Mode / MC No.", key:"modeMc", width:16},
  ];
  const data = recs.map(r => ({
    orNumber: r.orNumber || "", date: r.date || "", yearCovered: r.yearCovered || "",
    particulars: r.particulars || "", amount: parseFloat(r.amount || 0), modeMc: r.modeMc || "",
  }));

  const subtitle = `RA#: ${lot.ra_number || "---"}  •  Subd/Ph/Blk/Lot: ${lot.sub || ""}/${lot.ph || ""}/${lot.blk || ""}/${lot.lot || ""}  •  TD No.: ${lot.td_no_latest || lot.td_no_old || "---"}`;
  const wb = await buildLogoWorkbook("OR History Report", columns, data, { subtitle });
  const stamp = new Date().toISOString().slice(0, 10);
  const buf = await wb.xlsx.writeBuffer();
  saveAs(new Blob([buf], {type: "application/octet-stream"}), `OR_History_${(lot.ra_number || "Lot")}_${stamp}.xlsx`);
}
// ============================================
// Update from Excel: binabasa ang EKSAKTONG parehong .xlsx na ginawa ng
// "Export to Excel" (pagkatapos i-edit ng user sa Excel), tapos ini-
// uupdate ang mga matching lot (RA# muna, fallback Subd/Ph/Blk/Lot) sa
// database — WALANG bagong lot na ginagawa dito, update lang. Ginagamit
// ang ExcelJS na naka-load na sa page (parehong library ng export).
// ============================================
function subdivmonUpdateFromExcel() {
  document.getElementById("subdivmon-update-excel-input").value = "";
  document.getElementById("subdivmon-update-excel-input").click();
}

async function subdivmonHandleUpdateExcel(event) {
  const file = event.target.files[0];
  if (!file) return;

  const debug = document.getElementById("subdivmon-debug");
  const originalDebug = debug ? debug.textContent : "";
  if (debug) debug.textContent = "⚡ Binabasa ang Excel file...";

  try {
    const buf = await file.arrayBuffer();
    const wb = new ExcelJS.Workbook();
    await wb.xlsx.load(buf);
    const ws = wb.worksheets[0];
    if (!ws) throw new Error("Walang worksheet na nahanap sa file.");

    // Hanapin ang header row (yung row na may "RA Number" sa isa sa mga cell) —
    // hindi kinukuha ang fixed row number dahil pwedeng nagbago ang layout
    // (logo/subtitle rows) sa binagong Excel.
    let headerRowIdx = null, headerMap = {};
    const wanted = {
      "subdivision": "sub", "phase": "ph", "block": "blk", "lot": "lot",
      "ra number": "raNumber", "buyer's name": "buyersName", "lot owner": "lotOwner",
      "td no.": "tdNo", "previous td no.": "previousTdNo", "tct no.": "tctNo",
      "previous tct no.": "previousTctNo", "assessed value": "assessedValue",
      "status": "lotStatus", "or number": "orNumber", "or date": "orDate",
      "year covered": "yearCovered", "particulars": "particulars",
      "amount": "amount", "mode / mc no.": "modeMc",
    };
    for (let r = 1; r <= Math.min(ws.rowCount, 20); r++) {
      const row = ws.getRow(r);
      let hits = 0;
      const map = {};
      row.eachCell((cell, colNumber) => {
        const label = String(cell.value || "").trim().toLowerCase();
        if (wanted[label]) { map[wanted[label]] = colNumber; hits++; }
      });
      if (hits >= 5) { headerRowIdx = r; headerMap = map; break; }
    }
    if (!headerRowIdx) throw new Error("Hindi mahanap ang header row (siguraduhing hindi binago ang column names).");

    const rows = [];
    for (let r = headerRowIdx + 1; r <= ws.rowCount; r++) {
      const row = ws.getRow(r);
      if (row.cellCount === 0) continue;
      const get = (key) => {
        const col = headerMap[key];
        if (!col) return "";
        const v = row.getCell(col).value;
        if (v === null || v === undefined) return "";
        if (typeof v === "object" && v.result !== undefined) return String(v.result);
        return String(v).trim();
      };
      const sub = get("sub");
      if (!sub) continue; // laktawan ang blangkong row
      rows.push({
        sub, ph: get("ph"), blk: get("blk"), lot: get("lot"),
        raNumber: get("raNumber"), buyersName: get("buyersName"), lotOwner: get("lotOwner"),
        tdNo: get("tdNo"), previousTdNo: get("previousTdNo"), tctNo: get("tctNo"),
        previousTctNo: get("previousTctNo"), assessedValue: get("assessedValue"),
        lotStatus: get("lotStatus"), orNumber: get("orNumber"), orDate: get("orDate"),
        yearCovered: get("yearCovered"), particulars: get("particulars"),
        amount: get("amount"), modeMc: get("modeMc"),
      });
    }

    if (!rows.length) throw new Error("Walang laman na row na nahanap sa file.");

    const CHUNK_SIZE = 500;
    let lotsUpdated = 0, orUpdated = 0, notFound = 0;
    const notFoundList = [];
    for (let i = 0; i < rows.length; i += CHUNK_SIZE) {
      const chunk = rows.slice(i, i + CHUNK_SIZE);
      if (debug) debug.textContent = `⚡ Nag-a-update... (${Math.min(i + CHUNK_SIZE, rows.length)}/${rows.length} row(s))`;
      const res = await fetch(CLOUD_URL, {
        method: "POST", headers: {"Content-Type": "application/json"},
        body: JSON.stringify({ action: "importSubdivisionMonitorUpdate", rows: chunk })
      }).then(r => r.json());
      if (res.error) throw new Error(res.error + ` (nangyari sa batch ${Math.floor(i / CHUNK_SIZE) + 1})`);
      lotsUpdated += res.lotsUpdated || 0;
      orUpdated += res.orUpdated || 0;
      notFound += res.notFound || 0;
      if (res.notFoundList) notFoundList.push(...res.notFoundList);
    }

    let msg = `🎉 Tapos na ang update: ${lotsUpdated} lot na na-update, ${orUpdated} OR entry na na-update/dagdag.`;
    if (notFound) {
      msg += ` ⚠️ ${notFound} row ang hindi na-match sa Lot Inventory.`;
      if (notFoundList.length) {
        msg += " Sample: " + notFoundList.slice(0, 10).join(", ");
      }
    }
    alert(msg);
    if (debug) debug.textContent = originalDebug;
    await subdivmonGoPage(subdivmonCurrentPage);
  } catch (e) {
    alert("Update failed: " + e.message);
    if (debug) debug.textContent = originalDebug;
  } finally {
    event.target.value = "";
  }
}
// ============================================
// Edit / Delete ng na-import na OR history record (lot_or_history)
// ============================================

function openLotOrHistoryEditModal(rec) {
  let modal = document.getElementById("lotOrHistoryEditModal");
  if (!modal) {
    modal = document.createElement("div");
    modal.className = "modal-overlay";
    modal.id = "lotOrHistoryEditModal";
    modal.innerHTML = `
      <div class="modal" style="max-width:420px;width:92vw">
        <div class="modal-header">
          <h3 style="font-size:16px;margin:0">Edit OR History Record</h3>
          <button class="modal-close" onclick="closeLotOrHistoryEditModal()">✕</button>
        </div>
        <div class="form-grid" style="padding:14px 18px;display:grid;grid-template-columns:1fr 1fr;gap:10px 12px">
          <input type="hidden" id="loh-id">
          <input type="hidden" id="loh-lotInventoryId">
          <div class="field"><label>Year Covered</label><input type="number" id="loh-yr"></div>
          <div class="field"><label>OR Number</label><input type="text" id="loh-orNumber"></div>
          <div class="field"><label>From</label><input type="text" id="loh-fr"></div>
          <div class="field"><label>To</label><input type="text" id="loh-to"></div>
          <div class="field"><label>Amount</label><input type="number" step="0.01" id="loh-amount"></div>
          <div class="field"><label>OR Date</label><input type="text" id="loh-orDate" placeholder="YYYY-MM-DD"></div>
          <div class="field" style="grid-column:span 2"><label>MC# / Liaison</label><input type="text" id="loh-mcLiaison"></div>
          <div class="field" style="grid-column:span 2"><label>Remarks / Particulars</label><input type="text" id="loh-remarks"></div>
        </div>
        <div style="padding:12px 18px;display:flex;justify-content:flex-end;gap:8px;border-top:1px solid var(--border)">
          <button class="btn btn-ghost" onclick="closeLotOrHistoryEditModal()">Cancel</button>
          <button class="btn btn-primary" onclick="saveLotOrHistoryUI()">Save</button>
        </div>
      </div>`;
    document.body.appendChild(modal);
  }
  document.getElementById("loh-id").value = rec.id || "";
  document.getElementById("loh-lotInventoryId").value = rec.lotInventoryId || "";
  document.getElementById("loh-yr").value = rec.yr || "";
  document.getElementById("loh-fr").value = rec.fr || "";
  document.getElementById("loh-to").value = rec.to || "";
  document.getElementById("loh-orNumber").value = rec.orNumber || "";
  document.getElementById("loh-amount").value = rec.amount != null ? rec.amount : "";
  document.getElementById("loh-orDate").value = rec.date || "";
  document.getElementById("loh-mcLiaison").value = rec.modeMc || "";
  document.getElementById("loh-remarks").value = rec.remarks || "";
  modal.classList.add("show");
}

function closeLotOrHistoryEditModal() {
  const modal = document.getElementById("lotOrHistoryEditModal");
  if (modal) modal.classList.remove("show");
}

async function saveLotOrHistoryUI() {
  const id = document.getElementById("loh-id").value;
  const lotInventoryId = document.getElementById("loh-lotInventoryId").value;
  const body = {
    action: "updateLotOrHistory",
    id,
    yr: document.getElementById("loh-yr").value,
    fr: document.getElementById("loh-fr").value.trim(),
    to: document.getElementById("loh-to").value.trim(),
    orNumber: document.getElementById("loh-orNumber").value.trim(),
    amount: document.getElementById("loh-amount").value.trim(),
    orDate: document.getElementById("loh-orDate").value.trim(),
    mcLiaison: document.getElementById("loh-mcLiaison").value.trim(),
    remarks: document.getElementById("loh-remarks").value.trim(),
  };
  if (!body.yr) { alert("Year Covered ay required."); return; }
  try {
    const res = await fetch(CLOUD_URL, {method:"POST", headers:{"Content-Type":"application/json"}, body:JSON.stringify(body)}).then(r=>r.json());
    if (res.error) { alert(res.error); return; }
    closeLotOrHistoryEditModal();
    if (typeof showToast === "function") showToast("✅ Na-update ang OR history record!");
    if (lotInventoryId) await subdivmonOpenLot(parseInt(lotInventoryId, 10));
  } catch (e) {
    alert("Error saving record.");
  }
}

async function deleteLotOrHistoryUI(id, lotInventoryId) {
  if (!await showConfirm("Delete this OR history record?", {title:"Delete Record", okLabel:"Delete", danger:true})) return;
  try {
    const res = await fetch(CLOUD_URL, {method:"POST", headers:{"Content-Type":"application/json"}, body:JSON.stringify({action:"deleteLotOrHistory", id})}).then(r=>r.json());
    if (res.error) { alert(res.error); return; }
    if (lotInventoryId) await subdivmonOpenLot(lotInventoryId);
  } catch (e) {
    alert("Error deleting record.");
  }
}