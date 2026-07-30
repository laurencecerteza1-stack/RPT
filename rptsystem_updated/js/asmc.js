// ============================================================================
// AS WITH MC — bagong module. 3 hiwalay na dataset (bawat isa'y may sariling
// database table): RPT Dept, SLLI Liaison, SLRDI Liaison. Basta i-upload
// ang CSV, awtomatikong hahanapin ang tunay na header row (nilalaktawan ang
// mga banner/title row sa taas), tapos ipapasok LAHAT ng laman — walang
// validation o pag-map sa ibang module.
// ============================================================================

const ASMC_DATASET_LABELS = {
  rpt_dept: "AS with MC (RPT Dept.)",
  slli: "SLLI MC (Liaison)",
  slrdi: "SLRDI MC (Liaison)",
};

// Tanging sina ANN at CARL lang ang pwedeng mag-import/mag-add/mag-delete ng
// AS with MC data (full access). Lahat ng iba ay view/search lang BY
// DEFAULT, MALIBAN sa 2 column na nasa ASMC_LIMITED_EDIT_FIELDS — pwede
// nilang i-edit yun (basta naka-login sila), pero locked/di-magagalaw ang
// lahat ng iba pang column.
const ASMC_EDITORS = ["ANN", "CARL"];
const ASMC_LIMITED_EDIT_FIELDS = ["RETURNED OR", "DATE RECORDED"];

function asmcCanEdit() {
  return ASMC_EDITORS.includes(String(CURRENT_USER || "").toUpperCase());
}
// Kahit sinong naka-login (may CURRENT_USER) ay pwedeng mag-"limited edit"
// (RETURNED OR checkbox + DATE RECORDED lang).
function asmcCanLimitedEdit() {
  return String(CURRENT_USER || "").trim() !== "";
}
function asmcIsLimitedEditableField(h) {
  const up = String(h || "").trim().toUpperCase();
  return ASMC_LIMITED_EDIT_FIELDS.some(f => f.toUpperCase() === up);
}
function asmcApplyPermissions() {
  const wrap = document.getElementById("asmc-import-wrap");
  if (wrap) wrap.style.display = asmcCanEdit() ? "flex" : "none";
}
let asmcActiveDataset = "rpt_dept";
const asmcState = {
  rpt_dept: { page: 1, totalPages: 1, headers: [], q: "", combo: "" },
  slli:     { page: 1, totalPages: 1, headers: [], q: "", combo: "" },
  slrdi:    { page: 1, totalPages: 1, headers: [], q: "", combo: "" },
};

function asmcSwitchTab(dataset) {
  asmcActiveDataset = dataset;
  document.querySelectorAll(".asmc-tab").forEach(t => t.classList.toggle("active", t.dataset.asmc === dataset));
  const st = asmcState[dataset];
  document.getElementById("asmc-q").value = st.q || "";
  asmcLoad(1);
}

