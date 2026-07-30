<?php
function getUsers() {
    $db = getDB();
    ensureUsersTable($db);
    $result = $db->query("SELECT username, role FROM rpt_users ORDER BY username ASC");
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    $db->close();
    sendJSON(["users" => $users]);
}

// ============================================
// ONLINE PRESENCE
// Tinatawag ito ng browser kada ilang segundo habang
// bukas ang app. "Online" = na-update ang last_seen
// nila sa loob ng nakaraang 2 minuto.
// ============================================
function heartbeat($body) {
    $db = getDB();
    ensureUsersTable($db);
    $username = strtoupper(trim($body['username'] ?? ''));
    if (empty($username)) {
        sendJSON(["error" => "Username is required."]);
    }
    $stmt = $db->prepare("UPDATE rpt_users SET last_seen = NOW() WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->close();
    $db->close();
    sendJSON(["success" => true]);
}

function getOnlineUsers() {
    $db = getDB();
    ensureUsersTable($db);
    $result = $db->query("SELECT username, role, last_seen FROM rpt_users WHERE last_seen IS NOT NULL AND last_seen >= (NOW() - INTERVAL 2 MINUTE) ORDER BY username ASC");
    $online = [];
    while ($row = $result->fetch_assoc()) {
        $online[] = $row;
    }
    $db->close();
    sendJSON(["success" => true, "online" => $online]);
}

// ============================================
// ADD USER
// ============================================
function addUser($body) {
    $db = getDB();
    ensureUsersTable($db);

    $username = strtoupper(trim($body['username'] ?? ''));
    $password = trim($body['password'] ?? '');
    $role     = in_array($body['role'] ?? '', ['admin','staff','viewer']) ? $body['role'] : 'staff';

    if (empty($username) || empty($password)) {
        sendJSON(["error" => "Username and password are required."]);
    }

    $check = $db->prepare("SELECT id FROM rpt_users WHERE username = ?");
    $check->bind_param("s", $username);
    $check->execute();
    $check->store_result();
    if ($check->num_rows > 0) {
        sendJSON(["error" => "Username '$username' already exists."]);
    }
    $check->close();

    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO rpt_users (username, password, role) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $username, $hashed, $role);

    if ($stmt->execute()) {
        sendJSON(["success" => true, "username" => $username]);
    } else {
        sendJSON(["error" => $stmt->error]);
    }
    $stmt->close();
    $db->close();
}

// ============================================
// DELETE USER
// ============================================
function deleteUser($body) {
    $db = getDB();
    ensureUsersTable($db);

    $username = strtoupper(trim($body['username'] ?? ''));
    if (empty($username)) sendJSON(["error" => "Username required."]);

    $stmt = $db->prepare("DELETE FROM rpt_users WHERE username = ?");
    $stmt->bind_param("s", $username);

    if ($stmt->execute()) {
        sendJSON(["success" => true]);
    } else {
        sendJSON(["error" => $stmt->error]);
    }
    $stmt->close();
    $db->close();
}

// ============================================
// CHANGE PASSWORD
// ============================================
function changePassword($body) {
    $db = getDB();
    ensureUsersTable($db);

    $username        = currentSessionUsername() ?? '';
    $currentPassword = trim($body['currentPassword'] ?? '');
    $newPassword     = trim($body['newPassword'] ?? '');

    if (empty($username) || empty($currentPassword) || empty($newPassword)) {
        sendJSON(["error" => "All fields are required."]);
    }
    if (strlen($newPassword) < 4) {
        sendJSON(["error" => "New password must be at least 4 characters."]);
    }

    $stmt = $db->prepare("SELECT id, password FROM rpt_users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        sendJSON(["error" => "Account not found."]);
    }

    $validPassword = password_verify($currentPassword, $user['password']);

    if (!$validPassword) {
        sendJSON(["error" => "Current password is incorrect."]);
    }

    $newHashed = password_hash($newPassword, PASSWORD_DEFAULT);
    $upd = $db->prepare("UPDATE rpt_users SET password = ? WHERE username = ?");
    $upd->bind_param("ss", $newHashed, $username);

    if ($upd->execute()) {
        sendJSON(["success" => true]);
    } else {
        sendJSON(["error" => $upd->error]);
    }
    $upd->close();
    $db->close();
}

// ============================================
// SAVE PROFILE (avatar + accent color)
// ============================================
function saveProfile($body) {
    $db = getDB();
    ensureUsersTable($db);

    $username    = currentSessionUsername() ?? '';
    $avatar      = $body['avatar'] ?? null;
    $accentColor = trim($body['accentColor'] ?? '');

    if (empty($username)) sendJSON(["error" => "Username required."]);

    if ($accentColor && !preg_match('/^#[0-9a-fA-F]{3,6}$/', $accentColor)) {
        $accentColor = '';
    }

    $stmt = $db->prepare("UPDATE rpt_users SET avatar = ?, accent_color = ? WHERE username = ?");
    $stmt->bind_param("sss", $avatar, $accentColor, $username);

    if ($stmt->execute()) {
        sendJSON(["success" => true]);
    } else {
        sendJSON(["error" => $stmt->error]);
    }
    $stmt->close();
    $db->close();
}

// ============================================
// GET PROFILE (avatar + accent color)
// ============================================
function getProfile($body) {
    $db = getDB();
    ensureUsersTable($db);

    $username = strtoupper(trim($body['username'] ?? ''));
    if (empty($username)) sendJSON(["error" => "Username required."]);

    $stmt = $db->prepare("SELECT avatar, accent_color FROM rpt_users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    $db->close();

    if ($row) {
        sendJSON(["success" => true, "avatar" => $row['avatar'], "accentColor" => $row['accent_color']]);
    } else {
        sendJSON(["success" => true, "avatar" => null, "accentColor" => null]);
    }
}
