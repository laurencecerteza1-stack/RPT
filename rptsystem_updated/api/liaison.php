<?php
function ensureLiaisonRecordsTable($db) {
    $db->query("
        CREATE TABLE IF NOT EXISTS liaison_records (
            id                  INT AUTO_INCREMENT PRIMARY KEY,
            liaison_name        VARCHAR(100) DEFAULT NULL,
            date_requested      VARCHAR(20)  DEFAULT NULL,
            ra_no               VARCHAR(100) DEFAULT NULL,
            buyer               VARCHAR(150) DEFAULT NULL,
            subd                VARCHAR(50)  DEFAULT NULL,
            ph                  VARCHAR(20)  DEFAULT NULL,
            blk                 VARCHAR(20)  DEFAULT NULL,
            lot                 VARCHAR(20)  DEFAULT NULL,
            description         VARCHAR(150) DEFAULT NULL,
            tct                 VARCHAR(100) DEFAULT NULL,
            pin_no              VARCHAR(100) DEFAULT NULL,
            td_no               VARCHAR(100) DEFAULT NULL,
            yr_covered          VARCHAR(50)  DEFAULT NULL,
            amount              DECIMAL(15,2) DEFAULT 0,
            owner               VARCHAR(150) DEFAULT NULL,
            remarks             VARCHAR(255) DEFAULT NULL,
            or_no               VARCHAR(50)  DEFAULT NULL,
            or_yr_covered       VARCHAR(50)  DEFAULT NULL,
            or_amount           DECIMAL(15,2) DEFAULT 0,
            or_date             VARCHAR(20)  DEFAULT NULL,
            date_received       VARCHAR(20)  DEFAULT NULL,
            status_remarks      VARCHAR(255) DEFAULT NULL,
            date_saved          DATETIME DEFAULT NOW(),
            created_by          VARCHAR(100) DEFAULT NULL,
            image_path          VARCHAR(255) DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // Kung existing na table (walang image_path pa), i-add nang hindi nagra-error
    $col = $db->query("SHOW COLUMNS FROM liaison_records LIKE 'image_path'");
    if ($col && $col->num_rows === 0) {
        $db->query("ALTER TABLE liaison_records ADD COLUMN image_path VARCHAR(255) DEFAULT NULL");
    }
    // Normalized+indexed generated column (UPPER+TRIM ng ra_no) para mabilis at
    // case/space-insensitive ang pagtutugma sa Computation list. Chine-check muna
    // kung wala pa bago i-ALTER (malaking table, ayaw nating paulit-ulit itong subukan).
    $normCol = $db->query("SHOW COLUMNS FROM liaison_records LIKE 'ra_no_norm'");
    if ($normCol && $normCol->num_rows === 0) {
        @$db->query("ALTER TABLE liaison_records ADD COLUMN ra_no_norm VARCHAR(100) COLLATE utf8mb4_general_ci GENERATED ALWAYS AS (UPPER(TRIM(ra_no))) STORED, ADD INDEX idx_ra_no_norm (ra_no_norm)");
    }
    // NOTE: idx_ra_no index dapat gawin nang MANUAL (isang beses lang) sa MySQL
    // command line, HINDI dito sa runtime — malaking table kaya pwede mag-timeout
    // ang ALTER TABLE kung sa loob ng PHP request request ito ginawa. Tingnan ang
    // README_LIAISON_INDEX.txt / instructions mula kay Claude para sa SQL command.
}

// ============================================
// LIAISON ACTIVITY LOG (audit trail per record)
// ============================================
function ensureLiaisonActivityLogTable($db) {
    $db->query("
        CREATE TABLE IF NOT EXISTS liaison_activity_log (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            liaison_id    INT NOT NULL,
            action        VARCHAR(20) NOT NULL,
            field_name    VARCHAR(50)  DEFAULT NULL,
            old_value     VARCHAR(255) DEFAULT NULL,
            new_value     VARCHAR(255) DEFAULT NULL,
            note          VARCHAR(255) DEFAULT NULL,
            changed_by    VARCHAR(100) DEFAULT NULL,
            changed_at    DATETIME DEFAULT NOW(),
            INDEX idx_liaison_id (liaison_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// Human-readable labels for logged fields
function liaisonFieldLabels() {
    return [
        'liaison_name'   => 'Liaison Name',
        'date_requested' => 'Date Requested',
        'ra_no'          => 'RA#',
        'buyer'          => "Buyer's Name",
        'subd'           => 'Subdivision',
        'ph'             => 'Phase',
        'blk'            => 'Block',
        'lot'            => 'Lot',
        'description'    => 'Description',
        'tct'            => 'TCT',
        'pin_no'         => 'PIN No.',
        'td_no'          => 'TD No.',
        'yr_covered'     => 'Yr Covered',
        'amount'         => 'Amount',
        'owner'          => 'Owner',
        'remarks'        => 'Remarks',
        'or_no'          => 'OR#',
        'or_yr_covered'  => 'OR Yr Covered',
        'or_amount'      => 'OR Amount',
        'or_date'        => 'OR Date',
        'date_received'  => 'Date Received',
        'status_remarks' => 'Status/Remarks',
    ];
}

function logLiaisonActivity($db, $liaisonId, $action, $changedBy, $fieldName = null, $oldValue = null, $newValue = null, $note = null) {
    ensureLiaisonActivityLogTable($db);
    $stmt = $db->prepare("INSERT INTO liaison_activity_log (liaison_id, action, field_name, old_value, new_value, note, changed_by, changed_at) VALUES (?,?,?,?,?,?,?,NOW())");
    $stmt->bind_param("issssss", $liaisonId, $action, $fieldName, $oldValue, $newValue, $note, $changedBy);
    $stmt->execute();
    $stmt->close();
}

function getLiaisonActivityLog($body) {
    $db = getDB();
    ensureLiaisonActivityLogTable($db);
    $liaisonId = isset($body['liaisonId']) ? (int)$body['liaisonId'] : 0;
    if ($liaisonId <= 0) sendJSON(["error" => "Invalid record ID"]);
    $stmt = $db->prepare("
        SELECT action, field_name, old_value, new_value, note, changed_by,
               DATE_FORMAT(changed_at, '%Y-%m-%dT%H:%i:%s') AS changed_at
        FROM liaison_activity_log
        WHERE liaison_id = ?
        ORDER BY changed_at DESC, id DESC
    ");
    $stmt->bind_param("i", $liaisonId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    $db->close();
    sendJSON(["log" => $rows, "labels" => liaisonFieldLabels()]);
}

function ensureLiaisonAttachmentsTable($db) {
    $db->query("
        CREATE TABLE IF NOT EXISTS liaison_attachments (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            liaison_id    INT NOT NULL,
            file_path     VARCHAR(255) NOT NULL,
            uploaded_at   DATETIME DEFAULT NOW(),
            INDEX (liaison_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function getAllLiaisonAttachments() {
    $db = getDB();
    ensureLiaisonRecordsTable($db);
    ensureLiaisonAttachmentsTable($db);

    $rows = [];

    // Main attachment stored directly on the liaison record
    $res = $db->query("SELECT id, ra_no, buyer, liaison_name, image_path, date_saved FROM liaison_records WHERE image_path IS NOT NULL AND image_path != ''");
    while ($r = $res->fetch_assoc()) {
        $rows[] = [
            "liaison_id" => $r['id'],
            "ra_no" => $r['ra_no'],
            "buyer" => $r['buyer'],
            "liaison_name" => $r['liaison_name'],
            "file_path" => $r['image_path'],
            "uploaded_at" => $r['date_saved'],
            "source" => "main"
        ];
    }

    // Additional attachments
    $res2 = $db->query("
        SELECT a.id, a.file_path, a.uploaded_at, l.id AS liaison_id, l.ra_no, l.buyer, l.liaison_name
        FROM liaison_attachments a
        JOIN liaison_records l ON l.id = a.liaison_id
    ");
    while ($r = $res2->fetch_assoc()) {
        $rows[] = [
            "liaison_id" => $r['liaison_id'],
            "ra_no" => $r['ra_no'],
            "buyer" => $r['buyer'],
            "liaison_name" => $r['liaison_name'],
            "file_path" => $r['file_path'],
            "uploaded_at" => $r['uploaded_at'],
            "source" => "extra"
        ];
    }

    usort($rows, function($a, $b) { return strtotime($b['uploaded_at'] ?? '1970-01-01') <=> strtotime($a['uploaded_at'] ?? '1970-01-01'); });

    $db->close();
    sendJSON(["rows" => $rows, "total" => count($rows)]);
}

function getLiaisonAttachments($body) {
    $db = getDB();
    ensureLiaisonAttachmentsTable($db);
    $liaisonId = intval($body['liaisonId'] ?? 0);
    if ($liaisonId <= 0) sendJSON(["rows" => []]);
    $stmt = $db->prepare("SELECT id, file_path, uploaded_at FROM liaison_attachments WHERE liaison_id = ? ORDER BY id ASC");
    $stmt->bind_param("i", $liaisonId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    $db->close();
    sendJSON(["rows" => $rows]);
}

function addLiaisonAttachment($body) {
    $db = getDB();
    ensureLiaisonAttachmentsTable($db);
    $liaisonId = intval($body['liaisonId'] ?? 0);
    $filePath = trim($body['filePath'] ?? '');
    if ($liaisonId <= 0 || $filePath === '') sendJSON(["error" => "Missing liaisonId or filePath."]);
    $stmt = $db->prepare("INSERT INTO liaison_attachments (liaison_id, file_path) VALUES (?, ?)");
    $stmt->bind_param("is", $liaisonId, $filePath);
    if ($stmt->execute()) {
        sendJSON(["success" => true, "id" => $stmt->insert_id]);
    } else {
        sendJSON(["error" => $stmt->error]);
    }
    $stmt->close();
    $db->close();
}

function deleteLiaisonAttachment($body) {
    $db = getDB();
    ensureLiaisonAttachmentsTable($db);
    $id = intval($body['id'] ?? 0);
    if ($id <= 0) sendJSON(["error" => "Missing id."]);
    $stmt = $db->prepare("DELETE FROM liaison_attachments WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        sendJSON(["success" => true]);
    } else {
        sendJSON(["error" => $stmt->error]);
    }
    $stmt->close();
    $db->close();
}

function getLiaisonRecords() {
    $db = getDB();
    ensureLiaisonRecordsTable($db);
    ensureLiaisonAttachmentsTable($db);
    $result = $db->query("SELECT * FROM liaison_records ORDER BY id DESC");
    $rows = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;

    // Count ng extra attachments per record (para may makitang total sa listahan)
    $countMap = [];
    $cntRes = $db->query("SELECT liaison_id, COUNT(*) AS cnt FROM liaison_attachments GROUP BY liaison_id");
    while ($c = $cntRes->fetch_assoc()) $countMap[$c['liaison_id']] = intval($c['cnt']);
    foreach ($rows as &$r) {
        $r['extra_attachment_count'] = $countMap[$r['id']] ?? 0;
    }
    unset($r);

    // Kunin lang ang avatars ng mga user na aktwal na may record dito, isang beses kada user
    // (imbis na i-JOIN per-row, na paulit-ulit na kokopyahin ang malaking avatar blob)
    $avatars = [];
    $usernames = array_values(array_unique(array_filter(array_map(fn($r) => $r['created_by'] ?? '', $rows))));
    if (!empty($usernames)) {
        $placeholders = implode(',', array_fill(0, count($usernames), '?'));
        $types = str_repeat('s', count($usernames));
        $stmt = $db->prepare("SELECT username, avatar FROM rpt_users WHERE username IN ($placeholders)");
        $stmt->bind_param($types, ...$usernames);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($u = $res->fetch_assoc()) $avatars[$u['username']] = $u['avatar'];
        $stmt->close();
    }

    $db->close();
    sendJSON(["records" => $rows, "avatars" => $avatars]);
}

function saveLiaisonRecord($body) {
    $db = getDB();
    ensureLiaisonRecordsTable($db);

    $id            = isset($body['id']) ? (int)$body['id'] : 0;
    $liaisonName   = trim($body['liaisonName'] ?? '');
    $dateRequested = trim($body['dateRequested'] ?? '');
    $raNo          = trim($body['raNo'] ?? '');
    $buyer         = trim($body['buyer'] ?? '');
    $subd          = trim($body['subd'] ?? '');
    $ph            = trim($body['ph'] ?? '');
    $blk           = trim($body['blk'] ?? '');
    $lot           = trim($body['lot'] ?? '');
    $description   = trim($body['description'] ?? '');
    $tct           = trim($body['tct'] ?? '');
    $pinNo         = trim($body['pinNo'] ?? '');
    $tdNo          = trim($body['tdNo'] ?? '');
    $yrCovered     = trim($body['yrCovered'] ?? '');
    $amount        = (float)str_replace(',', '', $body['amount'] ?? '0');
    $owner         = trim($body['owner'] ?? '');
    $remarks       = trim($body['remarks'] ?? '');
    $orNo          = trim($body['orNo'] ?? '');
    $orYrCovered   = trim($body['orYrCovered'] ?? '');
    $orAmount      = (float)str_replace(',', '', $body['orAmount'] ?? '0');
    $orDate        = trim($body['orDate'] ?? '');
    $dateReceived  = trim($body['dateReceived'] ?? '');
    $statusRemarks = trim($body['statusRemarks'] ?? '');
    $createdBy     = currentSessionUsername() ?? '';
    $imagePath     = trim($body['imagePath'] ?? '');
    $removeImage   = !empty($body['removeImage']);

    if (empty($raNo) && empty($buyer)) sendJSON(["error" => "RA# or Buyer's Name is required."]);

    $oldRow = null;
    if ($id > 0) {
        $oldStmt = $db->prepare("SELECT * FROM liaison_records WHERE id = ?");
        $oldStmt->bind_param("i", $id);
        $oldStmt->execute();
        $oldRow = $oldStmt->get_result()->fetch_assoc();
        $oldStmt->close();
    }

    if ($id > 0) {
        // Kung explicit na inalis ng user ang image, i-set talaga sa NULL
        if ($removeImage && $imagePath === '') {
            $stmt = $db->prepare("UPDATE liaison_records SET liaison_name=?, date_requested=?, ra_no=?, buyer=?, subd=?, ph=?, blk=?, lot=?, description=?, tct=?, pin_no=?, td_no=?, yr_covered=?, amount=?, owner=?, remarks=?, or_no=?, or_yr_covered=?, or_amount=?, or_date=?, date_received=?, status_remarks=?, image_path=NULL WHERE id=?");
            $stmt->bind_param("sssssssssssssdssssdsssi",
                $liaisonName, $dateRequested, $raNo, $buyer, $subd, $ph, $blk, $lot,
                $description, $tct, $pinNo, $tdNo, $yrCovered, $amount, $owner, $remarks,
                $orNo, $orYrCovered, $orAmount, $orDate, $dateReceived, $statusRemarks, $id
            );
        } else if ($imagePath === '') {
            $stmt = $db->prepare("UPDATE liaison_records SET liaison_name=?, date_requested=?, ra_no=?, buyer=?, subd=?, ph=?, blk=?, lot=?, description=?, tct=?, pin_no=?, td_no=?, yr_covered=?, amount=?, owner=?, remarks=?, or_no=?, or_yr_covered=?, or_amount=?, or_date=?, date_received=?, status_remarks=? WHERE id=?");
            $stmt->bind_param("sssssssssssssdssssdsssi",
                $liaisonName, $dateRequested, $raNo, $buyer, $subd, $ph, $blk, $lot,
                $description, $tct, $pinNo, $tdNo, $yrCovered, $amount, $owner, $remarks,
                $orNo, $orYrCovered, $orAmount, $orDate, $dateReceived, $statusRemarks, $id
            );
        } else {
            $stmt = $db->prepare("UPDATE liaison_records SET liaison_name=?, date_requested=?, ra_no=?, buyer=?, subd=?, ph=?, blk=?, lot=?, description=?, tct=?, pin_no=?, td_no=?, yr_covered=?, amount=?, owner=?, remarks=?, or_no=?, or_yr_covered=?, or_amount=?, or_date=?, date_received=?, status_remarks=?, image_path=? WHERE id=?");
            $stmt->bind_param("sssssssssssssdssssdssssi",
                $liaisonName, $dateRequested, $raNo, $buyer, $subd, $ph, $blk, $lot,
                $description, $tct, $pinNo, $tdNo, $yrCovered, $amount, $owner, $remarks,
                $orNo, $orYrCovered, $orAmount, $orDate, $dateReceived, $statusRemarks, $imagePath, $id
            );
        }
    } else {
        $stmt = $db->prepare("INSERT INTO liaison_records (liaison_name, date_requested, ra_no, buyer, subd, ph, blk, lot, description, tct, pin_no, td_no, yr_covered, amount, owner, remarks, or_no, or_yr_covered, or_amount, or_date, date_received, status_remarks, created_by, image_path, date_saved) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
        $stmt->bind_param("sssssssssssssdssssssssss",
            $liaisonName, $dateRequested, $raNo, $buyer, $subd, $ph, $blk, $lot,
            $description, $tct, $pinNo, $tdNo, $yrCovered, $amount, $owner, $remarks,
            $orNo, $orYrCovered, $orAmount, $orDate, $dateReceived, $statusRemarks, $createdBy, $imagePath
        );
    }

    if ($stmt->execute()) {
        $newId = $id > 0 ? $id : $db->insert_id;

        if ($id > 0 && $oldRow) {
            // Compare new values vs old row and log only the fields that actually changed
            $newValues = [
                'liaison_name' => $liaisonName, 'date_requested' => $dateRequested, 'ra_no' => $raNo,
                'buyer' => $buyer, 'subd' => $subd, 'ph' => $ph, 'blk' => $blk, 'lot' => $lot,
                'description' => $description, 'tct' => $tct, 'pin_no' => $pinNo, 'td_no' => $tdNo,
                'yr_covered' => $yrCovered, 'amount' => number_format($amount, 2, '.', ''),
                'owner' => $owner, 'remarks' => $remarks, 'or_no' => $orNo, 'or_yr_covered' => $orYrCovered,
                'or_amount' => number_format($orAmount, 2, '.', ''), 'or_date' => $orDate,
                'date_received' => $dateReceived, 'status_remarks' => $statusRemarks,
            ];
            foreach ($newValues as $field => $newVal) {
                $oldVal = isset($oldRow[$field]) ? (string)$oldRow[$field] : '';
                if ($field === 'amount' || $field === 'or_amount') $oldVal = number_format((float)$oldVal, 2, '.', '');
                if (trim($oldVal) !== trim((string)$newVal)) {
                    logLiaisonActivity($db, $newId, 'updated', $createdBy, $field, $oldVal, $newVal);
                }
            }
        } else {
            logLiaisonActivity($db, $newId, 'created', $createdBy, null, null, null, "Record created (RA# {$raNo}, Buyer: {$buyer})");
        }

        // Auto-match sa Lot Inventory (Subd./Phase/Blk./Lot) para sa Subdivision Monitor
        ensureLiaisonLotLinkColumn($db);
        ensureLotInventoryTable($db);
        ensureLotInventoryMatchKey($db);
        autoLinkLiaisonToLot($db, $newId, $subd, $ph, $blk, $lot);

        sendJSON(["success" => true, "id" => $newId]);
    } else {
        sendJSON(["error" => $stmt->error]);
    }
    $stmt->close();
    $db->close();
}

function bulkImportLiaisonRecords($body) {
    $db = getDB();
    ensureLiaisonRecordsTable($db);
    ensureLiaisonLotLinkColumn($db);
    ensureLotInventoryTable($db);
    ensureLotInventoryMatchKey($db);

    $records = $body['records'] ?? [];
    if (!is_array($records) || count($records) === 0) {
        sendJSON(["error" => "No records received."]);
    }

    $stmt = $db->prepare("INSERT INTO liaison_records (liaison_name, date_requested, ra_no, buyer, subd, ph, blk, lot, description, tct, pin_no, td_no, yr_covered, amount, owner, remarks, or_no, or_yr_covered, or_amount, or_date, date_received, status_remarks, created_by, date_saved) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");

    $inserted = 0;
    $errors = 0;
    foreach ($records as $r) {
        $liaisonName   = trim($r['liaisonName'] ?? '');
        $dateRequested = trim($r['dateRequested'] ?? '');
        $raNo          = trim($r['raNo'] ?? '');
        $buyer         = trim($r['buyer'] ?? '');
        $subd          = trim($r['subd'] ?? '');
        $ph            = trim($r['ph'] ?? '');
        $blk           = trim($r['blk'] ?? '');
        $lot           = trim($r['lot'] ?? '');
        $description   = trim($r['description'] ?? '');
        $tct           = trim($r['tct'] ?? '');
        $pinNo         = trim($r['pinNo'] ?? '');
        $tdNo          = trim($r['tdNo'] ?? '');
        $yrCovered     = trim($r['yrCovered'] ?? '');
        $amount        = (float)str_replace(',', '', $r['amount'] ?? '0');
        $owner         = trim($r['owner'] ?? '');
        $remarks       = trim($r['remarks'] ?? '');
        $orNo          = trim($r['orNo'] ?? '');
        $orYrCovered   = trim($r['orYrCovered'] ?? '');
        $orAmount      = (float)str_replace(',', '', $r['orAmount'] ?? '0');
        $orDate        = trim($r['orDate'] ?? '');
        $dateReceived  = trim($r['dateReceived'] ?? '');
        $statusRemarks = trim($r['statusRemarks'] ?? '');
        $createdBy     = trim($r['createdBy'] ?? '');

        if (empty($raNo) && empty($buyer)) { $errors++; continue; }

        $stmt->bind_param("ssssssssssssdssssssssss",
            $liaisonName, $dateRequested, $raNo, $buyer, $subd, $ph, $blk, $lot,
            $description, $tct, $pinNo, $tdNo, $yrCovered, $amount, $owner, $remarks,
            $orNo, $orYrCovered, $orAmount, $orDate, $dateReceived, $statusRemarks, $createdBy
        );
        if ($stmt->execute()) {
            $inserted++;
            autoLinkLiaisonToLot($db, $db->insert_id, $subd, $ph, $blk, $lot);
        } else {
            $errors++;
        }
    }
    $stmt->close();
    $db->close();
    sendJSON(["success" => true, "inserted" => $inserted, "errors" => $errors]);
}

function deleteLiaisonRecord($body) {
    $db = getDB();
    ensureLiaisonRecordsTable($db);
    $id = isset($body['id']) ? (int)$body['id'] : 0;
    $changedBy = currentSessionUsername() ?? '';
    if ($id <= 0) sendJSON(["error" => "Invalid ID"]);

    $oldStmt = $db->prepare("SELECT ra_no, buyer FROM liaison_records WHERE id = ?");
    $oldStmt->bind_param("i", $id);
    $oldStmt->execute();
    $oldRow = $oldStmt->get_result()->fetch_assoc();
    $oldStmt->close();

    $stmt = $db->prepare("DELETE FROM liaison_records WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            if ($oldRow) {
                logLiaisonActivity($db, $id, 'deleted', $changedBy, null, null, null, "Record deleted (RA# {$oldRow['ra_no']}, Buyer: {$oldRow['buyer']})");
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

// ============================================
// LOT INVENTORY (master list galing SLLI-SLRDI.xlsx, para sa RA# lookup)
// ============================================
function ensureLotInventoryTable($db) {
    $db->query("
        CREATE TABLE IF NOT EXISTS lot_inventory (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            ra_number       VARCHAR(50) NOT NULL,
            class           VARCHAR(50)  DEFAULT NULL,
            subdivision     VARCHAR(150) DEFAULT NULL,
            sub             VARCHAR(20)  DEFAULT NULL,
            ph              VARCHAR(10)  DEFAULT NULL,
            blk             VARCHAR(10)  DEFAULT NULL,
            lot             VARCHAR(10)  DEFAULT NULL,
            lot_area        VARCHAR(50)  DEFAULT NULL,
            house_area      VARCHAR(50)  DEFAULT NULL,
            cts_no          VARCHAR(100) DEFAULT NULL,
            control_no      VARCHAR(100) DEFAULT NULL,
            code            VARCHAR(100) DEFAULT NULL,
            buyers_name     VARCHAR(150) DEFAULT NULL,
            location        VARCHAR(150) DEFAULT NULL,
            lot_owner       VARCHAR(150) DEFAULT NULL,
            tct_no          VARCHAR(100) DEFAULT NULL,
            remarks         VARCHAR(255) DEFAULT NULL,
            transferred_tct VARCHAR(100) DEFAULT NULL,
            unit            VARCHAR(50)  DEFAULT NULL,
            pin_no          VARCHAR(100) DEFAULT NULL,
            td_no_old       VARCHAR(100) DEFAULT NULL,
            td_no_latest    VARCHAR(100) DEFAULT NULL,
            sale_type       VARCHAR(100) DEFAULT NULL,
            lot_type        VARCHAR(100) DEFAULT NULL,
            paid_tdate      VARCHAR(50)  DEFAULT NULL,
            sale_date       VARCHAR(50)  DEFAULT NULL,
            terms           VARCHAR(100) DEFAULT NULL,
            lot_price       VARCHAR(50)  DEFAULT NULL,
            house_price     VARCHAR(50)  DEFAULT NULL,
            contract_price  VARCHAR(50)  DEFAULT NULL,
            rel_date        VARCHAR(50)  DEFAULT NULL,
            final_area      VARCHAR(50)  DEFAULT NULL,
            marketing       VARCHAR(150) DEFAULT NULL,
            tel_no          VARCHAR(100) DEFAULT NULL,
            email           VARCHAR(150) DEFAULT NULL,
            address         VARCHAR(255) DEFAULT NULL,
            column1         VARCHAR(150) DEFAULT NULL,
            column2         VARCHAR(150) DEFAULT NULL,
            birth_date      VARCHAR(50)  DEFAULT NULL,
            tin             VARCHAR(100) DEFAULT NULL,
            remarks2        VARCHAR(255) DEFAULT NULL,
            UNIQUE KEY ra_unique (ra_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function getLiaisonRecordsByRA($body) {
    $db = getDB();
    ensureLiaisonRecordsTable($db);
    $ra = trim($body['raNo'] ?? '');
    $excludeId = intval($body['excludeId'] ?? 0);
    if (empty($ra)) sendJSON(["rows" => [], "total" => 0]);

    if ($excludeId > 0) {
        $stmt = $db->prepare("SELECT * FROM liaison_records WHERE ra_no = ? AND id != ? ORDER BY id DESC");
        $stmt->bind_param("si", $ra, $excludeId);
    } else {
        $stmt = $db->prepare("SELECT * FROM liaison_records WHERE ra_no = ? ORDER BY id DESC");
        $stmt->bind_param("s", $ra);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    $db->close();

    sendJSON(["rows" => $rows, "total" => count($rows)]);
}

function getLotInventoryByRA($body) {
    $db = getDB();
    ensureLotInventoryTable($db);
    $ra = trim($body['raNo'] ?? '');
    if (empty($ra)) sendJSON(["error" => "RA# is required."]);
    $stmt = $db->prepare("SELECT * FROM lot_inventory WHERE ra_number = ? LIMIT 1");
    $stmt->bind_param("s", $ra);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    $db->close();
    sendJSON($row ?: ["notFound" => true]);
}

function searchLotInventory($body) {
    $db = getDB();
    ensureLotInventoryTable($db);

    $q        = trim($body['query'] ?? '');
    $page     = max(1, intval($body['page'] ?? 1));
    $pageSize = intval($body['pageSize'] ?? 20);
    if ($pageSize < 1)  $pageSize = 20;
    if ($pageSize > 200) $pageSize = 200;
    $offset   = ($page - 1) * $pageSize;

    if ($q !== '') {
        $like = '%' . $q . '%';
        $where = "WHERE ra_number LIKE ? OR buyers_name LIKE ? OR subdivision LIKE ? OR tct_no LIKE ? OR lot_owner LIKE ? OR td_no_old LIKE ? OR td_no_latest LIKE ?";

        $countStmt = $db->prepare("SELECT COUNT(*) AS total FROM lot_inventory $where");
        $countStmt->bind_param("sssssss", $like, $like, $like, $like, $like, $like, $like);
        $countStmt->execute();
        $total = intval($countStmt->get_result()->fetch_assoc()['total']);
        $countStmt->close();

        $stmt = $db->prepare("SELECT * FROM lot_inventory $where ORDER BY id DESC LIMIT ? OFFSET ?");
        $stmt->bind_param("sssssssii", $like, $like, $like, $like, $like, $like, $like, $pageSize, $offset);
    } else {
        $total = intval($db->query("SELECT COUNT(*) AS total FROM lot_inventory")->fetch_assoc()['total']);
        $stmt = $db->prepare("SELECT * FROM lot_inventory ORDER BY id DESC LIMIT ? OFFSET ?");
        $stmt->bind_param("ii", $pageSize, $offset);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    $db->close();

    sendJSON([
        "rows"       => $rows,
        "total"      => $total,
        "page"       => $page,
        "pageSize"   => $pageSize,
        "totalPages" => max(1, ceil($total / $pageSize))
    ]);
}

function updateLotInventory($body) {
    $db = getDB();
    ensureLotInventoryTable($db);

    $id = intval($body['id'] ?? 0);
    if (!$id) sendJSON(["error" => "ID is required."]);

    // Only these fields are editable from the Lot Inventory table (matches the master-list columns).
    $editable = ['class', 'sub', 'ph', 'blk', 'lot', 'ra_number', 'lot_area', 'buyers_name', 'lot_owner', 'tct_no', 'td_no_old', 'td_no_latest'];

    $sets = [];
    $vals = [];
    $types = "";
    foreach ($editable as $field) {
        if (array_key_exists($field, $body)) {
            $sets[] = "$field = ?";
            $vals[] = trim($body[$field]);
            $types .= "s";
        }
    }
    if (empty($sets)) sendJSON(["error" => "No fields to update."]);

    $vals[] = $id;
    $types .= "i";

    $sql = "UPDATE lot_inventory SET " . implode(", ", $sets) . " WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$vals);

    if ($stmt->execute()) {
        // If a TD value was set on this lot, push it to any liaison records for the same RA#
        // that don't have a TD yet (doesn't overwrite ones that already have a TD).
        if (array_key_exists('td_no_old', $body) || array_key_exists('td_no_latest', $body)) {
            $rowStmt = $db->prepare("SELECT ra_number, td_no_old, td_no_latest FROM lot_inventory WHERE id = ?");
            $rowStmt->bind_param("i", $id);
            $rowStmt->execute();
            $row = $rowStmt->get_result()->fetch_assoc();
            $rowStmt->close();

            if ($row) {
                $tdValue = trim($row['td_no_latest'] ?? '') !== '' ? $row['td_no_latest'] : ($row['td_no_old'] ?? '');
                $raNumber = $row['ra_number'] ?? '';
                if ($tdValue !== '' && $raNumber !== '') {
                    ensureLiaisonRecordsTable($db);
                    $syncStmt = $db->prepare("UPDATE liaison_records SET td_no = ? WHERE ra_no = ?");
                    $syncStmt->bind_param("ss", $tdValue, $raNumber);
                    $syncStmt->execute();
                    $syncStmt->close();
                }
            }
        }
        sendJSON(["success" => true, "id" => $id]);
    } else {
        sendJSON(["error" => $stmt->error]);
    }
    $stmt->close();
    $db->close();
}

function deleteLotInventory($body) {
    $db = getDB();
    ensureLotInventoryTable($db);

    $id = intval($body['id'] ?? 0);
    if (!$id) sendJSON(["error" => "ID is required."]);

    $stmt = $db->prepare("DELETE FROM lot_inventory WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            sendJSON(["success" => true]);
        } else {
            sendJSON(["error" => "Record not found (ID: $id)"]);
        }
    } else {
        sendJSON(["error" => $stmt->error]);
    }
    $stmt->close();
    $db->close();
}