function asmcIsBoolVal(v) {
  const s = String(v ?? "").trim().toUpperCase();
  return s === "TRUE" || s === "FALSE";
}
function asmcEsc(v) {
  return String(v ?? "").replace(/[&<>"']/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
}
function asmcCellHTML(v, isBoolCol) {
  if (asmcIsBoolVal(v) || (isBoolCol && String(v ?? "").trim() === "")) {
    const checked = String(v).trim().toUpperCase() === "TRUE";
    return `<input type="checkbox" ${checked ? "checked" : ""} onclick="return false" tabindex="-1" style="width:18px;height:18px;accent-color:var(--accent);opacity:1;filter:none;cursor:default;vertical-align:middle">`;
  }
  return asmcEsc(v);
}

// Hanapin ang totoong header row ng isang sheet-as-array-of-arrays: yung
// unang row na may pinaka-maraming di-blangkong cell (>= 6). Nilalaktawan
// nito ang mga banner/title row sa itaas (hal. "STA. LUCIA LAND INC.").
function asmcFindHeaderRow(aoa) {
  let bestIdx = 0, bestCount = -1;
  for (let i = 0; i < Math.min(aoa.length, 15); i++) {
    const nonEmpty = (aoa[i] || []).filter(c => String(c ?? "").trim() !== "").length;
    if (nonEmpty > bestCount) { bestCount = nonEmpty; bestIdx = i; }
  }
  return bestCount >= 6 ? bestIdx : 0;
}

// Ang ibang CSV/xlsx export (hal. "AS with MC - RPT DEPT.") ay may paulit-ulit
// na column header sa isang row (hal. 4x "MC#/CHECK#" para sa MC1-4, 2x
// "REMARKS"). Dahil ang bawat row ay iniimbak/dinidisplay gamit ang header
// TEXT bilang key (hindi index), kailangang gawing unique muna ang mga ito
// bago ipasok, kung hindi ay magsasanib-sanib ang datos ng magkaparehong-
// pangalan na column (mawawala yung ibang value).
function asmcDedupeHeaders(headers) {
  const seen = {};
  return headers.map(h => {
    const base = String(h ?? "").trim();
    if (base === "") return base;
    const key = base.toUpperCase();
    seen[key] = (seen[key] || 0) + 1;
    return seen[key] === 1 ? base : `${base} (${seen[key]})`;
  });
}

async function asmcHandleFileUpload(event, dataset) {
  const file = event.target.files[0];
  event.target.value = ""; // para pwede ulit i-upload yung parehong file
  if (!file) return;

  if (!asmcCanEdit()) {
    showAlertModal("Access denied. Only ANN and CARL can import/edit AS with MC.");
    return;
  }

  const debug = document.getElementById("asmc-debug");
  debug.textContent = `⚡ Reading ${file.name}...`;

  try {
    const text = await file.text();
    const wb = XLSX.read(text, { type: "string" });
    const sheet = wb.Sheets[wb.SheetNames[0]];
    const aoa = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: "", raw: false });

    const headerIdx = asmcFindHeaderRow(aoa);
    const rawHeaders = (aoa[headerIdx] || []).map(h => String(h ?? "").trim());
    const headers = asmcDedupeHeaders(rawHeaders);
    const dataRows = aoa.slice(headerIdx + 1).filter(row => row.some(c => String(c ?? "").trim() !== ""));

    if (!headers.length || !dataRows.length) {
      showAlertModal("No data found in the file. Please make sure the CSV is correct.");
      debug.textContent = "";
      return;
    }

    debug.textContent = `⚡ Importing ${dataRows.length} rows into ${ASMC_DATASET_LABELS[dataset]}...`;

    const res = await fetch(CLOUD_URL, {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "importAsmcDataset", dataset, headers, rows: dataRows, importedBy: CURRENT_USER || "" })
    }).then(r => r.json());

    if (res.error) { showAlertModal("Import failed: " + res.error); debug.textContent = ""; return; }

    debug.textContent = "";
    showAlertModal(`Imported: ${res.imported} rows into ${ASMC_DATASET_LABELS[dataset]}.`);
    asmcSwitchTab(dataset);
  } catch (e) {
    console.error("[asmc] import failed:", e);
    showAlertModal("Import failed: " + e.message);
    debug.textContent = "";
  }
}

let asmcSearchTimer = null;
function asmcSearch() {
  const st = asmcState[asmcActiveDataset];
  // Iisang search box na lang ang ginagamit — sinasagot nito ang pareho:
  // general column search (q) at ang JV#/AS#/MC#/Payee combo filter (combo).
  const val = document.getElementById("asmc-q").value.trim();
  st.q = val;
  st.combo = val;
  clearTimeout(asmcSearchTimer);
  asmcSearchTimer = setTimeout(() => asmcLoad(1), 300);
}

function asmcClearFilters() {
  document.getElementById("asmc-q").value = "";
  asmcSearch();
}

