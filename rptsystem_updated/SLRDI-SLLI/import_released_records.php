<?php
// ============================================
// import_released_records.php — CSV Importer papunta sa released_titles
// I-lagay sa: htdocs/rptsystem/import_released_records.php
// I-access sa: http://localhost/rptsystem/import_released_records.php
//
// Upload ang released_titles_import.csv.
// Gagawa ito ng released_titles table (parehong ginagamit na
// ng "Released" tab mismo sa app mo, sa pamamagitan ng api.php).
// ============================================

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'rpt_system');

session_start();
$current_user = $_SESSION['username'] ?? 'ADMIN';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset("utf8mb4");

$conn->query("
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

$success = 0;
$skipped = 0;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv'])) {
    set_time_limit(0);
    $file = $_FILES['csv']['tmp_name'];
    $orig_name = $_FILES['csv']['name'];
    $handle = fopen($file, 'r');
    fgetcsv($handle); // skip header row

    // CSV columns: date_released, year, buyer, subd, ph, blk, lot, ra_no, transferred_title, original_title, owner
    $sql = "INSERT INTO released_titles
                (date_released, year, buyer, subd, ph, blk, lot, ra_no, transferred_title, original_title, owner, created_by, date_saved)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);

    $conn->begin_transaction();

    while (($row = fgetcsv($handle)) !== false) {
        $date_released     = trim($row[0] ?? '');
        $year              = trim($row[1] ?? '');
        $buyer             = trim($row[2] ?? '');
        $subd              = trim($row[3] ?? '');
        $ph                = trim($row[4] ?? '');
        $blk               = trim($row[5] ?? '');
        $lot               = trim($row[6] ?? '');
        $ra_no             = trim($row[7] ?? '');
        $transferred_title = trim($row[8] ?? '');
        $original_title    = trim($row[9] ?? '');
        $owner             = trim($row[10] ?? '');

        if (empty($buyer) && empty($subd) && empty($ra_no)) {
            $skipped++;
            continue;
        }

        $created_by = 'IMPORT (CSV)';

        $stmt->bind_param(
            "ssssssssssss",
            $date_released, $year, $buyer, $subd, $ph, $blk, $lot, $ra_no, $transferred_title, $original_title, $owner, $created_by
        );
        if ($stmt->execute()) {
            $success++;
        } else {
            $errors[] = "Row (RA# $ra_no, buyer $buyer): " . $stmt->error;
        }
    }

    $conn->commit();
    $stmt->close();
    fclose($handle);
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Import Released Title Records</title>
<style>
body{font-family:Arial,sans-serif;background:#0f1117;color:#e2e8f0;padding:40px;max-width:800px;margin:0 auto}
h2{margin-bottom:4px}
p.sub{color:#94a3b8;font-size:13px;margin-top:0}
.card{background:#1a1f2e;border:1px solid #2d3748;border-radius:12px;padding:24px;margin-top:20px}
input[type=file]{margin:14px 0;color:#e2e8f0}
button{background:#1a73e8;color:#fff;border:none;padding:10px 18px;border-radius:8px;cursor:pointer;font-size:14px}
button:hover{background:#1557b0}
.result{margin-top:20px;padding:14px;border-radius:8px;background:#111827;border:1px solid #2d3748}
.ok{color:#22c55e}.err{color:#f87171}
</style>
</head>
<body>
<h2>📦 Import Released Title Records</h2>
<p class="sub">Upload ang released_titles_import.csv papunta sa database (released_titles table).</p>
<div class="card">
<form method="POST" enctype="multipart/form-data">
  <input type="file" name="csv" accept=".csv" required>
  <br>
  <button type="submit">Upload &amp; Import</button>
</form>
</div>
<?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
<div class="result">
  <p class="ok">✓ Imported: <?php echo $success; ?> record(s)</p>
  <p>Skipped (walang RA#/Buyer/Subd): <?php echo $skipped; ?></p>
  <?php if ($errors): ?>
    <p class="err">Errors:</p>
    <ul>
    <?php foreach (array_slice($errors,0,20) as $e): ?>
      <li class="err"><?php echo htmlspecialchars($e); ?></li>
    <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
<?php endif; ?>
</body>
</html>
