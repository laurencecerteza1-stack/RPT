<?php
// ============================================
// import.php — CSV to MySQL Importer
// I-lagay sa: htdocs/rpt_system/import.php
// I-access sa: http://localhost/rpt_system/import.php
// ============================================

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (($_SESSION['rpt_role'] ?? null) !== 'admin') {
    http_response_code(403);
    die("Access denied. Please log in as an admin in the app first, then reload this page.");
}

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'rpt_system');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset("utf8mb4");

$success = 0;
$skipped = 0;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv'])) {
    $file = $_FILES['csv']['tmp_name'];
    $handle = fopen($file, 'r');

    // Skip header row
    fgetcsv($handle);

    while (($row = fgetcsv($handle)) !== false) {
        // Kunin ang columns
        $date_raw    = trim($row[0] ?? '');
        $lot         = trim($row[1] ?? '');
        $prepared_by = trim($row[2] ?? '');
        $grand_total = str_replace(',', '', trim($row[3] ?? '0'));
        $full_data   = trim($row[4] ?? '{}');

        // Skip kung walang lot
        if (empty($lot)) { $skipped++; continue; }

        // Fix date: "3/12/2026 8:30:48" → "2026-03-12 08:30:48"
        $date_fixed = fixDate($date_raw);

        // Fix grand_total
        $total = (float)$grand_total;

        // Insert
        $stmt = $conn->prepare("INSERT IGNORE INTO rpt_records (date_saved, lot, prepared_by, grand_total, full_data) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssdds", $date_fixed, $lot, $prepared_by, $total, $full_data);

        if ($stmt->execute()) {
            if ($conn->affected_rows > 0) {
                $success++;
            } else {
                $skipped++; // Duplicate lot
            }
        } else {
            $errors[] = "Row $lot: " . $stmt->error;
        }
        $stmt->close();
    }

    fclose($handle);
}

function fixDate($raw) {
    if (empty($raw)) return date('Y-m-d H:i:s');
    // Try M/D/YYYY H:MM:SS format
    $dt = DateTime::createFromFormat('n/j/Y G:i:s', $raw);
    if ($dt) return $dt->format('Y-m-d H:i:s');
    // Try other formats
    $dt = DateTime::createFromFormat('n/j/Y', $raw);
    if ($dt) return $dt->format('Y-m-d 00:00:00');
    return date('Y-m-d H:i:s');
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>RPT CSV Importer</title>
    <style>
        body { font-family: Arial; max-width: 600px; margin: 50px auto; padding: 20px; }
        .success { color: green; font-weight: bold; }
        .error   { color: red; }
        .box     { background: #f5f5f5; padding: 20px; border-radius: 8px; }
        button   { background: #1a73e8; color: white; padding: 10px 24px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        input[type=file] { margin: 10px 0; }
    </style>
</head>
<body>
    <div class="box">
        <h2>📂 RPT CSV Importer</h2>

        <?php if ($success > 0 || $skipped > 0): ?>
            <p class="success">✅ Imported: <strong><?= $success ?></strong> records</p>
            <p>⏭️ Skipped (duplicate/blank): <strong><?= $skipped ?></strong></p>
            <?php if ($errors): ?>
                <p class="error">❌ Errors:</p>
                <ul><?php foreach($errors as $e) echo "<li class='error'>$e</li>"; ?></ul>
            <?php endif; ?>
            <hr>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <p>Upload the CSV you exported from Google Sheets:</p>
            <input type="file" name="csv" accept=".csv" required><br><br>
            <button type="submit">🚀 Import</button>
        </form>

        <br>
        <small>
            ⚠️ Columns dapat sa order na ito:<br>
            <code>date_saved, lot, prepared_by, grand_total, full_data</code>
        </small>
    </div>
</body>
</html>