async function asmcLoad(page) {
  const dataset = asmcActiveDataset;
  const st = asmcState[dataset];
  st.page = page || st.page || 1;

  const thead = document.getElementById("asmc-thead");
  const tbody = document.getElementById("asmc-tbody");
  const pageInfo = document.getElementById("asmc-page-info");
  tbody.innerHTML = `<tr><td colspan="20" style="text-align:center;padding:20px;color:var(--muted)">Loading...</td></tr>`;

  try {
    const data = await fetch(CLOUD_URL, {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "listAsmcDataset", dataset, q: st.q, combo: st.combo, page: st.page, pageSize: 50, sort: (document.getElementById("asmc-sort") || {}).value || "latest" })
    }).then(r => r.json());

    if (data.error) { tbody.innerHTML = `<tr><td colspan="20" style="text-align:center;padding:20px;color:var(--danger)">${asmcEsc(data.error)}</td></tr>`; return; }

    st.headers = data.headers || [];
    st.totalPages = data.totalPages || 1;
    st.rows = data.rows || [];

    if (!st.headers.length || !(data.rows || []).length) {
      thead.innerHTML = "";
      tbody.innerHTML = `<tr><td style="text-align:center;padding:30px;color:var(--muted)">No data imported yet for ${asmcEsc(ASMC_DATASET_LABELS[dataset])}. Click "Import CSV" above.</td></tr>`;
      pageInfo.textContent = "";
      return;
    }

    const showActionsCol = asmcCanEdit() || asmcCanLimitedEdit();
    // I-tsek muna ang BUONG page ng data (hindi lang isang row) para malaman
    // kung alin sa mga column ang boolean/checkbox talaga — kung hindi,
    // nawawala ang checkbox sa mga row na blangko lang ang value doon.
    const boolColSet = {};
    st.headers.forEach(h => {
      boolColSet[h] = data.rows.some(r => asmcIsBoolVal(r.data[h]));
    });
    thead.innerHTML = "<tr>" + (showActionsCol ? `<th style="white-space:nowrap;padding:8px 10px;text-align:left">Actions</th>` : "") + st.headers.map(h => `<th style="white-space:nowrap;padding:8px 10px;text-align:left">${asmcEsc(h)}</th>`).join("") + "</tr>";
    tbody.innerHTML = data.rows.map(r =>
      "<tr>" + (showActionsCol ? `<td style="white-space:nowrap;padding:6px 10px"><button class="btn btn-ghost" style="padding:3px 8px;font-size:11px" onclick="asmcOpenEditModal(${r.id})">✏️</button> ${asmcCanEdit() ? `<button class="btn btn-ghost" style="padding:3px 8px;font-size:11px;color:var(--danger)" onclick="asmcDeleteRow(${r.id})">🗑️</button>` : ""}</td>` : "") +
      st.headers.map(h => `<td style="white-space:nowrap;padding:6px 10px">${asmcCellHTML(r.data[h], boolColSet[h])}</td>`).join("") + "</tr>"
    ).join("");

    pageInfo.textContent = `Showing page ${data.page} of ${data.totalPages} (${data.total} total rows)`;
  } catch (e) {
    console.error("[asmc] load failed:", e);
    tbody.innerHTML = `<tr><td colspan="20" style="text-align:center;padding:20px;color:var(--danger)">Failed to load: ${asmcEsc(e.message)}</td></tr>`;
  }
}

function asmcGoPage(delta) {
  const st = asmcState[asmcActiveDataset];
  const newPage = st.page + delta;
  if (newPage < 1 || newPage > st.totalPages) return;
  asmcLoad(newPage);
}

function loadAsmc() {
  sectionState.asmc.loaded = true;
  asmcApplyPermissions();
  asmcSwitchTab(asmcActiveDataset);
}

// ── Add / Edit / Delete ng isang row ──
let asmcEditingId = null;

function asmcHeaderIsBoolColumn(dataset, h) {
  const rows = (asmcState[dataset] || {}).rows || [];
  for (let i = 0; i < rows.length; i++) {
    const v = rows[i].data ? rows[i].data[h] : undefined;
    if (v !== undefined && v !== "" && asmcIsBoolVal(v)) return true;
  }
  return false;
}

function asmcIsASHeader(h) {
  return /AS#/i.test(String(h || ""));
}

// Ang "#" column ay auto-increment (hawak ng ibang proseso/sheet), kaya
// hindi na ito ipinapakita sa Add/Edit Row form.
function asmcIsRowNumberHeader(h) {
  return String(h || "").trim() === "#";
}

// Inaayos ang pagkakasunod-sunod ng mga field sa form: tinatanggal ang "#"
// (auto-increment) at inilalagay ang AS# sa pinaka-unahan.
function asmcOrderedFormHeaders(headers) {
  const filtered = headers.filter(h => !asmcIsRowNumberHeader(h));
  const asIdx = filtered.findIndex(h => asmcIsASHeader(h));
  if (asIdx > 0) {
    const [asHeader] = filtered.splice(asIdx, 1);
    filtered.unshift(asHeader);
  }
  return filtered;
}

