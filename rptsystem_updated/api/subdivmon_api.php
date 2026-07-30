<?php
// ============================================
// subdivmon.php — Subdivision Monitor module
// Auto-links Liaison records to their matching Lot Inventory
// entry (Subdivision > Block > Lot) and exposes a tree view
// so users can drill down and see all liaison history per lot.
// ============================================

// Add lot_inventory_id link column to liaison_records (safe/idempotent)
function ensureLiaisonLotLinkColumn($db) {
    $col = $db->query("SHOW COLUMNS FROM liaison_records LIKE 'lot_inventory_id'");
    if ($col && $col->num_rows === 0) {
        $db->query("ALTER TABLE liaison_records ADD COLUMN lot_inventory_id INT DEFAULT NULL, ADD INDEX idx_lot_inventory_id (lot_inventory_id)");
    }
}

// Add an INDEXED generated "match key" column sa lot_inventory
// (sm_key = UPPER(TRIM(sub))|UPPER(TRIM(ph))|UPPER(TRIM(blk))|UPPER(TRIM(lot))).
// DAHILAN: yung dating matching ay gumagamit ng UPPER(TRIM(col)) sa WHERE
// clause, na hindi puwedeng gamitan ng index — kaya bawat isang liaison
// record na ilink ay nagfu-full-table-scan sa ~250k rows ng lot_inventory.
// Sa 15,000+ liaison records, iyan ang dahilan ng timeout. Gamit ang
// indexed generated column, instant na ang lookup (index seek, hindi scan).
function ensureLotInventoryMatchKey($db) {
    $col = $db->query("SHOW COLUMNS FROM lot_inventory LIKE 'sm_key'");
    $needsRebuild = ($col && $col->num_rows === 0);

    if (!$needsRebuild) {
        // Existing installs: i-check kung luma pa ang generation expression
        // (walang REGEXP normalization) — kung luma, i-drop at i-rebuild
        // para ma-apply ang Blk/Lot leading-zero fix (isang beses lang ito
        // tatakbo bawat install, pagkatapos ay lalagpasan na).
        $dbNameRow = $db->query("SELECT DATABASE() d")->fetch_assoc();
        $dbName = $dbNameRow ? $dbNameRow['d'] : '';
        $exprRes = $db->query("
            SELECT GENERATION_EXPRESSION FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = '" . $db->real_escape_string($dbName) . "'
              AND TABLE_NAME = 'lot_inventory' AND COLUMN_NAME = 'sm_key'
        ");
        $expr = $exprRes ? ($exprRes->fetch_assoc()['GENERATION_EXPRESSION'] ?? '') : '';
        if (stripos($expr, 'REGEXP') === false) {
            $db->query("ALTER TABLE lot_inventory DROP INDEX idx_sm_key, DROP COLUMN sm_key");
            $needsRebuild = true;
        }
    }

    if ($needsRebuild) {
        $db->query("
            ALTER TABLE lot_inventory
            ADD COLUMN sm_key VARCHAR(80)
                GENERATED ALWAYS AS (
                    CONCAT(
                        UPPER(TRIM(sub)), '|',
                        UPPER(TRIM(ph)), '|',
                        IF(TRIM(blk) REGEXP '^[0-9]+$', CAST(TRIM(blk) AS UNSIGNED), UPPER(TRIM(blk))), '|',
                        IF(TRIM(lot) REGEXP '^[0-9]+$', CAST(TRIM(lot) AS UNSIGNED), UPPER(TRIM(lot)))
                    )
                ) STORED,
            ADD INDEX idx_sm_key (sm_key)
        ");
    }
}

// Normalize a subd/ph value para consistent yung pagtutugma
// (walang extra spaces, case-insensitive)
function _smNormalize($v) {
    $v = trim((string)$v);
    return $v === '' ? '' : mb_strtoupper($v);
}

// Normalize a Blk/Lot value: kung purely numeric, tinatanggal ang leading
// zeros (hal. "01" -> "1") dahil DITO nagmumula ang karamihan sa hindi
// pagtutugma ng Liaison <-> Subdivision Monitoring — parehong lot pero
// iba ang pagkakasulat sa dalawang datasource ("01" vs "1", " 1" vs "1").
function _smNormalizePart($v) {
    $v = trim((string)$v);
    if ($v === '') return '';
    if (preg_match('/^[0-9]+$/', $v)) return (string)((int)$v);
    return mb_strtoupper($v);
}

// I-normalize ang isang OR Number para sa dedup matching: ang imported CRC
// data ay may format na "2303397(2026)" o "2232256(2023-2025)" (may year
// suffix sa loob ng parenthesis), samantalang ang Liaison naman ay plain
// lang "2303397" — kaya tinatanggal muna ang "(...)" suffix bago i-compare,
// para magkatugma ang parehong OR kahit magkaiba ang format nila.
function _smNormalizeOrNumber($v) {
    $v = trim((string)$v);
    if ($v === '') return '';
    $v = preg_replace('/\s*\([^)]*\)\s*$/', '', $v);
    return mb_strtoupper(trim($v));
}

// Auto-match ang isang Liaison record sa lot_inventory gamit ang
// Subd./Phase/Blk./Lot, tapos i-save yung link (lot_inventory_id).
// Ginagamit ito pagkatapos ma-save (create/update) ang liaison record.
// NOTE: hindi na tinatawag dito ang ensureLiaisonLotLinkColumn/
// ensureLotInventoryTable/ensureLotInventoryMatchKey — dapat isang beses
// lang ito tumakbo sa simula ng calling function (mabagal kung per-row).
function autoLinkLiaisonToLot($db, $liaisonId, $subd, $ph, $blk, $lot) {
    $subd = trim($subd); $ph = trim($ph); $blk = trim($blk); $lot = trim($lot);

    if ($subd === '' && $ph === '' && $blk === '' && $lot === '') {
        // Walang sapat na info para mag-match; alisin ang existing link kung meron
        $stmt = $db->prepare("UPDATE liaison_records SET lot_inventory_id = NULL WHERE id = ?");
        $stmt->bind_param("i", $liaisonId);
        $stmt->execute();
        $stmt->close();
        return null;
    }

    // Partial na Subd/Ph/Blk/Lot (hal. walang Phase) ay bumabalik sa
    // dating multi-condition query (mabagal pero bihira lang mangyari).
    if ($subd === '' || $ph === '' || $blk === '' || $lot === '') {
        $where = []; $types = ""; $vals = [];
        if ($subd !== '') { $where[] = "UPPER(TRIM(sub)) = ?"; $types .= "s"; $vals[] = _smNormalize($subd); }
        if ($ph   !== '') { $where[] = "UPPER(TRIM(ph)) = ?";  $types .= "s"; $vals[] = _smNormalize($ph); }
        if ($blk  !== '') { $where[] = "IF(TRIM(blk) REGEXP '^[0-9]+$', CAST(TRIM(blk) AS UNSIGNED), UPPER(TRIM(blk))) = ?"; $types .= "s"; $vals[] = _smNormalizePart($blk); }
        if ($lot  !== '') { $where[] = "IF(TRIM(lot) REGEXP '^[0-9]+$', CAST(TRIM(lot) AS UNSIGNED), UPPER(TRIM(lot))) = ?"; $types .= "s"; $vals[] = _smNormalizePart($lot); }
        $sql = "SELECT id FROM lot_inventory WHERE " . implode(" AND ", $where) . " LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$vals);
    } else {
        // Fast path: indexed exact match sa sm_key
        $key = _smNormalize($subd) . '|' . _smNormalize($ph) . '|' . _smNormalizePart($blk) . '|' . _smNormalizePart($lot);
        $stmt = $db->prepare("SELECT id FROM lot_inventory WHERE sm_key = ? LIMIT 1");
        $stmt->bind_param("s", $key);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $lotInvId = $row ? (int)$row['id'] : null;

    $upd = $db->prepare("UPDATE liaison_records SET lot_inventory_id = ? WHERE id = ?");
    $upd->bind_param("ii", $lotInvId, $liaisonId);
    $upd->execute();
    $upd->close();

    return $lotInvId;
}

// Batch re-link: pwedeng tawagin manually para i-refresh lahat ng existing
// liaison records (hal. matapos mag-import ng bagong lot_inventory data)
// Chunked/paginated (offset + limit) para hindi mag-timeout sa browser/proxy
// kapag libo-libong records — tinatawag paulit-ulit ng frontend hanggang matapos.
function relinkAllLiaisonRecords($body) {
    @set_time_limit(0);
    $db = getDB();
    ensureLiaisonLotLinkColumn($db);
    ensureLotInventoryTable($db);
    ensureLotInventoryMatchKey($db);

    $offset = max(0, (int)($body['offset'] ?? 0));
    $limit  = max(1, min(2000, (int)($body['limit'] ?? 500)));

    $grand = (int)($db->query("SELECT COUNT(*) c FROM liaison_records")->fetch_assoc()['c'] ?? 0);

    $stmt = $db->prepare("SELECT id, ra_no, buyer, subd, ph, blk, lot FROM liaison_records ORDER BY id ASC LIMIT ? OFFSET ?");
    $stmt->bind_param("ii", $limit, $offset);
    $stmt->execute();
    $res = $stmt->get_result();

    $linked = 0; $processed = 0; $unmatched = [];
    while ($row = $res->fetch_assoc()) {
        $processed++;
        $matched = autoLinkLiaisonToLot($db, (int)$row['id'], $row['subd'], $row['ph'], $row['blk'], $row['lot']);
        if ($matched !== null) {
            $linked++;
        } else {
            // Walang katugmang lot sa lot_inventory (o walang sapat na Subd/Ph/Blk/Lot info) —
            // ilista para ma-review/ma-correct ng user ang data entry
            $unmatched[] = [
                "id"    => (int)$row['id'],
                "raNo"  => $row['ra_no'],
                "buyer" => $row['buyer'],
                "subd"  => $row['subd'],
                "ph"    => $row['ph'],
                "blk"   => $row['blk'],
                "lot"   => $row['lot'],
            ];
        }
    }
    $stmt->close();
    $db->close();

    $nextOffset = $offset + $processed;
    sendJSON([
        "success"    => true,
        "processed"  => $processed,
        "linked"     => $linked,
        "unmatched"  => $unmatched,
        "offset"     => $offset,
        "nextOffset" => $nextOffset,
        "grandTotal" => $grand,
        "done"       => $processed < $limit || $nextOffset >= $grand,
    ]);
}

// Listahan ng mga Municipal (galing tax_rates.municipal, kaparehong mapping
// na ginagamit sa Tax Rates module — tax_rates.lot_code = lot_inventory.sub)
function getSubdivisionMonitorMunicipals() {
    $db = getDB();
    $res = $db->query("SELECT DISTINCT municipal FROM tax_rates WHERE municipal IS NOT NULL AND municipal != '' ORDER BY municipal ASC");
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r['municipal'];
    $db->close();
    sendJSON(["municipals" => $rows]);
}

// Level 1: listahan ng mga Subdivision (galing lot_inventory.sub), kasama
// ang bilang ng lots at bilang ng may-kaugnay na liaison records.
// Optional na may-filter kung Municipal (galing sa Tax Rates mapping).
function getSubdivisionMonitorTree($body = []) {
    $db = getDB();
    ensureLotInventoryTable($db);
    ensureLiaisonLotLinkColumn($db);

    $municipal = trim($body['municipal'] ?? '');

    $sql = "
        SELECT
            li.sub AS subd,
            tr.municipal AS municipal,
            COUNT(DISTINCT li.id) AS lot_count,
            COUNT(DISTINCT lr.id) AS liaison_count
        FROM lot_inventory li
        LEFT JOIN liaison_records lr ON lr.lot_inventory_id = li.id
        LEFT JOIN tax_rates tr ON tr.lot_code = li.sub
        WHERE li.sub IS NOT NULL AND li.sub != ''
    ";
    $types = ""; $vals = [];
    if ($municipal !== '') {
        $sql .= " AND tr.municipal = ?";
        $types .= "s"; $vals[] = $municipal;
    }
    $sql .= " GROUP BY li.sub, tr.municipal ORDER BY li.sub ASC";

    if ($types !== '') {
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$vals);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $db->query($sql);
    }

    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = [
            "subd"          => $r['subd'],
            "municipal"     => $r['municipal'],
            "lotCount"      => (int)$r['lot_count'],
            "liaisonCount"  => (int)$r['liaison_count'],
        ];
    }
    $db->close();
    sendJSON(["subdivisions" => $rows]);
}

// Level 2: mga Block sa loob ng isang Subdivision (paginated)
function getSubdivisionMonitorBlocks($body) {
    $db = getDB();
    ensureLotInventoryTable($db);
    ensureLiaisonLotLinkColumn($db);

    $subd = trim($body['subd'] ?? '');
    if ($subd === '') sendJSON(["error" => "Subdivision is required."]);

    $page = max(1, (int)($body['page'] ?? 1));
    $pageSize = max(1, min(100, (int)($body['pageSize'] ?? 12)));
    $offset = ($page - 1) * $pageSize;
    $q = trim($body['q'] ?? '');

    $where = "li.sub = ?"; $types = "s"; $vals = [$subd];
    if ($q !== '') { $where .= " AND li.blk LIKE ?"; $types .= "s"; $vals[] = "%$q%"; }

    $countStmt = $db->prepare("SELECT COUNT(DISTINCT li.blk) AS c FROM lot_inventory li WHERE $where");
    $countStmt->bind_param($types, ...$vals);
    $countStmt->execute();
    $total = (int)($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $countStmt->close();

    $sql = "
        SELECT
            li.blk AS blk,
            COUNT(DISTINCT li.id) AS lot_count,
            COUNT(DISTINCT lr.id) AS liaison_count
        FROM lot_inventory li
        LEFT JOIN liaison_records lr ON lr.lot_inventory_id = li.id
        WHERE $where
        GROUP BY li.blk
        ORDER BY (li.blk + 0) ASC, li.blk ASC
        LIMIT ? OFFSET ?
    ";
    $types2 = $types . "ii"; $vals2 = array_merge($vals, [$pageSize, $offset]);
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types2, ...$vals2);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = [
            "blk"          => $r['blk'] !== null && $r['blk'] !== '' ? $r['blk'] : "(No Block)",
            "lotCount"     => (int)$r['lot_count'],
            "liaisonCount" => (int)$r['liaison_count'],
        ];
    }
    $stmt->close();
    $db->close();
    sendJSON(["subd" => $subd, "blocks" => $rows, "total" => $total, "page" => $page, "pageSize" => $pageSize, "totalPages" => max(1, (int)ceil($total / $pageSize))]);
}

