<?php
function ensureSlrdiRecordsTable($db) {
    $db->query("
        CREATE TABLE IF NOT EXISTS slrdi_records (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            ra_no           VARCHAR(100) DEFAULT NULL,
            subd            VARCHAR(50)  DEFAULT NULL,
            ph              VARCHAR(20)  DEFAULT NULL,
            blk             VARCHAR(20)  DEFAULT NULL,
            lot             VARCHAR(20)  DEFAULT NULL,
            description     VARCHAR(150) DEFAULT NULL,
            buyer           VARCHAR(150) DEFAULT NULL,
            tra_no          VARCHAR(100) DEFAULT NULL,
            turn_over_date  VARCHAR(20)  DEFAULT NULL,
            remarks         VARCHAR(255) DEFAULT NULL,
            created_by      VARCHAR(100) DEFAULT NULL,
            date_saved      DATETIME DEFAULT NOW()
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // Kung existing na table (walang ra_no pa), i-add nang hindi nagra-error
    $col = $db->query("SHOW COLUMNS FROM slrdi_records LIKE 'ra_no'");
    if ($col && $col->num_rows === 0) {
        $db->query("ALTER TABLE slrdi_records ADD COLUMN ra_no VARCHAR(100) DEFAULT NULL AFTER id");
    }
}

function ensureSlrdiActivityLogTable($db) {
    $db->query("
        CREATE TABLE IF NOT EXISTS slrdi_activity_log (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            slrdi_id      INT NOT NULL,
            action        VARCHAR(20) NOT NULL,
            field_name    VARCHAR(50)  DEFAULT NULL,
            old_value     VARCHAR(255) DEFAULT NULL,
            new_value     VARCHAR(255) DEFAULT NULL,
            note          VARCHAR(255) DEFAULT NULL,
            changed_by    VARCHAR(100) DEFAULT NULL,
            changed_at    DATETIME DEFAULT NOW(),
            INDEX idx_slrdi_id (slrdi_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function slrdiFieldLabels() {
    return [
        'ra_no'          => 'RA#',
        'subd'           => 'Subd.',
        'ph'             => 'Phase',
        'blk'            => 'Block',
        'lot'            => 'Lot',
        'description'    => 'Description',
        'buyer'          => "Buyer's Name",
        'tra_no'         => 'Tra#',
        'turn_over_date' => 'Turn Over Date',
        'remarks'        => 'Remarks',
    ];
}

function logSlrdiActivity($db, $slrdiId, $action, $changedBy, $fieldName = null, $oldValue = null, $newValue = null, $note = null) {
    ensureSlrdiActivityLogTable($db);
    $stmt = $db->prepare("INSERT INTO slrdi_activity_log (slrdi_id, action, field_name, old_value, new_value, note, changed_by, changed_at) VALUES (?,?,?,?,?,?,?,NOW())");
    $stmt->bind_param("issssss", $slrdiId, $action, $fieldName, $oldValue, $newValue, $note, $changedBy);
    $stmt->execute();
    $stmt->close();
}

function getSlrdiActivityLog($body) {
    $db = getDB();
    ensureSlrdiActivityLogTable($db);
    $slrdiId = isset($body['slrdiId']) ? (int)$body['slrdiId'] : 0;
    if ($slrdiId <= 0) sendJSON(["error" => "Invalid record ID"]);
    $stmt = $db->prepare("
        SELECT action, field_name, old_value, new_value, note, changed_by,
               DATE_FORMAT(changed_at, '%Y-%m-%dT%H:%i:%s') AS changed_at
        FROM slrdi_activity_log
        WHERE slrdi_id = ?
        ORDER BY changed_at DESC, id DESC
    ");
    $stmt->bind_param("i", $slrdiId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    $db->close();
    sendJSON(["log" => $rows, "labels" => slrdiFieldLabels()]);
}

function getSlrdiRecords() {
    $db = getDB();
    ensureSlrdiRecordsTable($db);
    $result = $db->query("SELECT * FROM slrdi_records ORDER BY id DESC");
    $rows = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    $db->close();
    sendJSON(["records" => $rows]);
}

function saveSlrdiRecord($body) {
    $db = getDB();
    ensureSlrdiRecordsTable($db);

    $id            = isset($body['id']) ? (int)$body['id'] : 0;
    $raNo          = trim($body['raNo'] ?? '');
    $subd          = trim($body['subd'] ?? '');
    $ph            = trim($body['ph'] ?? '');
    $blk           = trim($body['blk'] ?? '');
    $lot           = trim($body['lot'] ?? '');
    $description   = trim($body['description'] ?? '');
    $buyer         = trim($body['buyer'] ?? '');
    $traNo         = trim($body['traNo'] ?? '');
    $turnOverDate  = trim($body['turnOverDate'] ?? '');
    $remarks       = trim($body['remarks'] ?? '');
    $createdBy     = currentSessionUsername() ?? '';

    if (empty($raNo) && empty($subd) && empty($buyer)) sendJSON(["error" => "RA#, Subd., or Buyer's Name is required."]);

    $oldRow = null;
    if ($id > 0) {
        $oldStmt = $db->prepare("SELECT * FROM slrdi_records WHERE id = ?");
        $oldStmt->bind_param("i", $id);
        $oldStmt->execute();
        $oldRow = $oldStmt->get_result()->fetch_assoc();
        $oldStmt->close();
    }

    if ($id > 0) {
        $stmt = $db->prepare("UPDATE slrdi_records SET ra_no=?, subd=?, ph=?, blk=?, lot=?, description=?, buyer=?, tra_no=?, turn_over_date=?, remarks=? WHERE id=?");
        $stmt->bind_param("ssssssssssi", $raNo, $subd, $ph, $blk, $lot, $description, $buyer, $traNo, $turnOverDate, $remarks, $id);
    } else {
        $stmt = $db->prepare("INSERT INTO slrdi_records (ra_no, subd, ph, blk, lot, description, buyer, tra_no, turn_over_date, remarks, created_by, date_saved) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())");
        $stmt->bind_param("sssssssssss", $raNo, $subd, $ph, $blk, $lot, $description, $buyer, $traNo, $turnOverDate, $remarks, $createdBy);
    }

    if ($stmt->execute()) {
        $newId = $id > 0 ? $id : $db->insert_id;

        if ($id > 0 && $oldRow) {
            $newValues = [
                'ra_no' => $raNo, 'subd' => $subd, 'ph' => $ph, 'blk' => $blk, 'lot' => $lot,
                'description' => $description, 'buyer' => $buyer, 'tra_no' => $traNo,
                'turn_over_date' => $turnOverDate, 'remarks' => $remarks,
            ];
            foreach ($newValues as $field => $newVal) {
                $oldVal = isset($oldRow[$field]) ? (string)$oldRow[$field] : '';
                if (trim($oldVal) !== trim((string)$newVal)) {
                    logSlrdiActivity($db, $newId, 'updated', $createdBy, $field, $oldVal, $newVal);
                }
            }
        } else {
            logSlrdiActivity($db, $newId, 'created', $createdBy, null, null, null, "Record created (RA#: {$raNo}, Subd: {$subd}, Buyer: {$buyer})");
        }

        sendJSON(["success" => true, "id" => $newId]);
    } else {
        sendJSON(["error" => $stmt->error]);
    }
    $stmt->close();
    $db->close();
}

function deleteSlrdiRecord($body) {
    $db = getDB();
    ensureSlrdiRecordsTable($db);
    $id = isset($body['id']) ? (int)$body['id'] : 0;
    $changedBy = currentSessionUsername() ?? '';
    if ($id <= 0) sendJSON(["error" => "Invalid ID"]);

    $oldStmt = $db->prepare("SELECT ra_no, subd, buyer FROM slrdi_records WHERE id = ?");
    $oldStmt->bind_param("i", $id);
    $oldStmt->execute();
    $oldRow = $oldStmt->get_result()->fetch_assoc();
    $oldStmt->close();

    $stmt = $db->prepare("DELETE FROM slrdi_records WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            if ($oldRow) {
                logSlrdiActivity($db, $id, 'deleted', $changedBy, null, null, null, "Record deleted (RA#: {$oldRow['ra_no']}, Subd: {$oldRow['subd']}, Buyer: {$oldRow['buyer']})");
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