function asmcRenderRowFields(headers, values, lockOthers) {
  const dataset = asmcActiveDataset;
  const body = document.getElementById("asmcRowModalBody");
  const orderedHeaders = asmcOrderedFormHeaders(headers);
  body.innerHTML = orderedHeaders.map((h, i) => {
    const raw = values ? (values[h] ?? "") : "";
    const isBool = asmcIsBoolVal(raw) || (!values && asmcHeaderIsBoolColumn(dataset, h));
    const locked = !!lockOthers && !asmcIsLimitedEditableField(h);
    const lockedAttrs = locked ? `disabled title="View only — hindi ito maeedit"` : "";
    const labelHtml = `${asmcEsc(h)}${locked ? ` <span style="opacity:.55">🔒</span>` : ""}`;
    if (isBool) {
      const checked = String(raw).trim().toUpperCase() === "TRUE";
      return `<div class="field"><label>${labelHtml}</label><label style="display:flex;align-items:center;gap:8px;font-weight:400;${locked ? "opacity:.6" : ""}"><input type="checkbox" data-asmc-field="${asmcEsc(h)}" data-asmc-bool="1" style="width:20px;height:20px;accent-color:var(--accent);cursor:${locked ? "not-allowed" : "pointer"}" ${checked ? "checked" : ""} ${lockedAttrs} onchange="this.nextSibling.textContent=this.checked?' TRUE':' FALSE'"><span>${checked ? " TRUE" : " FALSE"}</span></label></div>`;
    }
    const val = asmcEsc(raw);
    if (asmcIsASHeader(h) && !locked) {
      return `<div class="field" style="grid-column:span 1"><label>${labelHtml}</label><input type="text" data-asmc-field="${asmcEsc(h)}" value="${val}" oninput="asmcCheckASHistory(this.value)"><div id="asmc-as-history" style="display:none;margin-top:6px;padding:8px 10px;background:rgba(255,180,0,.08);border:1px solid rgba(255,180,0,.35);border-radius:8px;font-size:11px;color:var(--text);line-height:1.6"></div></div>`;
    }
    return `<div class="field"><label>${labelHtml}</label><input type="text" data-asmc-field="${asmcEsc(h)}" value="${val}" ${lockedAttrs} style="${locked ? "opacity:.6;cursor:not-allowed" : ""}"></div>`;
  }).join("");
  // Kung may laman na ang AS# field pagkabukas ng modal (edit mode), i-check
  // agad kung may kaparehong record.
  const asInput = Array.from(document.querySelectorAll("#asmcRowModalBody [data-asmc-field]")).find(inp => asmcIsASHeader(inp.getAttribute("data-asmc-field")));
  if (asInput && asInput.value.trim()) asmcCheckASHistory(asInput.value);
}

let asmcASHistoryTimer = null;
function asmcCheckASHistory(val) {
  clearTimeout(asmcASHistoryTimer);
  const hist = document.getElementById("asmc-as-history");
  const q = String(val || "").trim();
  if (!hist) return;
  if (!q) { hist.style.display = "none"; hist.innerHTML = ""; return; }
  asmcASHistoryTimer = setTimeout(() => _doAsmcASHistoryCheck(q), 300);
}

let asmcASHistoryMatches = {};
async function _doAsmcASHistoryCheck(q) {
  const hist = document.getElementById("asmc-as-history");
  if (!hist) return;
  hist.style.display = "block";
  hist.innerHTML = "Checking existing records for this AS#...";
  try {
    const res = await fetch(CLOUD_URL, {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "lookupAsmcByAS", dataset: asmcActiveDataset, asValue: q, excludeId: asmcEditingId || 0 })
    }).then(r => r.json());
    const matches = res.matches || [];
    if (!matches.length) {
      asmcASHistoryMatches = {};
      hist.innerHTML = "✓ Walang existing record na may ganitong AS# sa dataset na ito.";
      return;
    }
    const headers = asmcState[asmcActiveDataset].headers || [];
    const payeeHeader = headers.find(h => /PAYEE/i.test(h));
    const particularsHeader = headers.find(h => /PARTICULARS/i.test(h));
    const jvHeader = headers.find(h => /^JV#/i.test(h) || /JV#/i.test(h));
    asmcASHistoryMatches = {};
    const rows = matches.map(m => {
      asmcASHistoryMatches[m.id] = m;
      const d = m.data || {};
      const payee = payeeHeader ? (d[payeeHeader] || "") : "";
      const particulars = particularsHeader ? (d[particularsHeader] || "") : "";
      const jv = jvHeader ? (d[jvHeader] || "") : "";
      const label = `${payee || "(no payee)"}${particulars ? " — " + particulars : ""}${jv ? " — JV# " + jv : ""}`;
      return `<div onclick="asmcEditFromHistory(${m.id})" style="cursor:pointer;text-decoration:underline;padding:2px 0" title="I-click para i-edit ang record na ito">• ${asmcEsc(label)}</div>`;
    }).join("");
    hist.innerHTML = `⚠️ May ${matches.length} existing record(s) na na sa AS# na ito (i-click para i-edit):<br>${rows}`;
  } catch (e) {
    hist.innerHTML = "Hindi na-check ang AS# history (connection error).";
  }
}