// Level 3: mga Lot sa loob ng isang Subdivision + Block
// Idempotent: dagdagan ng status column (galing sa imported subdivision
// Excel — hal. RELEASED/JOINT VENTURES/atbp.) kung wala pa. Kailangan ito
// dito (hindi lang sa import script) para hindi ma-break ang "Lots" query
// kahit hindi pa na-run ang import ng isang partikular na installation.
function ensureLotInventoryStatusColumn($db) {
    $col = $db->query("SHOW COLUMNS FROM lot_inventory LIKE 'status'");
    if ($col && $col->num_rows === 0) {
        $db->query("ALTER TABLE lot_inventory ADD COLUMN status VARCHAR(100) DEFAULT NULL");
    }
}

function getSubdivisionMonitorLots($body) {
    $db = getDB();
    ensureLotInventoryTable($db);
    ensureLiaisonLotLinkColumn($db);
    ensureLotInventoryStatusColumn($db);

    $subd = trim($body['subd'] ?? '');
    $blk  = trim($body['blk'] ?? '');
    $allBlocks = !empty($body['allBlocks']);
    if ($subd === '') sendJSON(["error" => "Subdivision is required."]);

    $where = "li.sub = ?";
    $types = "s"; $vals = [$subd];
    if (!$allBlocks) {
        if ($blk !== '' && $blk !== '(No Block)') {
            $where .= " AND li.blk = ?"; $types .= "s"; $vals[] = $blk;
        } else {
            $where .= " AND (li.blk IS NULL OR li.blk = '')";
        }
    }

    $page = max(1, (int)($body['page'] ?? 1));
    $pageSize = max(1, min(200, (int)($body['pageSize'] ?? 9)));
    $offset = ($page - 1) * $pageSize;
    $q = trim($body['q'] ?? '');
    if ($q !== '') {
        $where .= " AND (li.code LIKE ? OR li.ra_number LIKE ? OR li.lot LIKE ? OR li.td_no_latest LIKE ? OR li.td_no_old LIKE ? OR li.tct_no LIKE ? OR li.transferred_tct LIKE ?)";
        $types .= "sssssss"; $qLike = "%$q%";
        $vals[] = $qLike; $vals[] = $qLike; $vals[] = $qLike; $vals[] = $qLike; $vals[] = $qLike; $vals[] = $qLike; $vals[] = $qLike;
    }

    $countSql = "SELECT COUNT(*) AS c FROM lot_inventory li WHERE $where";
    $countStmt = $db->prepare($countSql);
    $countStmt->bind_param($types, ...$vals);
    $countStmt->execute();
    $total = (int)($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $countStmt->close();

    $sql = "
        SELECT
            li.id, li.code, li.ph, li.blk, li.lot, li.ra_number, li.buyers_name, li.lot_owner,
            li.assessed_value, li.status AS lot_status,
            li.tct_no, li.transferred_tct, li.td_no_old, li.td_no_latest,
            COUNT(lr.id) AS liaison_count,
            COUNT(CASE WHEN lr.or_no IS NOT NULL AND lr.or_no <> '' THEN 1 END) AS or_count,
            MAX(lr.date_received) AS last_activity,
            (SELECT lr2.status_remarks FROM liaison_records lr2
                WHERE lr2.lot_inventory_id = li.id
                ORDER BY lr2.date_saved DESC, lr2.id DESC LIMIT 1) AS latest_status,
            (SELECT lr2.or_date FROM liaison_records lr2
                WHERE lr2.lot_inventory_id = li.id AND lr2.or_date IS NOT NULL AND lr2.or_date <> ''
                ORDER BY lr2.or_date DESC, lr2.id DESC LIMIT 1) AS latest_or_date,
            (SELECT COALESCE(NULLIF(lr2.or_yr_covered,''), NULLIF(lr2.yr_covered,''))
                FROM liaison_records lr2
                WHERE lr2.lot_inventory_id = li.id
                ORDER BY lr2.date_saved DESC, lr2.id DESC LIMIT 1) AS rpt_updated
        FROM lot_inventory li
        LEFT JOIN liaison_records lr ON lr.lot_inventory_id = li.id
        WHERE $where
        GROUP BY li.id
        ORDER BY (li.ra_number + 0) ASC, li.ra_number ASC
        LIMIT ? OFFSET ?
    ";
    $types2 = $types . "ii"; $vals2 = array_merge($vals, [$pageSize, $offset]);
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types2, ...$vals2);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = [
            "id"            => (int)$r['id'],
            "code"          => $r['code'],
            "ph"            => $r['ph'],
            "blk"           => $r['blk'],
            "lot"           => $r['lot'],
            "raNumber"      => $r['ra_number'],
            "buyersName"    => $r['buyers_name'],
            "lotOwner"      => $r['lot_owner'],
            "tctNo"         => $r['tct_no'],
            "previousTctNo" => $r['transferred_tct'],
            "assessedValue" => $r['assessed_value'],
            "lotStatus"     => $r['lot_status'],
            "tdNo"          => $r['td_no_latest'],
            "previousTdNo"  => $r['td_no_old'],
            "liaisonCount"  => (int)$r['liaison_count'],
            "orCount"       => (int)$r['or_count'],
            "lastActivity"  => $r['last_activity'],
            "status"        => $r['latest_status'],
            "latestOrDate"  => $r['latest_or_date'],
            "rptUpdated"    => $r['rpt_updated'],
        ];
    }
    $stmt->close();
    $db->close();
    sendJSON(["subd" => $subd, "blk" => $blk, "lots" => $rows, "total" => $total, "page" => $page, "pageSize" => $pageSize, "totalPages" => max(1, (int)ceil($total / $pageSize))]);
}

