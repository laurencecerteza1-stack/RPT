<?php

// ============================================
// ENSURE rpt_records TABLE EXISTS
// ============================================
function ensureRecordsTable($db) {
    $db->query("
        CREATE TABLE IF NOT EXISTS rpt_records (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            lot          VARCHAR(100) NOT NULL UNIQUE,
            prepared_by  VARCHAR(100) DEFAULT NULL,
            grand_total  DECIMAL(15,2) DEFAULT 0,
            full_data    LONGTEXT DEFAULT NULL,
            date_saved   DATETIME DEFAULT NOW()
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // Normalized+indexed generated column para mabilis at case/space-insensitive
    // ang pag-match sa liaison_records.ra_no_norm (walang function sa join = magagamit ang index).
    @$db->query("ALTER TABLE rpt_records ADD COLUMN lot_norm VARCHAR(100) COLLATE utf8mb4_general_ci GENERATED ALWAYS AS (UPPER(TRIM(lot))) STORED");
    @$db->query("ALTER TABLE rpt_records ADD INDEX idx_lot_norm (lot_norm)");
}

// ============================================
// ENSURE chat_messages TABLE EXISTS
// ============================================
function ensureChatTable($db) {
    $db->query("
        CREATE TABLE IF NOT EXISTS chat_messages (
            id        INT AUTO_INCREMENT PRIMARY KEY,
            sender    VARCHAR(50) NOT NULL,
            message   TEXT NOT NULL,
            timestamp DATETIME DEFAULT NOW()
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// ============================================
// ENSURE users TABLE EXISTS WITH ALL COLUMNS
// ============================================
function ensureUsersTable($db) {
    $db->query("
        CREATE TABLE IF NOT EXISTS rpt_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'staff',
            avatar MEDIUMTEXT DEFAULT NULL,
            accent_color VARCHAR(20) DEFAULT NULL,
            created_at DATETIME DEFAULT NOW()
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // NOTE: "ADD COLUMN IF NOT EXISTS" requires MySQL 8.0.12+ / MariaDB 10.5+.
    // Mas pwedeng-pwede (lumang XAMPP) ang ginawa kong check-then-add sa ibaba
    // para hindi mag-error kung mas lumang MySQL/MariaDB ang bundled sa XAMPP mo.
    $existingCols = [];
    $res = $db->query("SHOW COLUMNS FROM rpt_users");
    while ($row = $res->fetch_assoc()) {
        $existingCols[] = $row['Field'];
    }
    if (!in_array('avatar', $existingCols)) {
        $db->query("ALTER TABLE rpt_users ADD COLUMN avatar MEDIUMTEXT DEFAULT NULL");
    }
    if (!in_array('accent_color', $existingCols)) {
        $db->query("ALTER TABLE rpt_users ADD COLUMN accent_color VARCHAR(20) DEFAULT NULL");
    }
    if (!in_array('last_seen', $existingCols)) {
        $db->query("ALTER TABLE rpt_users ADD COLUMN last_seen DATETIME DEFAULT NULL");
    }
}

// ============================================
// ENSURE tax_rates TABLE EXISTS
// ============================================
function ensureTaxRatesTable($db) {
    $db->query("
        CREATE TABLE IF NOT EXISTS tax_rates (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            lot_code   VARCHAR(20) NOT NULL UNIQUE,
            tax_rate   VARCHAR(20) NOT NULL DEFAULT '2%',
            municipal  VARCHAR(100) DEFAULT NULL,
            updated_at DATETIME DEFAULT NOW() ON UPDATE NOW()
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // Migration: add municipal column if table already existed without it
    $check = $db->query("SHOW COLUMNS FROM tax_rates LIKE 'municipal'");
    if ($check && $check->num_rows === 0) {
        $db->query("ALTER TABLE tax_rates ADD COLUMN municipal VARCHAR(100) DEFAULT NULL AFTER tax_rate");
    }
}

// ============================================
// LOGIN
// ============================================
function loginUser($body) {
    $db = getDB();
    ensureUsersTable($db);

    $username = strtoupper(trim($body['username'] ?? ''));
    $password = trim($body['password'] ?? '');

    if (empty($username) || empty($password)) {
        sendJSON(["error" => "Please fill in username and password."]);
    }

    $stmt = $db->prepare("SELECT id, password, role, avatar, accent_color FROM rpt_users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        sendJSON(["error" => "Invalid username or password."]);
    }

    $valid = password_verify($password, $user['password']);

    if (!$valid) {
        sendJSON(["error" => "Invalid username or password."]);
    }

    $db->close();

    setSessionUser($username, $user['role']);

    sendJSON([
        "success"     => true,
        "username"    => $username,
        "role"        => $user['role'],
        "avatar"      => $user['avatar'],
        "accentColor" => $user['accent_color'],
    ]);
}

// ============================================
// GET ALL RPT RECORDS
// ============================================
function getRecords() {
    $db = getDB();
    ensureRecordsTable($db);
    if (function_exists('ensureLiaisonRecordsTable')) {
        ensureLiaisonRecordsTable($db);
    }

    // Simpleng query lang (walang Liaison columns) — palaging safe, walang JOIN cost.
    $simpleSql = "
        SELECT
            DATE_FORMAT(date_saved, '%m/%d/%Y %H:%i') AS date_saved,
            lot, prepared_by, grand_total, full_data, id
        FROM rpt_records
        ORDER BY date_saved DESC
    ";

    // Palaging subukan ang JOIN para lumabas ang Liaison/OR#. Gumagamit ng
    // normalized+indexed columns (lot_norm / ra_no_norm) para mabilis — walang
    // function-on-column sa join condition mismo kaya magagamit ang index.
    $result = $db->query("
        SELECT
            DATE_FORMAT(r.date_saved, '%m/%d/%Y %H:%i') AS date_saved,
            r.lot,
            r.prepared_by,
            r.grand_total,
            r.full_data,
            r.id,
            lr.liaison_name,
            lr.or_no
        FROM rpt_records r
        LEFT JOIN (
            SELECT l1.ra_no_norm, l1.liaison_name, l1.or_no
            FROM liaison_records l1
            INNER JOIN (
                SELECT ra_no_norm, MAX(id) AS max_id FROM liaison_records GROUP BY ra_no_norm
            ) l2 ON l1.ra_no_norm = l2.ra_no_norm AND l1.id = l2.max_id
        ) lr ON lr.ra_no_norm = r.lot_norm
        ORDER BY r.date_saved DESC
    ");

    // Fallback lang kung talagang nag-error ang JOIN (hal. collation mismatch) —
    // huwag i-crash ang buong page, gamitin na lang ang simpleng query. Naka-log ang
    // aktwal na DB error para malaman kung bakit nag-fallback.
    if ($result === false) {
        error_log("getRecords() LIAISON JOIN failed: " . $db->error);
        $result = $db->query($simpleSql);
        $rows = [];
        while ($row = $result->fetch_row()) {
            $rows[] = [$row[0], $row[1], $row[2], (float)$row[3], $row[4], (int)$row[5], null, null];
        }
        $db->close();
        sendJSON($rows);
        return;
    }

    $rows = [];
    while ($row = $result->fetch_row()) {
        $rows[] = [
            $row[0],
            $row[1],
            $row[2],
            (float)$row[3],
            $row[4],
            (int)$row[5],
            $row[6],
            $row[7]
        ];
    }
    $db->close();
    sendJSON($rows);
}

// ============================================
// COMPUTATION RECORD ACTIVITY LOG (audit trail)
// ============================================
function ensureRecordActivityLogTable($db) {
    $db->query("
        CREATE TABLE IF NOT EXISTS rpt_records_activity_log (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            record_id     INT NOT NULL,
            action        VARCHAR(20) NOT NULL,
            field_name    VARCHAR(50)  DEFAULT NULL,
            old_value     VARCHAR(255) DEFAULT NULL,
            new_value     VARCHAR(255) DEFAULT NULL,
            note          VARCHAR(255) DEFAULT NULL,
            changed_by    VARCHAR(100) DEFAULT NULL,
            changed_at    DATETIME DEFAULT NOW(),
            INDEX idx_record_id (record_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function recordFieldLabels() {
    return [
        'lot'           => 'Lot Details',
        'prepared_by'   => 'Prepared By',
        'grand_total'   => 'Grand Total',
        'full_data'     => 'Computation Details',
    ];
}

function logRecordActivity($db, $recordId, $action, $changedBy, $fieldName = null, $oldValue = null, $newValue = null, $note = null) {
    ensureRecordActivityLogTable($db);
    $stmt = $db->prepare("INSERT INTO rpt_records_activity_log (record_id, action, field_name, old_value, new_value, note, changed_by, changed_at) VALUES (?,?,?,?,?,?,?,NOW())");
    $stmt->bind_param("issssss", $recordId, $action, $fieldName, $oldValue, $newValue, $note, $changedBy);
    $stmt->execute();
    $stmt->close();
}

function getRecordActivityLog($body) {
    $db = getDB();
    ensureRecordActivityLogTable($db);
    $recordId = isset($body['recordId']) ? (int)$body['recordId'] : 0;
    if ($recordId <= 0) sendJSON(["error" => "Invalid record ID"]);
    $stmt = $db->prepare("
        SELECT action, field_name, old_value, new_value, note, changed_by,
               DATE_FORMAT(changed_at, '%Y-%m-%dT%H:%i:%s') AS changed_at
        FROM rpt_records_activity_log
        WHERE record_id = ?
        ORDER BY changed_at DESC, id DESC
    ");
    $stmt->bind_param("i", $recordId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    $db->close();
    sendJSON(["log" => $rows, "labels" => recordFieldLabels()]);
}

// ============================================
// LIVE CHECK: does a computation record already exist for this Lot?
// (used by form.html while typing Lot Details, before saving)
// ============================================
function checkLotRecord($body) {
    $db = getDB();
    ensureRecordsTable($db);
    $lot = trim($body['lot'] ?? '');
    $excludeId = isset($body['excludeId']) ? (int)$body['excludeId'] : 0;

    if ($lot === '') sendJSON(["found" => false]);

    if ($excludeId > 0) {
        $stmt = $db->prepare("SELECT id, lot, prepared_by, full_data, DATE_FORMAT(date_saved,'%m/%d/%Y') AS date_saved FROM rpt_records WHERE lot = ? AND id != ? LIMIT 1");
        $stmt->bind_param("si", $lot, $excludeId);
    } else {
        $stmt = $db->prepare("SELECT id, lot, prepared_by, full_data, DATE_FORMAT(date_saved,'%m/%d/%Y') AS date_saved FROM rpt_records WHERE lot = ? LIMIT 1");
        $stmt->bind_param("s", $lot);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $db->close();

    if ($row) {
        sendJSON([
            "found"       => true,
            "id"          => (int)$row['id'],
            "lot"         => $row['lot'],
            "prepared_by" => $row['prepared_by'],
            "date_saved"  => $row['date_saved'],
            "full_data"   => $row['full_data'],
        ]);
    } else {
        sendJSON(["found" => false]);
    }
}

// ============================================
// SAVE OR UPDATE RPT RECORD
// ============================================
function saveRecord($body) {
    $db = getDB();
    ensureRecordsTable($db);
    $existingRow = isset($body['existingRow']) ? (int)$body['existingRow'] : 0;
    $lot         = trim($body['lot'] ?? '');
    $prep        = trim($body['prep'] ?? '');
    $totalStr    = str_replace(',', '', $body['total'] ?? '0');
    $total       = (float)$totalStr;
    $fullData    = json_encode($body['fullData'] ?? []);
    $changedBy   = currentSessionUsername() ?? $prep;

    if (empty($lot)) sendJSON(["error" => "Lot is required"]);

    // Explicit duplicate check (works even if the DB has no UNIQUE constraint on `lot`)
    if ($existingRow > 0) {
        $checkStmt = $db->prepare("SELECT id FROM rpt_records WHERE lot = ? AND id != ? LIMIT 1");
        $checkStmt->bind_param("si", $lot, $existingRow);
    } else {
        $checkStmt = $db->prepare("SELECT id FROM rpt_records WHERE lot = ? LIMIT 1");
        $checkStmt->bind_param("s", $lot);
    }
    $checkStmt->execute();
    $checkStmt->store_result();
    if ($checkStmt->num_rows > 0) {
        $checkStmt->close();
        $db->close();
        sendJSON(["error" => "DUPLICATE", "message" => "Lot '$lot' already has a record."]);
    }
    $checkStmt->close();

    $oldRow = null;
    if ($existingRow > 0) {
        $oldStmt = $db->prepare("SELECT lot, prepared_by, grand_total, full_data FROM rpt_records WHERE id = ?");
        $oldStmt->bind_param("i", $existingRow);
        $oldStmt->execute();
        $oldRow = $oldStmt->get_result()->fetch_assoc();
        $oldStmt->close();
    }

    if ($existingRow > 0) {
        $stmt = $db->prepare("UPDATE rpt_records SET lot=?, prepared_by=?, grand_total=?, full_data=?, date_saved=NOW() WHERE id=?");
        $stmt->bind_param("ssdsi", $lot, $prep, $total, $fullData, $existingRow);
    } else {
        $stmt = $db->prepare("INSERT INTO rpt_records (lot, prepared_by, grand_total, full_data, date_saved) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("ssds", $lot, $prep, $total, $fullData);
    }

    if ($stmt->execute()) {
        $newId = $existingRow > 0 ? $existingRow : $db->insert_id;

        if ($existingRow > 0 && $oldRow) {
            $newValues = [
                'lot' => $lot,
                'prepared_by' => $prep,
                'grand_total' => number_format($total, 2, '.', ''),
            ];
            foreach ($newValues as $field => $newVal) {
                $oldVal = isset($oldRow[$field]) ? (string)$oldRow[$field] : '';
                if ($field === 'grand_total') $oldVal = number_format((float)$oldVal, 2, '.', '');
                if (trim($oldVal) !== trim((string)$newVal)) {
                    logRecordActivity($db, $newId, 'updated', $changedBy, $field, $oldVal, $newVal);
                }
            }
            if (trim((string)($oldRow['full_data'] ?? '')) !== trim((string)$fullData)) {
                logRecordActivity($db, $newId, 'updated', $changedBy, 'full_data', null, null, 'Computation details updated');
            }
        } else {
            logRecordActivity($db, $newId, 'created', $changedBy, null, null, null, "Record created (Lot: {$lot})");
        }

        sendJSON(["success" => true, "id" => $newId]);
    } else {
        if ($db->errno === 1062) {
            sendJSON(["error" => "DUPLICATE", "message" => "Lot '$lot' already has a record."]);
        } else {
            sendJSON(["error" => $stmt->error]);
        }
    }
    $stmt->close();
    $db->close();
}

// ============================================
// DELETE RPT RECORD
// ============================================
function deleteRecord($body) {
    $db = getDB();
    ensureRecordsTable($db);
    $id = isset($body['id']) ? (int)$body['id'] : 0;
    $changedBy = currentSessionUsername() ?? '';
    if ($id <= 0) sendJSON(["error" => "Invalid ID"]);

    $oldStmt = $db->prepare("SELECT lot, prepared_by FROM rpt_records WHERE id = ?");
    $oldStmt->bind_param("i", $id);
    $oldStmt->execute();
    $oldRow = $oldStmt->get_result()->fetch_assoc();
    $oldStmt->close();

    $stmt = $db->prepare("DELETE FROM rpt_records WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            if ($oldRow) {
                logRecordActivity($db, $id, 'deleted', $changedBy ?: $oldRow['prepared_by'], null, null, null, "Record deleted (Lot: {$oldRow['lot']})");
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