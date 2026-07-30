<?php
// ============================================
// api.php — RPT System Backend (XAMPP / localhost)
// ============================================

@ini_set('display_errors', 1);
@ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    http_response_code(200);
    exit();
}

// ============================================
// DATABASE CONFIG (XAMPP defaults)
// ============================================
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');              // walang password by default sa XAMPP
define('DB_NAME', 'rpt_system');    // gawin mo muna ito sa phpMyAdmin (o gamitin ang setup.sql)

function getDB() {
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(["error" => "DB Connection failed: " . $conn->connect_error]);
        exit();
    }
    $conn->set_charset("utf8mb4");
    $conn->query("SET time_zone = '+08:00'");
    return $conn;
}

function sendJSON($data) {
    ob_end_clean();
    echo json_encode($data);
    exit();
}

// ============================================
// SERVER-SIDE AUTH (real source of truth — never trust
// role/username sent by the client in the request body)
// ============================================
function setSessionUser($username, $role) {
    session_regenerate_id(true);
    $_SESSION['rpt_username'] = $username;
    $_SESSION['rpt_role']     = $role;
}

function clearSessionUser() {
    $_SESSION = [];
    if (session_id() !== '') {
        session_destroy();
    }
}

function currentSessionRole() {
    return $_SESSION['rpt_role'] ?? null;
}

function currentSessionUsername() {
    return $_SESSION['rpt_username'] ?? null;
}

// Actions viewers are always allowed to call (read-only or own-account actions)
const VIEWER_ALLOWED_ACTIONS = [
    'login', 'logout', 'getUsers', 'changePassword', 'saveProfile', 'getProfile', 'chat', 'heartbeat',
];

// Regex: action names that are read-only by naming convention
const VIEWER_ALLOWED_PATTERN = '/^(get|search|list|lookup).*|.*ActivityLog$/i';

// Actions callable WITHOUT being logged in at all.
const PUBLIC_ACTIONS = ['login', 'logout'];

// Actions that require an admin session, no matter what the client claims.
const ADMIN_ONLY_ACTIONS = ['addUser', 'deleteUser', 'saveBirthday', 'deleteBirthday'];

// Blocks the request server-side if:
// 1) there's no logged-in session at all (except PUBLIC_ACTIONS), or
// 2) the action is admin-only and the session role isn't admin, or
// 3) the logged-in session belongs to a viewer and the action isn't read-only.
function enforceViewerRestriction($action) {
    if (!in_array($action, PUBLIC_ACTIONS, true) && currentSessionUsername() === null) {
        sendJSON(["error" => "Session expired or not logged in. Please log in again."]);
    }

    if (in_array($action, ADMIN_ONLY_ACTIONS, true) && currentSessionRole() !== 'admin') {
        sendJSON(["error" => "Access denied. Admin account lang ang pwedeng mag-manage ng users."]);
    }

    $role = currentSessionRole();
    if ($role !== 'viewer') {
        return; // not a viewer session — nothing to restrict
    }
    if (in_array($action, VIEWER_ALLOWED_ACTIONS, true)) {
        return;
    }
    if (preg_match(VIEWER_ALLOWED_PATTERN, $action)) {
        return;
    }
    sendJSON(["error" => "View-only account: hindi ka pwedeng mag-add/edit/delete."]);
}