// Listahan ng mga Subdivision, optional na naka-filter sa isang Municipal —
// ginagamit sa cascading Municipal -> Subdivision dropdowns ng Subdivision
// Monitor search bar (para hindi lahat ng lots agad ang lumalabas).
function getSubdivisionMonitorSubdivisions($body = []) {
    $db = getDB();
    ensureLotInventoryTable($db);

    $municipal = trim($body['municipal'] ?? '');

    $sql = "
        SELECT DISTINCT li.sub AS subd
        FROM lot_inventory li
        LEFT JOIN tax_rates tr ON tr.lot_code = li.sub
        WHERE li.sub IS NOT NULL AND li.sub != ''
    ";
    $types = ""; $vals = [];
    if ($municipal !== '') {
        $sql .= " AND tr.municipal = ?";
        $types .= "s"; $vals[] = $municipal;
    }
    $sql .= " ORDER BY li.sub ASC";

    if ($types !== '') {
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$vals);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $db->query($sql);
    }

    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r['subd'];
    $db->close();
    sendJSON(["subdivisions" => $rows]);
}

// Flat search: lahat ng lots (walang kailangang piliin muna na Subdivision/Block),
// katulad ng search bar sa Lot Master List (lots.php) — Code/TD/Status/TD-Record
// filters lahat sa isang row, may Municipal filter din, at result ay isang flat
// searchable/paginated table (hindi na tree/drill-down).
function searchSubdivisionMonitorLots($body) {
    $db = getDB();
    ensureLotInventoryTable($db);
    ensureLiaisonLotLinkColumn($db);
    ensureLotInventoryStatusColumn($db);
    ensureLotInventoryMatchKey($db); // ok lang ulit-ulitin, idempotent

    $code       = trim($body['code'] ?? '');
    $tdNo       = trim($body['tdNo'] ?? '');
    $status     = trim($body['status'] ?? '');
    $hasTdRecord = trim($body['hasTdRecord'] ?? ''); // '', 'yes', 'no'
    $municipal  = trim($body['municipal'] ?? '');
    $subd       = trim($body['subd'] ?? '');

    $where = "1=1"; $types = ""; $vals = [];

    if ($code !== '') {
        $where .= " AND (li.code LIKE ? OR li.ra_number LIKE ? OR li.lot LIKE ? OR li.sub LIKE ?)";
        $types .= "ssss"; $cLike = "%$code%";
        $vals[] = $cLike; $vals[] = $cLike; $vals[] = $cLike; $vals[] = $cLike;
    }
    if ($tdNo !== '') {
        $where .= " AND (li.td_no_latest LIKE ? OR li.td_no_old LIKE ?)";
        $types .= "ss"; $tLike = "%$tdNo%";
        $vals[] = $tLike; $vals[] = $tLike;
    }
    if ($status !== '') {
        $where .= " AND li.status LIKE ?";
        $types .= "s"; $vals[] = "%$status%";
    }
    if ($hasTdRecord === 'yes') {
        $where .= " AND ((li.td_no_latest IS NOT NULL AND li.td_no_latest != '') OR (li.td_no_old IS NOT NULL AND li.td_no_old != ''))";
    } elseif ($hasTdRecord === 'no') {
        $where .= " AND (li.td_no_latest IS NULL OR li.td_no_latest = '') AND (li.td_no_old IS NULL OR li.td_no_old = '')";
    }
    if ($municipal !== '') {
        $where .= " AND tr.municipal = ?";
        $types .= "s"; $vals[] = $municipal;
    }
    if ($subd !== '') {
        $where .= " AND li.sub = ?";
        $types .= "s"; $vals[] = $subd;
    }

    $page = max(1, (int)($body['page'] ?? 1));
    $pageSize = max(1, min(100, (int)($body['pageSize'] ?? 20)));
    $offset = ($page - 1) * $pageSize;

    $countSql = "
        SELECT COUNT(*) AS c
        FROM lot_inventory li
        LEFT JOIN tax_rates tr ON tr.lot_code = li.sub
        WHERE $where
    ";
    $countStmt = $db->prepare($countSql);
    if ($types !== '') $countStmt->bind_param($types, ...$vals);
    $countStmt->execute();
    $total = (int)($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $countStmt->close();

    $sql = "
        SELECT
            li.id, li.code, li.sub, li.ph, li.blk, li.lot, li.ra_number, li.buyers_name, li.lot_owner,
            li.assessed_value, li.status AS lot_status,
            li.tct_no, li.transferred_tct, li.td_no_old, li.td_no_latest,
            tr.municipal AS municipal,
            (
                (SELECT COUNT(*) FROM lot_or_history oh WHERE oh.lot_inventory_id = li.id AND oh.or_number IS NOT NULL AND oh.or_number <> '' AND oh.amount IS NOT NULL AND oh.amount > 0)
                +
                (SELECT COUNT(*) FROM liaison_records lr WHERE lr.lot_inventory_id = li.id AND lr.or_no IS NOT NULL AND lr.or_no <> ''
                    AND NOT EXISTS (SELECT 1 FROM lot_or_history oh4 WHERE oh4.lot_inventory_id = li.id AND UPPER(TRIM(REGEXP_REPLACE(oh4.or_number, '\\\\s*\\\\([^)]*\\\\)\\\\s*$', ''))) = UPPER(TRIM(lr.or_no))))
            ) AS or_count,
            (SELECT MAX(oh2.yr) FROM lot_or_history oh2 WHERE oh2.lot_inventory_id = li.id AND oh2.or_number IS NOT NULL AND oh2.or_number <> '' AND oh2.amount IS NOT NULL AND oh2.amount > 0) AS rpt_updated,
            COALESCE(
                (SELECT oh3.or_date FROM lot_or_history oh3 WHERE oh3.lot_inventory_id = li.id AND oh3.or_date IS NOT NULL AND oh3.or_date <> '' ORDER BY oh3.yr DESC, oh3.id DESC LIMIT 1),
                (SELECT lr2.or_date FROM liaison_records lr2 WHERE lr2.lot_inventory_id = li.id AND lr2.or_date IS NOT NULL AND lr2.or_date <> '' ORDER BY lr2.date_saved DESC, lr2.id DESC LIMIT 1)
            ) AS latest_or_date
        FROM lot_inventory li
        LEFT JOIN tax_rates tr ON tr.lot_code = li.sub
        WHERE $where
        ORDER BY (li.ra_number + 0) ASC, li.ra_number ASC
        LIMIT ? OFFSET ?
    ";
    $types2 = $types . "ii"; $vals2 = array_merge($vals, [$pageSize, $offset]);
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types2, ...$vals2);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = [
            "id"            => (int)$r['id'],
            "code"          => $r['code'],
            "sub"           => $r['sub'],
            "ph"            => $r['ph'],
            "blk"           => $r['blk'],
            "lot"           => $r['lot'],
            "raNumber"      => $r['ra_number'],
            "buyersName"    => $r['buyers_name'],
            "lotOwner"      => $r['lot_owner'],
            "tctNo"         => $r['tct_no'],
            "previousTctNo" => $r['transferred_tct'],
            "assessedValue" => $r['assessed_value'],
            "lotStatus"     => $r['lot_status'],
            "tdNo"          => $r['td_no_latest'],
            "previousTdNo"  => $r['td_no_old'],
            "municipal"     => $r['municipal'],
            "orCount"       => (int)$r['or_count'],
            "status"        => $r['lot_status'],
            "latestOrDate"  => $r['latest_or_date'],
            "rptUpdated"    => $r['rpt_updated'],
        ];
    }
    $stmt->close();
    $db->close();
    sendJSON([
        "lots" => $rows, "total" => $total, "page" => $page, "pageSize" => $pageSize,
        "totalPages" => max(1, (int)ceil($total / $pageSize)),
    ]);
}