function asmcEditFromHistory(id) {
  const match = asmcASHistoryMatches[id];
  if (!match) { showAlertModal("Row not found. Please try searching again."); return; }
  const fullEdit = asmcCanEdit();
  if (!fullEdit && !asmcCanLimitedEdit()) { showAlertModal("Access denied. Please log in to edit AS with MC."); return; }
  const dataset = asmcActiveDataset;
  const st = asmcState[dataset];
  // Siguraduhing nasa st.rows ang record na ito (baka nasa ibang page/di pa
  // naka-load) para gumana ang save/edit flow.
  if (!(st.rows || []).some(r => r.id === id)) {
    st.rows = st.rows || [];
    st.rows.push({ id, data: match.data || {} });
  }
  asmcEditingId = id;
  document.getElementById("asmcRowModalTitle").textContent = fullEdit ? "Edit Row" : "Edit Row (Returned OR / Date Recorded only)";
  asmcRenderRowFields(st.headers, match.data, !fullEdit);
}

function asmcOpenAddModal() {
  if (!asmcCanEdit()) { showAlertModal("Access denied. Only ANN and CARL can add to AS with MC."); return; }
  const st = asmcState[asmcActiveDataset];
  if (!st.headers.length) { showAlertModal("Please import a CSV first so the columns are known before adding a row."); return; }
  asmcEditingId = null;
  document.getElementById("asmcRowModalTitle").textContent = "Add Row";
  asmcRenderRowFields(st.headers, null, false);
  document.getElementById("asmcRowModalOverlay").classList.add("show");
}

function asmcOpenEditModal(id) {
  const fullEdit = asmcCanEdit();
  if (!fullEdit && !asmcCanLimitedEdit()) { showAlertModal("Access denied. Please log in to edit AS with MC."); return; }
  const st = asmcState[asmcActiveDataset];
  const row = (st.rows || []).find(r => r.id === id);
  if (!row) { showAlertModal("Row not found. Please reload the page."); return; }
  asmcEditingId = id;
  document.getElementById("asmcRowModalTitle").textContent = fullEdit ? "Edit Row" : "Edit Row (Returned OR / Date Recorded only)";
  asmcRenderRowFields(st.headers, row.data, !fullEdit);
  document.getElementById("asmcRowModalOverlay").classList.add("show");
}

function asmcCloseRowModal() {
  document.getElementById("asmcRowModalOverlay").classList.remove("show");
  asmcEditingId = null;
  asmcASHistoryMatches = {};
}

async function asmcSaveRowModal() {
  const dataset = asmcActiveDataset;
  const wasEditing = !!asmcEditingId;
  const st = asmcState[dataset];
  const inputs = document.querySelectorAll("#asmcRowModalBody [data-asmc-field]");
  const data = {};
  inputs.forEach(inp => {
    const key = inp.getAttribute("data-asmc-field");
    data[key] = inp.getAttribute("data-asmc-bool") ? (inp.checked ? "TRUE" : "FALSE") : inp.value;
  });

  // Ang "#" ay hindi na kasama sa form (auto-increment), pero kung nag-eedit
  // ng existing row, panatilihin ang dati nitong value para hindi mabura.
  const numHeader = (st.headers || []).find(h => asmcIsRowNumberHeader(h));
  if (numHeader && asmcEditingId) {
    const row = (st.rows || []).find(r => r.id === asmcEditingId);
    data[numHeader] = row ? (row.data ? row.data[numHeader] ?? "" : "") : "";
  }

  const action = asmcEditingId ? "updateAsmcRow" : "addAsmcRow";
  const payload = { action, dataset, data, actor: CURRENT_USER || "" };
  if (asmcEditingId) payload.id = asmcEditingId;

  try {
    const res = await fetch(CLOUD_URL, {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    }).then(r => r.json());
    if (res.error) { showAlertModal(res.error); return; }
    asmcLoad(st.page);
    // Pareho na ngayon ang behavior ng Add at Edit: pagkatapos ma-save,
    // awtomatikong nagcli-clear ang form pero hindi nasasarado ang modal.
    if (typeof showToast === "function") showToast(wasEditing ? "✅ Row updated!" : "✅ Row added!");
    asmcEditingId = null;
    asmcASHistoryMatches = {};
    document.getElementById("asmcRowModalTitle").textContent = "Add Row";
    asmcRenderRowFields(st.headers, null);
  } catch (e) {
    showAlertModal("Save failed: " + e.message);
  }
}

