<?php
// ============================================
// BIRTHDAYS MODULE — celebrants linked to rpt_users
// ============================================

function ensureBirthdaysTable($db) {
    $db->query("
        CREATE TABLE IF NOT EXISTS rpt_birthdays (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            username   VARCHAR(50) NOT NULL UNIQUE,
            full_name  VARCHAR(150) NOT NULL,
            birth_date DATE NOT NULL,
            created_at DATETIME DEFAULT NOW(),
            INDEX idx_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// ============================================
// GET all birthdays (joined with rpt_users so we only
// ever show people who currently have an account)
// ============================================
function getBirthdays() {
    $db = getDB();
    ensureBirthdaysTable($db);
    ensureUsersTable($db);

    $sql = "SELECT b.id, b.username, b.full_name, DATE_FORMAT(b.birth_date,'%Y-%m-%d') AS birth_date
            FROM rpt_birthdays b
            INNER JOIN rpt_users u ON u.username = b.username
            ORDER BY MONTH(b.birth_date), DAY(b.birth_date)";
    $res = $db->query($sql);
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $db->close();
    sendJSON(["success" => true, "birthdays" => $rows]);
}

// ============================================
// SAVE (add or update) a birthday — admin only, enforced by router/session check
// ============================================
function saveBirthday($body) {
    $db = getDB();
    ensureBirthdaysTable($db);

    $username  = trim($body['username'] ?? '');
    $fullName  = trim($body['full_name'] ?? '');
    $birthDate = trim($body['birth_date'] ?? ''); // expected YYYY-MM-DD

    if ($username === '' || $fullName === '' || $birthDate === '') {
        sendJSON(["error" => "Username, full name, at birth date ay required."]);
    }

    $stmt = $db->prepare("
        INSERT INTO rpt_birthdays (username, full_name, birth_date)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), birth_date = VALUES(birth_date)
    ");
    $stmt->bind_param("sss", $username, $fullName, $birthDate);
    $stmt->execute();
    $stmt->close();
    $db->close();

    sendJSON(["success" => true]);
}

// ============================================
// DELETE a birthday entry
// ============================================
function deleteBirthday($body) {
    $db = getDB();
    ensureBirthdaysTable($db);

    $username = trim($body['username'] ?? '');
    if ($username === '') sendJSON(["error" => "Missing username."]);

    $stmt = $db->prepare("DELETE FROM rpt_birthdays WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->close();
    $db->close();

    sendJSON(["success" => true]);
}
