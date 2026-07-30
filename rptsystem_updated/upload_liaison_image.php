<?php
// ============================================
// upload_liaison_image.php — Liaison image upload (XAMPP/localhost)
// Hiwalay ito sa api.php kasi multipart/form-data ang gamit dito,
// hindi JSON body (para sa image files).
// ============================================

@ini_set('display_errors', 0);
error_reporting(0);

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['rpt_username'])) {
    http_response_code(403);
    echo json_encode(["error" => "Session expired or not logged in. Please log in again."]);
    exit();
}

function respond($data) {
    echo json_encode($data);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(["error" => "POST only."]);
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    respond(["error" => "No image file received."]);
}

$file = $_FILES['image'];

// --- Validate file type (images o PDF) ---
$allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExt)) {
    respond(["error" => "Invalid file type. Allowed: jpg, jpeg, png, webp, pdf only."]);
}

// --- Validate size (max 5MB bago pa i-resize sa frontend) ---
$maxBytes = 5 * 1024 * 1024;
if ($file['size'] > $maxBytes) {
    respond(["error" => "File is too large (max 5MB)."]);
}

// --- Validate na tunay na image (skip validation for PDFs, checked via mime below) ---
if ($ext !== 'pdf') {
    $imgInfo = @getimagesize($file['tmp_name']);
    if ($imgInfo === false) {
        respond(["error" => "Invalid or corrupted image file."]);
    }
} else {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if ($mime !== 'application/pdf') {
        respond(["error" => "Invalid or corrupted PDF file."]);
    }
}

// --- Prepare upload folder: uploads/liaison/YYYY/MM/ ---
$year  = date('Y');
$month = date('m');
$relDir = "uploads/liaison/$year/$month";
$absDir = __DIR__ . "/$relDir";

if (!is_dir($absDir)) {
    if (!mkdir($absDir, 0755, true)) {
        respond(["error" => "Could not create the upload folder."]);
    }
}

// --- Protect the uploads folder from directory listing / script execution ---
$htaccessPath = __DIR__ . "/uploads/.htaccess";
if (!file_exists($htaccessPath)) {
    @file_put_contents($htaccessPath, "Options -Indexes\nphp_flag engine off\n");
}

// --- Generate safe unique filename ---
$liaisonId = isset($_POST['liaisonId']) ? preg_replace('/[^0-9]/', '', $_POST['liaisonId']) : '0';
$uniqueName = 'liaison_' . $liaisonId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$relPath = "$relDir/$uniqueName";
$absPath = "$absDir/$uniqueName";

if (!move_uploaded_file($file['tmp_name'], $absPath)) {
    respond(["error" => "Could not save the file to the server."]);
}

respond(["success" => true, "path" => $relPath]);
