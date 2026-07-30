<?php
function ensureReleasedTitlesTable($db) {
    $db->query("
        CREATE TABLE IF NOT EXISTS released_titles (
            id                  INT AUTO_INCREMENT PRIMARY KEY,
            date_released       VARCHAR(50)  DEFAULT NULL,
            year                VARCHAR(10)  DEFAULT NULL,
            buyer               VARCHAR(150) DEFAULT NULL,
            subd                VARCHAR(50)  DEFAULT NULL,
            ph                  VARCHAR(20)  DEFAULT NULL,
            blk                 VARCHAR(20)  DEFAULT NULL,
            lot                 VARCHAR(20)  DEFAULT NULL,
            ra_no               VARCHAR(100) DEFAULT NULL,
            transferred_title   VARCHAR(100) DEFAULT NULL,
            original_title      VARCHAR(100) DEFAULT NULL,
            owner               VARCHAR(100) DEFAULT NULL,
            created_by          VARCHAR(100) DEFAULT NULL,
            date_saved          DATETIME DEFAULT NOW(),
            INDEX idx_ra (ra_no),
            INDEX idx_buyer (buyer)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// ============================================
// RELEASED TITLE ACTIVITY LOG (audit trail per record)
// ============================================
function ensureReleasedActivityLogTable($db) {
    $db->query("
        CREATE TABLE IF NOT EXISTS released_activity_log (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            released_id   INT NOT NULL,
            action        VARCHAR(20) NOT NULL,
            field_name    VARCHAR(50)  DEFAULT NULL,
            old_value     VARCHAR(255) DEFAULT NULL,
            new_value     VARCHAR(255) DEFAULT NULL,
            note          VARCHAR(255) DEFAULT NULL,
            changed_by    VARCHAR(100) DEFAULT NULL,
            changed_at    DATETIME DEFAULT NOW(),
            INDEX idx_released_id (released_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function releasedFieldLabels() {
    return [
        'date_released'     => 'Date Released',
        'year'              => 'Year',
        'buyer'             => "Buyer's Name",
        'subd'              => 'Subdivision',
        'ph'                => 'Phase',
        'blk'               => 'Block',
        'lot'               => 'Lot',
        'ra_no'             => 'RA#',
        'transferred_title' => 'Transferred Title',
        'original_title'    => 'Original Title',
        'owner'             => 'Owner',
    ];
}

function logReleasedActivity($db, $releasedId, $action, $changedBy, $fieldName = null, $oldValue = null, $newValue = null, $note = null) {
    ensureReleasedActivityLogTable($db);
    $stmt = $db->prepare("INSERT INTO released_activity_log (released_id, action, field_name, old_value, new_value, note, changed_by, changed_at) VALUES (?,?,?,?,?,?,?,NOW())");
    $stmt->bind_param("issssss", $releasedId, $action, $fieldName, $oldValue, $newValue, $note, $changedBy);
    $stmt->execute();
    $stmt->close();
}

function getReleasedActivityLog($body) {
    $db = getDB();
    ensureReleasedActivityLogTable($db);
    $releasedId = isset($body['releasedId']) ? (int)$body['releasedId'] : 0;
    if ($releasedId <= 0) sendJSON(["error" => "Invalid record ID"]);
    $stmt = $db->prepare("
        SELECT action, field_name, old_value, new_value, note, changed_by,
               DATE_FORMAT(changed_at, '%Y-%m-%dT%H:%i:%s') AS changed_at
        FROM released_activity_log
        WHERE released_id = ?
        ORDER BY changed_at DESC, id DESC
    ");
    $stmt->bind_param("i", $releasedId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    $db->close();
    sendJSON(["log" => $rows, "labels" => releasedFieldLabels()]);
}

function searchReleasedTitles($body) {
    $db = getDB();
    ensureReleasedTitlesTable($db);

    $q        = trim($body['query'] ?? '');
    $page     = max(1, intval($body['page'] ?? 1));
    $pageSize = intval($body['pageSize'] ?? 100);
    if ($pageSize < 1)   $pageSize = 100;
    if ($pageSize > 5000) $pageSize = 5000;
    $offset   = ($page - 1) * $pageSize;

    if ($q !== '') {
        $like = '%' . $q . '%';
        $where = "WHERE ra_no LIKE ? OR buyer LIKE ? OR subd LIKE ? OR transferred_title LIKE ? OR original_title LIKE ? OR owner LIKE ?";

        $countStmt = $db->prepare("SELECT COUNT(*) AS total FROM released_titles $where");
        $countStmt->bind_param("ssssss", $like, $like, $like, $like, $like, $like);
        $countStmt->execute();
        $total = intval($countStmt->get_result()->fetch_assoc()['total']);
        $countStmt->close();

        $stmt = $db->prepare("SELECT * FROM released_titles $where ORDER BY id DESC LIMIT ? OFFSET ?");
        $stmt->bind_param("ssssssii", $like, $like, $like, $like, $like, $like, $pageSize, $offset);
    } else {
        $total = intval($db->query("SELECT COUNT(*) AS total FROM released_titles")->fetch_assoc()['total']);
        $stmt = $db->prepare("SELECT * FROM released_titles ORDER BY id DESC LIMIT ? OFFSET ?");
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

function saveReleasedRecord($body) {
    $db = getDB();
    ensureReleasedTitlesTable($db);

    $id                 = isset($body['id']) ? (int)$body['id'] : 0;
    $dateReleased       = trim($body['dateReleased'] ?? '');
    $year               = trim($body['year'] ?? '');
    $buyer              = trim($body['buyer'] ?? '');
    $subd               = trim($body['subd'] ?? '');
    $ph                 = trim($body['ph'] ?? '');
    $blk                = trim($body['blk'] ?? '');
    $lot                = trim($body['lot'] ?? '');
    $raNo               = trim($body['raNo'] ?? '');
    $transferredTitle   = trim($body['transferredTitle'] ?? '');
    $originalTitle      = trim($body['originalTitle'] ?? '');
    $owner              = trim($body['owner'] ?? '');
    $createdBy          = currentSessionUsername() ?? '';

    if (empty($raNo) && empty($buyer) && empty($subd)) sendJSON(["error" => "RA#, Buyer's Name, o Subd. ay required."]);

    $oldRow = null;
    if ($id > 0) {
        $oldStmt = $db->prepare("SELECT * FROM released_titles WHERE id = ?");
        $oldStmt->bind_param("i", $id);
        $oldStmt->execute();
        $oldRow = $oldStmt->get_result()->fetch_assoc();
        $oldStmt->close();
    }

    if ($id > 0) {
        $stmt = $db->prepare("UPDATE released_titles SET date_released=?, year=?, buyer=?, subd=?, ph=?, blk=?, lot=?, ra_no=?, transferred_title=?, original_title=?, owner=? WHERE id=?");
        $stmt->bind_param("sssssssssssi", $dateReleased, $year, $buyer, $subd, $ph, $blk, $lot, $raNo, $transferredTitle, $originalTitle, $owner, $id);
    } else {
        $stmt = $db->prepare("INSERT INTO released_titles (date_released, year, buyer, subd, ph, blk, lot, ra_no, transferred_title, original_title, owner, created_by, date_saved) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
        $stmt->bind_param("ssssssssssss", $dateReleased, $year, $buyer, $subd, $ph, $blk, $lot, $raNo, $transferredTitle, $originalTitle, $owner, $createdBy);
    }

    if ($stmt->execute()) {
        $newId = $id > 0 ? $id : $db->insert_id;

        if ($id > 0 && $oldRow) {
            $newValues = [
                'date_released' => $dateReleased, 'year' => $year, 'buyer' => $buyer,
                'subd' => $subd, 'ph' => $ph, 'blk' => $blk, 'lot' => $lot, 'ra_no' => $raNo,
                'transferred_title' => $transferredTitle, 'original_title' => $originalTitle, 'owner' => $owner,
            ];
            foreach ($newValues as $field => $newVal) {
                $oldVal = isset($oldRow[$field]) ? (string)$oldRow[$field] : '';
                if (trim($oldVal) !== trim((string)$newVal)) {
                    logReleasedActivity($db, $newId, 'updated', $createdBy, $field, $oldVal, $newVal);
                }
            }
        } else {
            logReleasedActivity($db, $newId, 'created', $createdBy, null, null, null, "Record created (RA# {$raNo}, Buyer: {$buyer})");
        }

        $stmt->close();
        $db->close();
        sendJSON(["success" => true, "id" => $newId]);
    } else {
        $err = $stmt->error;
        $stmt->close();
        $db->close();
        sendJSON(["error" => $err]);
    }
}

function deleteReleasedRecord($body) {
    $db = getDB();
    ensureReleasedTitlesTable($db);
    $id = isset($body['id']) ? (int)$body['id'] : 0;
    $changedBy = currentSessionUsername() ?? '';
    if ($id <= 0) sendJSON(["error" => "Invalid ID"]);

    $oldStmt = $db->prepare("SELECT ra_no, buyer FROM released_titles WHERE id = ?");
    $oldStmt->bind_param("i", $id);
    $oldStmt->execute();
    $oldRow = $oldStmt->get_result()->fetch_assoc();
    $oldStmt->close();

    $stmt = $db->prepare("DELETE FROM released_titles WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            if ($oldRow) {
                logReleasedActivity($db, $id, 'deleted', $changedBy, null, null, null, "Record deleted (RA# {$oldRow['ra_no']}, Buyer: {$oldRow['buyer']})");
            }
            sendJSON(["success" => true, "deleted_id" => $id]);
        } else {
            sendJSON(["error" => "Record not found (ID: $id)"]);
        }
    } else {
        sendJSON(["error" => $stmt->error]);
    }
    $stmt->close();
    $db->close();
}
