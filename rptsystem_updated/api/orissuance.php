<?php
// ============================================
// orissuance.php — OR Issuance module
// Records issued Official Receipts (MC#, OR#, From/To Quarter,
// Amount, OR Date), linked to a Lot via Subd/Ph/Blk/Lot
// (same lookup used by Subdivision Monitor / Lot Inventory).
// ============================================

function ensureOrIssuanceTable($db) {
    $db->query("
        CREATE TABLE IF NOT EXISTS or_issuance (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            ra_number       VARCHAR(100) DEFAULT NULL,
            subd            VARCHAR(50)  DEFAULT NULL,
            ph              VARCHAR(20)  DEFAULT NULL,
            blk             VARCHAR(20)  DEFAULT NULL,
            lot             VARCHAR(20)  DEFAULT NULL,
            buyer           VARCHAR(150) DEFAULT NULL,
            mc_no           VARCHAR(100) DEFAULT NULL,
            or_number       VARCHAR(100) DEFAULT NULL,
            yr              INT          DEFAULT NULL,
            from_quarter    TINYINT      DEFAULT NULL,
            to_quarter      TINYINT      DEFAULT NULL,
            amount          DECIMAL(15,2) DEFAULT NULL,
            or_date         VARCHAR(20)  DEFAULT NULL,
            created_by      VARCHAR(100) DEFAULT NULL,
            date_saved      DATETIME DEFAULT NOW(),
            INDEX idx_or_subd (subd, ph, blk, lot)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // Kung existing na table (walang ra_number pa), i-add nang hindi nagra-error
    $col = $db->query("SHOW COLUMNS FROM or_issuance LIKE 'ra_number'");
    if ($col && $col->num_rows === 0) {
        $db->query("ALTER TABLE or_issuance ADD COLUMN ra_number VARCHAR(100) DEFAULT NULL AFTER id");
    }
    // Explicit Year field — user-entered, hindi na dependent sa pag-parse ng
    // or_date string (na iba-iba ang format), para tama palagi ang batayan
    // ng "RPT Updated" column sa Subdivision Monitor.
    $colYr = $db->query("SHOW COLUMNS FROM or_issuance LIKE 'yr'");
    if ($colYr && $colYr->num_rows === 0) {
        $db->query("ALTER TABLE or_issuance ADD COLUMN yr INT DEFAULT NULL AFTER or_number");
    }
    // Link papunta sa lot_inventory para makita ang OR na ito sa
    // "OR History" ng Subdivision Monitor (getSubdivisionMonitorLotDetail).
    $col2 = $db->query("SHOW COLUMNS FROM or_issuance LIKE 'lot_inventory_id'");
    if ($col2 && $col2->num_rows === 0) {
        $db->query("ALTER TABLE or_issuance ADD COLUMN lot_inventory_id INT DEFAULT NULL, ADD INDEX idx_or_lot_inventory_id (lot_inventory_id)");
        // One-time backfill: i-link ang mga existing OR Issuance record
        // (na-save bago idagdag ang column na ito) sa kanilang lot, gamit
        // muna ang RA#, tapos fallback sa Subd/Ph/Blk/Lot match key.
        ensureLotInventoryMatchKey($db);
        $db->query("
            UPDATE or_issuance oi
            JOIN lot_inventory li ON li.ra_number = oi.ra_number AND oi.ra_number <> ''
            SET oi.lot_inventory_id = li.id
            WHERE oi.lot_inventory_id IS NULL
        ");
        $db->query("
            UPDATE or_issuance oi
            JOIN lot_inventory li
              ON li.sm_key = CONCAT(UPPER(TRIM(oi.subd)), '|', UPPER(TRIM(oi.ph)), '|',
                    IF(TRIM(oi.blk) REGEXP '^[0-9]+$', CAST(TRIM(oi.blk) AS UNSIGNED), UPPER(TRIM(oi.blk))), '|',
                    IF(TRIM(oi.lot) REGEXP '^[0-9]+$', CAST(TRIM(oi.lot) AS UNSIGNED), UPPER(TRIM(oi.lot))))
            SET oi.lot_inventory_id = li.id
            WHERE oi.lot_inventory_id IS NULL AND oi.subd <> '' AND oi.ph <> '' AND oi.blk <> '' AND oi.lot <> ''
        ");
    }
    // AS# — reference number ng Accounting Summary/AS with MC, para
    // maka-link/makita ang OR issuance record na ito sa AS# na kaugnay.
    $col3 = $db->query("SHOW COLUMNS FROM or_issuance LIKE 'as_number'");
    if ($col3 && $col3->num_rows === 0) {
        $db->query("ALTER TABLE or_issuance ADD COLUMN as_number VARCHAR(100) DEFAULT NULL AFTER ra_number, ADD INDEX idx_or_as_number (as_number)");
    }
}

function ensureOrIssuanceActivityLogTable($db) {
    $db->query("
        CREATE TABLE IF NOT EXISTS or_issuance_activity_log (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            or_id         INT NOT NULL,
            action        VARCHAR(20) NOT NULL,
            field_name    VARCHAR(50)  DEFAULT NULL,
            old_value     VARCHAR(255) DEFAULT NULL,
            new_value     VARCHAR(255) DEFAULT NULL,
            note          VARCHAR(255) DEFAULT NULL,
            changed_by    VARCHAR(100) DEFAULT NULL,
            changed_at    DATETIME DEFAULT NOW(),
            INDEX idx_or_id (or_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function orIssuanceFieldLabels() {
    return [
        'ra_number'    => 'RA#',
        'as_number'    => 'AS#',
        'subd'         => 'Subd.',
        'ph'           => 'Phase',
        'blk'          => 'Block',
        'lot'          => 'Lot',
        'buyer'        => "Buyer's Name",
        'mc_no'        => 'MC#',
        'or_number'    => 'OR Number',
        'yr'           => 'Year',
        'from_quarter' => 'From',
        'to_quarter'   => 'To',
        'amount'       => 'Amount',
        'or_date'      => 'OR Date',
    ];
}

function _orQuarterLabel($q) {
    $q = (int)$q;
    $names = [1 => '1st Quarter', 2 => '2nd Quarter', 3 => '3rd Quarter', 4 => '4th Quarter'];
    return $names[$q] ?? null;
}

function logOrIssuanceActivity($db, $orId, $action, $changedBy, $fieldName = null, $oldValue = null, $newValue = null, $note = null) {
    ensureOrIssuanceActivityLogTable($db);
    $stmt = $db->prepare("INSERT INTO or_issuance_activity_log (or_id, action, field_name, old_value, new_value, note, changed_by, changed_at) VALUES (?,?,?,?,?,?,?,NOW())");
    $stmt->bind_param("issssss", $orId, $action, $fieldName, $oldValue, $newValue, $note, $changedBy);
    $stmt->execute();
    $stmt->close();
}

function getOrIssuanceActivityLog($body) {
    $db = getDB();
    ensureOrIssuanceActivityLogTable($db);
    $orId = isset($body['orId']) ? (int)$body['orId'] : 0;
    if ($orId <= 0) sendJSON(["error" => "Invalid record ID"]);
    $stmt = $db->prepare("
        SELECT action, field_name, old_value, new_value, note, changed_by,
               DATE_FORMAT(changed_at, '%Y-%m-%dT%H:%i:%s') AS changed_at
        FROM or_issuance_activity_log
        WHERE or_id = ?
        ORDER BY changed_at DESC, id DESC
    ");
    $stmt->bind_param("i", $orId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    $db->close();
    sendJSON(["log" => $rows, "labels" => orIssuanceFieldLabels()]);
}

function getOrIssuanceRecords() {
    $db = getDB();
    ensureOrIssuanceTable($db);
    $result = $db->query("SELECT * FROM or_issuance ORDER BY id DESC");
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $row['from_quarter_label'] = _orQuarterLabel($row['from_quarter']);
        $row['to_quarter_label']   = _orQuarterLabel($row['to_quarter']);
        $rows[] = $row;
    }
    $db->close();
    sendJSON(["records" => $rows]);
}

function _orResolveLotInventoryId($db, $raNumber, $subd, $ph, $blk, $lot) {
    if ($raNumber !== '') {
        $stmt = $db->prepare("SELECT id FROM lot_inventory WHERE ra_number = ? LIMIT 1");
        $stmt->bind_param("s", $raNumber);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($r) return (int)$r['id'];
    }
    if ($subd !== '' && $ph !== '' && $blk !== '' && $lot !== '') {
        ensureLotInventoryMatchKey($db);
        $key = _smNormalize($subd) . '|' . _smNormalize($ph) . '|' . _smNormalizePart($blk) . '|' . _smNormalizePart($lot);
        $stmt = $db->prepare("SELECT id FROM lot_inventory WHERE sm_key = ? LIMIT 1");
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($r) return (int)$r['id'];
    }
    return 0;
}

function saveOrIssuanceRecord($body) {
    $db = getDB();
    ensureOrIssuanceTable($db);

    $id          = isset($body['id']) ? (int)$body['id'] : 0;
    $raNumber    = trim($body['raNumber'] ?? '');
    $asNumber    = trim($body['asNumber'] ?? '');
    $subd        = trim($body['subd'] ?? '');
    $ph          = trim($body['ph'] ?? '');
    $blk         = trim($body['blk'] ?? '');
    $lot         = trim($body['lot'] ?? '');
    $buyer       = trim($body['buyer'] ?? '');
    $mcNo        = trim($body['mcNo'] ?? '');
    $orNumber    = trim($body['orNumber'] ?? '');
    $yrRaw       = trim((string)($body['yr'] ?? ''));
    $yr          = ($yrRaw !== '' && preg_match('/^\d{4}$/', $yrRaw)) ? (int)$yrRaw : null;
    $fromQuarter = isset($body['fromQuarter']) && $body['fromQuarter'] !== '' ? (int)$body['fromQuarter'] : null;
    $toQuarter   = isset($body['toQuarter'])   && $body['toQuarter']   !== '' ? (int)$body['toQuarter']   : null;
    $amountRaw   = trim((string)($body['amount'] ?? ''));
    $amount      = $amountRaw === '' ? null : (float)$amountRaw;
    $orDate      = trim($body['orDate'] ?? '');
    $createdBy   = currentSessionUsername() ?? '';

    if ($fromQuarter !== null && ($fromQuarter < 1 || $fromQuarter > 4)) $fromQuarter = null;
    if ($toQuarter   !== null && ($toQuarter   < 1 || $toQuarter   > 4)) $toQuarter   = null;

    if (empty($orNumber) && empty($subd) && empty($buyer)) {
        sendJSON(["error" => "OR Number, Subd., or Buyer's Name is required."]);
    }

    $lotInventoryId = _orResolveLotInventoryId($db, $raNumber, $subd, $ph, $blk, $lot);

    $oldRow = null;
    if ($id > 0) {
        $oldStmt = $db->prepare("SELECT * FROM or_issuance WHERE id = ?");
        $oldStmt->bind_param("i", $id);
        $oldStmt->execute();
        $oldRow = $oldStmt->get_result()->fetch_assoc();
        $oldStmt->close();
    }

    if ($id > 0) {
        $stmt = $db->prepare("UPDATE or_issuance SET ra_number=?, as_number=?, subd=?, ph=?, blk=?, lot=?, buyer=?, mc_no=?, or_number=?, yr=?, from_quarter=?, to_quarter=?, amount=?, or_date=?, lot_inventory_id=? WHERE id=?");
        $stmt->bind_param("sssssssssiiidsii", $raNumber, $asNumber, $subd, $ph, $blk, $lot, $buyer, $mcNo, $orNumber, $yr, $fromQuarter, $toQuarter, $amount, $orDate, $lotInventoryId, $id);
    } else {
        $stmt = $db->prepare("INSERT INTO or_issuance (ra_number, as_number, subd, ph, blk, lot, buyer, mc_no, or_number, yr, from_quarter, to_quarter, amount, or_date, lot_inventory_id, created_by, date_saved) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
        $stmt->bind_param("sssssssssiiidsis", $raNumber, $asNumber, $subd, $ph, $blk, $lot, $buyer, $mcNo, $orNumber, $yr, $fromQuarter, $toQuarter, $amount, $orDate, $lotInventoryId, $createdBy);
    }

    if ($stmt->execute()) {
        $newId = $id > 0 ? $id : $db->insert_id;

        if ($id > 0 && $oldRow) {
            $newValues = [
                'ra_number' => $raNumber, 'as_number' => $asNumber, 'subd' => $subd, 'ph' => $ph, 'blk' => $blk, 'lot' => $lot, 'buyer' => $buyer,
                'mc_no' => $mcNo, 'or_number' => $orNumber, 'yr' => $yr, 'from_quarter' => $fromQuarter,
                'to_quarter' => $toQuarter, 'amount' => $amount, 'or_date' => $orDate,
            ];
            foreach ($newValues as $field => $newVal) {
                $oldVal = isset($oldRow[$field]) ? (string)$oldRow[$field] : '';
                if (trim($oldVal) !== trim((string)$newVal)) {
                    logOrIssuanceActivity($db, $newId, 'updated', $createdBy, $field, $oldVal, $newVal);
                }
            }
        } else {
            logOrIssuanceActivity($db, $newId, 'created', $createdBy, null, null, null, "Record created (OR#: {$orNumber}, Subd: {$subd}, Buyer: {$buyer})");
        }

        sendJSON(["success" => true, "id" => $newId]);
    } else {
        sendJSON(["error" => $stmt->error]);
    }
    $stmt->close();
    $db->close();
}

function deleteOrIssuanceRecord($body) {
    $db = getDB();
    ensureOrIssuanceTable($db);
    $id = isset($body['id']) ? (int)$body['id'] : 0;
    $changedBy = currentSessionUsername() ?? '';
    if ($id <= 0) sendJSON(["error" => "Invalid ID"]);

    $oldStmt = $db->prepare("SELECT subd, or_number, buyer FROM or_issuance WHERE id = ?");
    $oldStmt->bind_param("i", $id);
    $oldStmt->execute();
    $oldRow = $oldStmt->get_result()->fetch_assoc();
    $oldStmt->close();

    $stmt = $db->prepare("DELETE FROM or_issuance WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            if ($oldRow) {
                logOrIssuanceActivity($db, $id, 'deleted', $changedBy, null, null, null, "Record deleted (OR#: {$oldRow['or_number']}, Subd: {$oldRow['subd']}, Buyer: {$oldRow['buyer']})");
            }
            sendJSON(["success" => true, "deleted_id" => $id]);
        }
        else sendJSON(["error" => "Record not found (ID: $id)"]);
    } else {
        sendJSON(["error" => $stmt->error]);
    }
    $stmt->close();
    $db->close();
}