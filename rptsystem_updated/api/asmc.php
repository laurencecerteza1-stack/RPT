<?php
// ============================================================================
// AS WITH MC — bagong module para lang mag-imbak ng 3 HIWALAY na historical/
// archive na CSV (AS with MC - RPT Dept, SLLI MC Liaison, SLRDI MC Liaison).
//
// Sadyang GENERIC/RAW ang pag-iimbak dito (isang JSON blob per row, kasama
// ang orihinal na column headers) — HINDI ito kumokonekta o nagpapalit sa
// existing na Liaison/SLLI/SLRDI modules (na may sariling workflow/fields).
// Layunin lang: "ilagay lahat ng laman ng CSV" sa sarili nilang table,
// hiwalay-hiwalay bawat dataset, walang parsing/validation logic na
// pwedeng makaltas ng datos.
// ============================================================================

// Tatlong hiwalay na dataset/table
const ASMC_DATASETS = [
    'rpt_dept' => 'asmc_rpt_dept',
    'slli'     => 'asmc_slli_liaison',
    'slrdi'    => 'asmc_slrdi_liaison',
];

// Mga username na pwedeng mag-import/mag-add/mag-delete ng AS with MC data
// (full access). Lahat ng iba pang naka-login na users ay view/search lang
// ang pwede BY DEFAULT, MALIBAN sa 2 partikular na column (tingnan sa baba)
// na pwede nilang i-edit — locked/di-magagalaw ang lahat ng iba pang column.
const ASMC_EDITORS = ['ANN', 'CARL'];

// Mga column na pwedeng i-edit ng mga user na HINDI kabilang sa ASMC_EDITORS.
// Case-insensitive ang pagtutugma sa header name. Lahat ng iba pang column
// ay locked/read-only para sa kanila.
const ASMC_LIMITED_EDIT_FIELDS = ['RETURNED OR', 'DATE RECORDED'];

function asmcCanEdit($username) {
    return in_array(strtoupper(trim((string)$username)), ASMC_EDITORS, true);
}

// "Limited edit" = kahit sinong naka-login (may username), pwedeng mag-edit
// pero ang mga column lang na nasa ASMC_LIMITED_EDIT_FIELDS.
function asmcCanLimitedEdit($username) {
    return trim((string)$username) !== '';
}

function asmcIsLimitedEditableField($header) {
    $h = strtoupper(trim((string)$header));
    foreach (ASMC_LIMITED_EDIT_FIELDS as $f) {
        if (strtoupper($f) === $h) return true;
    }
    return false;
}

function asmcTableFor($dataset) {
    if (!isset(ASMC_DATASETS[$dataset])) {
        sendJSON(["error" => "Invalid dataset: $dataset"]);
        exit;
    }
    return ASMC_DATASETS[$dataset];
}