async function asmcDeleteRow(id) {
  if (!asmcCanEdit()) { showAlertModal("Access denied. Only ANN and CARL can delete AS with MC."); return; }
  if (!(await showConfirm("Are you sure you want to delete this row?", { title: "Delete Row", okLabel: "Delete", danger: true }))) return;
  const dataset = asmcActiveDataset;
  try {
    const res = await fetch(CLOUD_URL, {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "deleteAsmcRow", dataset, id, actor: CURRENT_USER || "" })
    }).then(r => r.json());
    if (res.error) { showAlertModal(res.error); return; }
    asmcLoad(asmcState[dataset].page);
  } catch (e) {
    showAlertModal("Delete failed: " + e.message);
  }
}

// Export to Excel — kinukuha LAHAT ng rows na tumutugma sa kasalukuyang
// search/filters (JV#, AS#, MC#, PAYEE, general search) ng active na tab,
// hindi lang yung nasa kasalukuyang page, tapos ginagawang .xlsx file.
async function asmcExportExcel() {
  const dataset = asmcActiveDataset;
  const st = asmcState[dataset];
  const btn = document.getElementById("asmc-export-btn");
  const debug = document.getElementById("asmc-debug");
  if (btn) { btn.disabled = true; btn.textContent = "⏳ Exporting..."; }
  try {
    const data = await fetch(CLOUD_URL, {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        action: "listAsmcDataset", dataset, q: st.q, combo: st.combo,
        exportAll: true, sort: (document.getElementById("asmc-sort") || {}).value || "latest"
      })
    }).then(r => r.json());

    if (data.error) { showAlertModal("Export failed: " + data.error); return; }
    const headers = data.headers || [];
    const rows = data.rows || [];
    if (!rows.length) { showAlertModal("Walang rows na maie-export (base sa kasalukuyang search/filter)."); return; }

    // Gamitin ang parehong shared logo/branded workbook helper na ginagamit ng
    // Released at SLLI exports, para magkapareho ang porma (logos, title,
    // subtitle, styled header row) ng lahat ng exported Excel files.
    const columns = headers.map((h, i) => ({
      header: h,
      key: `col${i}`,
      width: Math.min(30, Math.max(10, String(h || "").length + 4))
    }));
    const exportRows = rows.map(r => {
      const obj = {};
      headers.forEach((h, i) => { obj[`col${i}`] = r.data ? (r.data[h] ?? "") : ""; });
      return obj;
    });

    const q = (document.getElementById("asmc-q") || {}).value || "";
    const subtitle = `Keyword: ${q || "All"} | Records: ${rows.length}`;
    const wb = await buildLogoWorkbook(ASMC_DATASET_LABELS[dataset], columns, exportRows, {
      sheetName: ASMC_DATASET_LABELS[dataset].slice(0, 31), subtitle
    });

    const stamp = new Date().toISOString().slice(0, 10);
    const filename = `${ASMC_DATASET_LABELS[dataset].replace(/[^\w\s-]/g, "")}_${stamp}.xlsx`;
    const buf = await wb.xlsx.writeBuffer();
    saveAs(new Blob([buf], { type: "application/octet-stream" }), filename);
    if (typeof showToast === "function") showToast(`✅ Exported ${rows.length} rows to Excel`);
  } catch (e) {
    showAlertModal("Export failed: " + e.message);
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = "📊 Export to Excel"; }
    if (debug) debug.textContent = "";
  }
}