// Level 4: detalye ng isang Lot + lahat ng OR history dun — dalawang
// pinagmumulan: (1) lot_or_history (na-import galing sa CRC Excel files,
// per-year na breakdown), at (2) liaison_records (mga OR na naitala sa
// pamamagitan ng Liaison module). Pinagsasama at pinapasort ng magkasama
// para makumpleto ang buong OR history ng isang lot kahit saan galing.
function getSubdivisionMonitorLotDetail($body) {
    $db = getDB();
    ensureLotInventoryTable($db);
    ensureLiaisonLotLinkColumn($db);

    $lotInventoryId = isset($body['lotInventoryId']) ? (int)$body['lotInventoryId'] : 0;
    if ($lotInventoryId <= 0) sendJSON(["error" => "Invalid lot."]);

    $stmt = $db->prepare("SELECT * FROM lot_inventory WHERE id = ?");
    $stmt->bind_param("i", $lotInventoryId);
    $stmt->execute();
    $lot = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$lot) sendJSON(["error" => "Lot not found."]);

    $orHistory = [];

    // Source 1: lot_or_history (imported CRC data)
    $stmt = $db->prepare("
        SELECT * FROM lot_or_history
        WHERE lot_inventory_id = ?
        ORDER BY yr DESC, id DESC
    ");
    $stmt->bind_param("i", $lotInventoryId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        // Laktawan ang mga "placeholder" row: walang amount (0 o blangko)
        // ay hindi talaga isang na-issue na OR, kundi marker lang na
        // "for request"/pending pa sa source data — kaya minsan garbled din
        // ang laman ng or_number column nito (hal. "-2025-42525").
        if (empty($r['amount']) || (float)$r['amount'] <= 0) continue;
        $orHistory[] = [
            "source"      => "import",
            "orNumber"    => $r['or_number'],
            "date"        => $r['or_date'],
            "yearCovered" => $r['yr'] ? ($r['fr'] && $r['to'] ? $r['yr'] . " (" . $r['fr'] . "-" . $r['to'] . ")" : (string)$r['yr']) : null,
            "sortYear"    => $r['yr'] ? (int)$r['yr'] : 0,
            "particulars" => $r['remarks'] ?: "Real Property Tax",
            "amount"      => $r['amount'],
            "modeMc"      => $r['mc_liaison'] ?: ($r['as_no'] ?: $r['jv_no']),
            "hasFile"     => false,
        ];
    }
    $stmt->close();

    // Source 2: liaison_records (mga OR na naitala via Liaison module)
    $stmt = $db->prepare("
        SELECT * FROM liaison_records
        WHERE lot_inventory_id = ? AND or_no IS NOT NULL AND or_no <> ''
        ORDER BY date_saved DESC, id DESC
    ");
    $stmt->bind_param("i", $lotInventoryId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $modeMc = "Cash";
        if (!empty($r['remarks']) && preg_match('/MC#\s*[\w-]+/i', $r['remarks'], $m)) $modeMc = strtoupper($m[0]);
        $orHistory[] = [
            "source"      => "liaison",
            "orNumber"    => $r['or_no'],
            "date"        => $r['or_date'],
            "yearCovered" => $r['or_yr_covered'] ?: $r['yr_covered'],
            "sortYear"    => (int)preg_replace('/\D/', '', substr($r['or_yr_covered'] ?: $r['yr_covered'] ?: '0', 0, 4)),
            "particulars" => $r['status_remarks'] ?: ($r['remarks'] ?: "Real Property Tax"),
            "amount"      => $r['or_amount'],
            "modeMc"      => $modeMc,
            "hasFile"     => !empty($r['image_path']),
        ];
    }
    $stmt->close();

    // I-dedupe base sa OR Number: kung ang isang OR ay nakapasok na sa
    // imported CRC data (Source 1), huwag na ulit isama ang parehong OR
    // mula sa Liaison records (Source 2) — dito nagmumula ang "dobol" na
    // OR entry. Priority ang imported record (mas kompleto ang year-block
    // info nito); ang Liaison version na lang ng parehong OR ang tatanggalin.
    $seenOrNumbers = [];
    foreach ($orHistory as $r) {
        if ($r['source'] === 'import' && !empty($r['orNumber'])) {
            $seenOrNumbers[_smNormalizeOrNumber($r['orNumber'])] = true;
        }
    }
    $orHistory = array_values(array_filter($orHistory, function($r) use ($seenOrNumbers) {
        if ($r['source'] !== 'liaison' || empty($r['orNumber'])) return true;
        return !isset($seenOrNumbers[_smNormalizeOrNumber($r['orNumber'])]);
    }));

    usort($orHistory, function($a, $b) {
        return $b['sortYear'] <=> $a['sortYear'];
    });

    $db->close();
    sendJSON(["lot" => $lot, "orHistory" => $orHistory]);
}

// Update-from-Excel: tumatanggap ng rows na EKSAKTONG parehong columns
// ng "Export to Excel" (isang row bawat OR entry, may lot info sa bawat
// row) — ginagawa itong pareho sa Update kapag na-edit sa Excel ang
// exported file, tapos ini-upload ulit dito. WALANG bagong lot na
// ginagawa dito (update lang sa existing lots) — kung gusto ring
// mag-import ng BAGONG lot, gamitin ang "Import Lot Data" (import_lot_full.php).
function importSubdivisionMonitorUpdate($body) {
    set_time_limit(0);
    @ini_set('memory_limit', '512M');
    $db = getDB();
    ensureLotInventoryTable($db);
    ensureLotInventoryMatchKey($db);
    $db->query("
        CREATE TABLE IF NOT EXISTS lot_or_history (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            lot_inventory_id INT NOT NULL,
            yr               INT NOT NULL,
            as_no            VARCHAR(100) DEFAULT NULL,
            jv_no            VARCHAR(100) DEFAULT NULL,
            mc_liaison       VARCHAR(100) DEFAULT NULL,
            or_number        VARCHAR(100) DEFAULT NULL,
            fr               VARCHAR(50)  DEFAULT NULL,
            `to`             VARCHAR(50)  DEFAULT NULL,
            amount           DECIMAL(15,2) DEFAULT NULL,
            or_date          VARCHAR(50)  DEFAULT NULL,
            remarks          VARCHAR(255) DEFAULT NULL,
            source           VARCHAR(50)  DEFAULT 'crc_import',
            UNIQUE KEY uniq_lot_year_or (lot_inventory_id, yr, or_number),
            INDEX idx_lot (lot_inventory_id)
        )
    ");

    $rows = $body['rows'] ?? [];
    if (!is_array($rows) || !count($rows)) sendJSON(["error" => "Walang rows na na-receive."]);

    $findByRA  = $db->prepare("SELECT id FROM lot_inventory WHERE ra_number = ? LIMIT 1");
    $findByKey = $db->prepare("SELECT id FROM lot_inventory WHERE sm_key = ? LIMIT 1");
    $updLot = $db->prepare("
        UPDATE lot_inventory
        SET buyers_name      = IF(?='', buyers_name, ?),
            lot_owner        = IF(?='', lot_owner, ?),
            tct_no           = IF(?='', tct_no, ?),
            transferred_tct  = IF(?='', transferred_tct, ?),
            assessed_value   = IF(?='', assessed_value, ?),
            status           = IF(?='', status, ?),
            td_no_latest     = IF(?='', td_no_latest, ?),
            td_no_old        = IF(?='', td_no_old, ?)
        WHERE id = ?
    ");
    $insOr = $db->prepare("
        INSERT INTO lot_or_history (lot_inventory_id, yr, or_number, amount, or_date, remarks, mc_liaison)
        VALUES (?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE amount=VALUES(amount), or_date=VALUES(or_date), remarks=VALUES(remarks), mc_liaison=VALUES(mc_liaison)
    ");

    if (!$findByRA || !$findByKey || !$updLot || !$insOr) {
        sendJSON(["error" => "Query preparation failed: " . $db->error]);
    }

    $updated = 0; $notFound = 0; $orUpdated = 0; $notFoundList = [];
    $seenLotIds = [];

    foreach ($rows as $row) {
        $ra  = trim($row['raNumber'] ?? '');
        $sub = trim($row['sub'] ?? ''); $ph = trim($row['ph'] ?? '');
        $blk = trim($row['blk'] ?? ''); $lot = trim($row['lot'] ?? '');

        $lotId = 0;
        if ($ra !== '') {
            $findByRA->bind_param("s", $ra);
            $findByRA->execute();
            $r = $findByRA->get_result()->fetch_assoc();
            if ($r) $lotId = (int)$r['id'];
        }
        if (!$lotId && $sub !== '' && $ph !== '' && $blk !== '' && $lot !== '') {
            $key = _smNormalize($sub) . '|' . _smNormalize($ph) . '|' . _smNormalizePart($blk) . '|' . _smNormalizePart($lot);
            $findByKey->bind_param("s", $key);
            $findByKey->execute();
            $r = $findByKey->get_result()->fetch_assoc();
            if ($r) $lotId = (int)$r['id'];
        }

        if (!$lotId) {
            $notFound++;
            if (count($notFoundList) < 200) $notFoundList[] = $ra ?: "$sub/$ph/$blk/$lot";
            continue;
        }

        // I-update ang lot info isang beses lang bawat lot (paulit-ulit
        // ang parehong lot info sa bawat row dahil isang row bawat OR entry).
        if (!isset($seenLotIds[$lotId])) {
            $seenLotIds[$lotId] = true;
            $buyersName    = trim($row['buyersName'] ?? '');
            $lotOwner      = trim($row['lotOwner'] ?? '');
            $tctNo         = trim($row['tctNo'] ?? '');
            $prevTctNo     = trim($row['previousTctNo'] ?? '');
            $avRaw         = trim((string)($row['assessedValue'] ?? ''));
            $av            = ($avRaw === '' || $avRaw === '0') ? '' : number_format((float)$avRaw, 2, '.', '');
            $status        = trim($row['lotStatus'] ?? ($row['status'] ?? ''));
            $tdNo          = trim($row['tdNo'] ?? '');
            $prevTdNo      = trim($row['previousTdNo'] ?? '');

            $updLot->bind_param(
                "ssssssssssssssssi",
                $buyersName,$buyersName, $lotOwner,$lotOwner, $tctNo,$tctNo, $prevTctNo,$prevTctNo,
                $av,$av, $status,$status, $tdNo,$tdNo, $prevTdNo,$prevTdNo,
                $lotId
            );
            $updLot->execute();
            $updated++;
        }

        // OR history row (kung meron)
        $orNumber = trim($row['orNumber'] ?? '');
        if ($orNumber !== '') {
            $yearCovered = trim((string)($row['yearCovered'] ?? ''));
            preg_match('/\d{4}/', $yearCovered, $ym);
            $yr = $ym ? (int)$ym[0] : (int)date('Y');
            $amountRaw = trim((string)($row['amount'] ?? ''));
            $amount = $amountRaw === '' ? null : (float)$amountRaw;
            $orDate = trim($row['orDate'] ?? '');
            $particulars = trim($row['particulars'] ?? '');
            $modeMc = trim($row['modeMc'] ?? '');

            $insOr->bind_param("iisdsss", $lotId, $yr, $orNumber, $amount, $orDate, $particulars, $modeMc);
            $insOr->execute();
            $orUpdated++;
        }
    }

    $findByRA->close(); $findByKey->close(); $updLot->close(); $insOr->close();
    $db->close();

    sendJSON([
        "success"      => true,
        "lotsUpdated"  => count($seenLotIds),
        "orUpdated"    => $orUpdated,
        "notFound"     => $notFound,
        "notFoundList" => $notFoundList,
    ]);
}