function ensureAsmcTables($db) {
    foreach (ASMC_DATASETS as $table) {
        $db->query("
            CREATE TABLE IF NOT EXISTS `$table` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                row_num INT NOT NULL,
                row_json LONGTEXT NOT NULL,
                imported_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                imported_by VARCHAR(100) DEFAULT NULL,
                INDEX (row_num)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
    // Meta table: nagse-save ng column headers (at pagkakasunod nito) bawat
    // dataset, para tama pa rin ang pagkakaayos ng mga columns pag ipinakita/
    // ie-export kahit wala munang laman ang table.
    $db->query("
        CREATE TABLE IF NOT EXISTS asmc_meta (
            dataset VARCHAR(30) PRIMARY KEY,
            headers_json LONGTEXT NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// Import (FULL REPLACE) ng isang dataset — tinatanggal muna ang lumang laman
// ng kani-kanilang table bago ipasok ang bago, para eksaktong larawan ng CSV
// na in-upload ang laman ng table (walang duplicate kapag paulit-ulit
// na-import ang parehong file).
function importAsmcDataset($body) {
    $importedBy = currentSessionUsername() ?? '';

    // Access control: ANN at CARL lang ang pwedeng mag-import/mag-edit.
    if (!asmcCanEdit($importedBy)) {
        sendJSON(["error" => "Access denied. Only ANN and CARL can import/edit AS with MC."]);
        return;
    }

    $db = getDB();
    ensureAsmcTables($db);

    $dataset = trim($body['dataset'] ?? '');
    $table = asmcTableFor($dataset);
    $headers = $body['headers'] ?? [];
    $rows = $body['rows'] ?? [];

    if (!is_array($headers) || !count($headers)) {
        sendJSON(["error" => "No headers received."]);
        return;
    }
    if (!is_array($rows)) {
        sendJSON(["error" => "No rows received."]);
        return;
    }

    $db->begin_transaction();
    try {
        $db->query("TRUNCATE TABLE `$table`");

        $stmt = $db->prepare("INSERT INTO `$table` (row_num, row_json, imported_by) VALUES (?, ?, ?)");
        $rowNum = 0;
        foreach ($rows as $row) {
            $rowNum++;
            // Bawat row ay isang array ng cell values, kasunod ng headers.
            // "Ilagay lahat" — kahit blangko ang cell, isasama pa rin ito
            // (empty string) para eksaktong bilang ng column ang mapreserve.
            $obj = [];
            foreach ($headers as $i => $h) {
                $key = trim((string)$h) !== '' ? (string)$h : ("col_" . ($i + 1));
                $obj[$key] = $row[$i] ?? '';
            }
            $json = json_encode($obj, JSON_UNESCAPED_UNICODE);
            $stmt->bind_param("iss", $rowNum, $json, $importedBy);
            $stmt->execute();
        }
        $stmt->close();

        $headersJson = json_encode(array_values($headers), JSON_UNESCAPED_UNICODE);
        $metaStmt = $db->prepare("INSERT INTO asmc_meta (dataset, headers_json) VALUES (?, ?) ON DUPLICATE KEY UPDATE headers_json = VALUES(headers_json), updated_at = NOW()");
        $metaStmt->bind_param("ss", $dataset, $headersJson);
        $metaStmt->execute();
        $metaStmt->close();

        $db->commit();
        sendJSON(["success" => true, "imported" => $rowNum]);
    } catch (Exception $e) {
        $db->rollback();
        sendJSON(["error" => "Import failed: " . $e->getMessage()]);
    }
    $db->close();
}

// Bagong row (manual add, hindi galing sa CSV import).
function addAsmcRow($body) {
    $actor = currentSessionUsername() ?? '';
    if (!asmcCanEdit($actor)) {
        sendJSON(["error" => "Access denied. Only ANN and CARL can add to AS with MC."]);
        return;
    }

    $db = getDB();
    ensureAsmcTables($db);

    $dataset = trim($body['dataset'] ?? '');
    $table = asmcTableFor($dataset);
    $data = $body['data'] ?? [];
    if (!is_array($data)) $data = [];

    $rowNumRes = $db->query("SELECT COALESCE(MAX(row_num),0)+1 AS n FROM `$table`")->fetch_assoc();
    $rowNum = (int)$rowNumRes['n'];

    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    $stmt = $db->prepare("INSERT INTO `$table` (row_num, row_json, imported_by) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $rowNum, $json, $actor);
    if ($stmt->execute()) {
        sendJSON(["success" => true, "id" => $stmt->insert_id]);
    } else {
        sendJSON(["error" => $stmt->error]);
    }
    $stmt->close();
    $db->close();
}

// I-edit ang laman ng isang row (row_json) — hindi nagbabago ang headers.
function updateAsmcRow($body) {
    $actor = currentSessionUsername() ?? '';
    $fullEdit = asmcCanEdit($actor);
    $limitedEdit = asmcCanLimitedEdit($actor);

    if (!$fullEdit && !$limitedEdit) {
        sendJSON(["error" => "Access denied. Please log in to edit AS with MC."]);
        return;
    }

    $db = getDB();
    ensureAsmcTables($db);

    $dataset = trim($body['dataset'] ?? '');
    $table = asmcTableFor($dataset);
    $id = (int)($body['id'] ?? 0);
    $data = $body['data'] ?? [];
    if (!$id) { sendJSON(["error" => "Missing row id."]); return; }
    if (!is_array($data)) $data = [];

    if (!$fullEdit) {
        // Limited edit: kahit ano pa ang ipinadala ng client, ang RETURNED OR
        // at DATE RECORDED lang (o katumbas na column) ang papayagang baguhin.
        // I-fetch muna ang existing row para hindi masira/mabura ang ibang
        // column (locked/read-only sila para sa user na ito).
        $existingStmt = $db->prepare("SELECT row_json FROM `$table` WHERE id = ?");
        $existingStmt->bind_param("i", $id);
        $existingStmt->execute();
        $existingRow = $existingStmt->get_result()->fetch_assoc();
        $existingStmt->close();
        if (!$existingRow) { sendJSON(["error" => "Row not found."]); $db->close(); return; }

        $merged = json_decode($existingRow['row_json'], true) ?: [];
        foreach ($data as $key => $val) {
            if (asmcIsLimitedEditableField($key)) {
                $merged[$key] = $val;
            }
        }
        $data = $merged;
    }

    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    $stmt = $db->prepare("UPDATE `$table` SET row_json = ? WHERE id = ?");
    $stmt->bind_param("si", $json, $id);
    if ($stmt->execute()) {
        sendJSON(["success" => true]);
    } else {
        sendJSON(["error" => $stmt->error]);
    }
    $stmt->close();
    $db->close();
}

// Tanggalin ang isang row.
function deleteAsmcRow($body) {
    $actor = currentSessionUsername() ?? '';
    if (!asmcCanEdit($actor)) {
        sendJSON(["error" => "Access denied. Only ANN and CARL can delete AS with MC."]);
        return;
    }

    $db = getDB();
    ensureAsmcTables($db);

    $dataset = trim($body['dataset'] ?? '');
    $table = asmcTableFor($dataset);
    $id = (int)($body['id'] ?? 0);
    if (!$id) { sendJSON(["error" => "Missing row id."]); return; }

    $stmt = $db->prepare("DELETE FROM `$table` WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        sendJSON(["success" => true]);
    } else {
        sendJSON(["error" => $stmt->error]);
    }
    $stmt->close();
    $db->close();
}

// Tinitingnan kung aling(mga) column (header name) ang tumutugma sa isang
// filter category (jv / as / mc / payee), base sa keyword na laman ng
// pangalan ng header — dahil iba-iba ang eksaktong pangalan ng column bawat
// dataset/import (hal. "JV#", "AS#", "MC#/CHECK#", "MC#/CHECK# (2)", "PAYEE").
function asmcHeadersMatchingKeyword($headers, $keyword) {
    $kw = strtoupper($keyword);
    $matches = [];
    foreach ($headers as $h) {
        if (strpos(strtoupper((string)$h), $kw) !== false) $matches[] = $h;
    }
    return $matches;
}

// Listahan (paginated, may general free-text search + specific na filters
// para sa JV#, AS#, MC#, PAYEE) ng isang dataset. Ibinabalik din ang headers
// (pagkakasunod ng columns) galing sa asmc_meta, para tama ang pagkakaayos
// ng table sa frontend. Kapag exportAll=true, isasauli ang LAHAT ng rows na
// tumutugma sa filters (walang page limit) — ginagamit ng "Export to Excel".
function listAsmcDataset($body) {
    $db = getDB();
    ensureAsmcTables($db);

    $dataset = trim($body['dataset'] ?? '');
    $table = asmcTableFor($dataset);
    $q = trim($body['q'] ?? '');
    $combo = trim($body['combo'] ?? '');
    $exportAll = !empty($body['exportAll']);
    $page = max(1, (int)($body['page'] ?? 1));
    $pageSize = max(1, min(200, (int)($body['pageSize'] ?? 50)));
    $sort = strtolower(trim($body['sort'] ?? 'latest'));
    $orderBy = $sort === 'oldest' ? 'id ASC' : 'id DESC';

    // Headers (para malaman kung aling column ang JV#/AS#/MC#/PAYEE).
    $headers = [];
    $metaStmt = $db->prepare("SELECT headers_json FROM asmc_meta WHERE dataset = ?");
    $metaStmt->bind_param("s", $dataset);
    $metaStmt->execute();
    $metaRow = $metaStmt->get_result()->fetch_assoc();
    $metaStmt->close();
    if ($metaRow) $headers = json_decode($metaRow['headers_json'], true) ?: [];

    // "Combo" search: OR na tugma sa kahit anong column na JV#, AS#, MC#, o
    // PAYEE (kasama lahat ng variant, hal. 4 MC#/CHECK# columns).
    $comboHeaders = [];
    if ($combo !== '') {
        $comboHeaders = array_values(array_unique(array_merge(
            asmcHeadersMatchingKeyword($headers, 'JV'),
            asmcHeadersMatchingKeyword($headers, 'AS#'),
            asmcHeadersMatchingKeyword($headers, 'MC#'),
            asmcHeadersMatchingKeyword($headers, 'PAYEE')
        )));
    }

    // General (row-wide) na filter muna sa SQL level, para hindi kailangang
    // i-load ang buong table kung hindi kailangan.
    $where = "1=1";
    $types = "";
    $vals = [];
    if ($q !== '') {
        $where .= " AND row_json LIKE ?";
        $types .= "s"; $vals[] = "%$q%";
    }

    $sql = "SELECT id, row_num, row_json FROM `$table` WHERE $where ORDER BY $orderBy";
    $stmt = $db->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$vals);
    $stmt->execute();
    $res = $stmt->get_result();

    $allRows = [];
    while ($r = $res->fetch_assoc()) {
        $data = json_decode($r['row_json'], true) ?: [];

        // Combo filter (OR sa JV#/AS#/MC#/PAYEE columns).
        if ($combo !== '' && !asmcRowMatchesFilter($data, $comboHeaders, $combo)) continue;

        $allRows[] = [
            "id" => (int)$r['id'],
            "rowNum" => (int)$r['row_num'],
            "data" => $data,
        ];
    }
    $stmt->close();

    $total = count($allRows);

    if ($exportAll) {
        $pageRows = $allRows;
        $page = 1;
        $pageSize = max(1, $total);
    } else {
        $offset = ($page - 1) * $pageSize;
        $pageRows = array_slice($allRows, $offset, $pageSize);
    }

    if (!count($headers) && $total) $headers = array_keys($allRows[0]['data']);

    $db->close();
    sendJSON([
        "headers" => $headers,
        "rows" => $pageRows,
        "total" => $total,
        "page" => $page,
        "pageSize" => $pageSize,
        "totalPages" => max(1, ceil($total / max(1, $pageSize))),
    ]);
}

function asmcRowMatchesFilter($data, $headers, $term) {
    if (!count($headers)) return false;
    $needle = strtoupper($term);
    foreach ($headers as $h) {
        $val = $data[$h] ?? '';
        if (strpos(strtoupper((string)$val), $needle) !== false) return true;
    }
    return false;
}

// Habang nag-a-add/nag-e-edit ng row, hinahanap kung may existing na record na
// ang AS# column ay eksaktong tumutugma (case/space-insensitive) sa binuong
// value — kagaya ng RA# duplicate-history check sa SLLI/SLRDI. Ipinapakita
// ito sa modal bago pa i-save, para malaman kung may kapareho nang record.
function lookupAsmcByAS($body) {
    $db = getDB();
    ensureAsmcTables($db);

    $dataset = trim($body['dataset'] ?? '');
    $table = asmcTableFor($dataset);
    $asValue = trim($body['asValue'] ?? '');
    $excludeId = (int)($body['excludeId'] ?? 0);

    if ($asValue === '') {
        $db->close();
        sendJSON(["matches" => []]);
        return;
    }

    $headers = [];
    $metaStmt = $db->prepare("SELECT headers_json FROM asmc_meta WHERE dataset = ?");
    $metaStmt->bind_param("s", $dataset);
    $metaStmt->execute();
    $metaRow = $metaStmt->get_result()->fetch_assoc();
    $metaStmt->close();
    if ($metaRow) $headers = json_decode($metaRow['headers_json'], true) ?: [];

    $asHeaders = asmcHeadersMatchingKeyword($headers, 'AS#');
    if (!count($asHeaders)) {
        $db->close();
        sendJSON(["matches" => []]);
        return;
    }

    $norm = function ($s) { return strtoupper(trim((string)$s)); };
    // Digits-lang na version — para kahit i-type lang ang bilang (hal. "1234"
    // kahit ang laman sa dataset ay "AS#1234" o "AS# 1234-2024"), madideklara
    // pa ring "existing" ito.
    $normDigits = function ($s) { return preg_replace('/[^0-9]/', '', (string)$s); };
    $needle = $norm($asValue);
    $needleDigits = $normDigits($asValue);

    // I-broaden ang SQL LIKE gamit ang digits-only na bersyon ng typed value
    // (kung meron), para hindi nasasala ang mga row na iba ang format pero
    // pareho ang numero.
    $sql = "SELECT id, row_json FROM `$table` WHERE row_json LIKE ?" . ($needleDigits !== '' && $needleDigits !== $asValue ? " OR row_json LIKE ?" : "") . " ORDER BY id DESC";
    $stmt = $db->prepare($sql);
    $like = "%$asValue%";
    if ($needleDigits !== '' && $needleDigits !== $asValue) {
        $likeDigits = "%$needleDigits%";
        $stmt->bind_param("ss", $like, $likeDigits);
    } else {
        $stmt->bind_param("s", $like);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $matches = [];
    while ($r = $res->fetch_assoc()) {
        $id = (int)$r['id'];
        if ($id === $excludeId) continue;
        $data = json_decode($r['row_json'], true) ?: [];
        $hit = false;
        foreach ($asHeaders as $h) {
            $val = $data[$h] ?? '';
            if ($norm($val) === $needle) { $hit = true; break; }
            if ($needleDigits !== '' && $normDigits($val) === $needleDigits) { $hit = true; break; }
        }
        if ($hit) {
            $matches[] = ["id" => $id, "data" => $data];
            if (count($matches) >= 30) break;
        }
    }
    $stmt->close();
    $db->close();

    sendJSON(["matches" => $matches, "asHeaders" => $asHeaders]);
}
