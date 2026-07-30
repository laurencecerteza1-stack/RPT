<?php
// ============================================
// import_lot_inventory.php — CSV Importer for lot_inventory (FULL columns)
// I-lagay sa: htdocs/rptsystem/import_lot_inventory.php
// I-access sa: http://localhost/rptsystem/import_lot_inventory.php
//
// NOTE: Malaki ang CSV (~250k rows / ~80MB). Kung mag-fail ang upload,
// itaas ang upload_max_filesize at post_max_size sa php.ini (e.g. 150M),
// then i-restart ang Apache sa XAMPP.
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

$conn->query("
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

$success = 0;
$skipped = 0;
$errors  = [];

$cols = ['class','subdivision','sub','ph','blk','lot','ra_number','lot_area','house_area','cts_no','control_no','code','buyers_name','location','lot_owner','tct_no','remarks','transferred_tct','unit','pin_no','td_no_old','td_no_latest','sale_type','lot_type','paid_tdate','sale_date','terms','lot_price','house_price','contract_price','rel_date','final_area','marketing','tel_no','email','address','column1','column2','birth_date','tin','remarks2'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv'])) {
    set_time_limit(0);
    $file = $_FILES['csv']['tmp_name'];
    $handle = fopen($file, 'r');
    fgetcsv($handle); // skip header

    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $updateParts = [];
    foreach ($cols as $c) if ($c !== 'ra_number') $updateParts[] = "$c=VALUES($c)";
    $updateSet = implode(',', $updateParts);
    $sql = "INSERT INTO lot_inventory (" . implode(',', $cols) . ") VALUES ($placeholders) ON DUPLICATE KEY UPDATE $updateSet";
    $stmt = $conn->prepare($sql);

    $vals = array_fill(0, count($cols), '');
    $types = str_repeat('s', count($cols));
    $refs = [];
    foreach ($vals as $k => $v) $refs[$k] = &$vals[$k];
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);

    $conn->begin_transaction();
    $raIdx = array_search('ra_number', $cols);
    $subIdx = array_search('sub', $cols);
    while (($row = fgetcsv($handle)) !== false) {
        if (empty(trim($row[$raIdx] ?? ''))) { $skipped++; continue; }
        // Tanggihan ang mga row na title/subtitle/header ng isang exported
        // REPORT (hal. "Subdivision Monitor Report - Generated: ...") na
        // baka na-upload nang mali sa halip na ang tunay na lot_inventory CSV.
        $raVal = trim($row[$raIdx] ?? '');
        $subVal = trim($row[$subIdx] ?? '');
        if (stripos($raVal, 'Subdivision Monitor Report') !== false
            || stripos($raVal, 'Generated:') !== false
            || preg_match('/^Municipal\s*:/i', $raVal)
            || mb_strtolower($raVal) === 'ra number' || mb_strtolower($raVal) === 'ra#'
            || mb_strtolower($subVal) === 'subdivision' || mb_strtolower($subVal) === 'subd') {
            $skipped++; continue;
        }
        foreach ($cols as $i => $c) $vals[$i] = trim($row[$i] ?? '');
        if ($stmt->execute()) {
            $success++;
        } else {
            $errors[] = "RA " . $vals[$raIdx] . ": " . $stmt->error;
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
<title>Import Lot Inventory</title>
<style>
body{font-family:Arial,sans-serif;background:#0f1117;color:#e2e8f0;padding:40px;max-width:640px;margin:0 auto}
h2{margin-bottom:4px}
p.sub{color:#94a3b8;font-size:13px;margin-top:0}
.card{background:#1a1f2e;border:1px solid #2d3748;border-radius:12px;padding:24px;margin-top:20px}
input[type=file]{margin:14px 0;color:#e2e8f0}
button{background:#f1c40f;color:#1a1f2e;border:none;padding:10px 20px;border-radius:8px;font-weight:600;cursor:pointer}
.result{margin-top:16px;padding:12px;border-radius:8px;background:#14291e;color:#4ade80;font-size:13px}
.err{color:#f87171;font-size:12px;margin-top:6px}
</style>
</head>
<body>
<h2>📋 Import Lot Inventory (Full Columns)</h2>
<p class="sub">Upload the <code>lot_inventory_full_import.csv</code> (lahat ng 41 columns galing SLLI-SLRDI.xlsx). Re-uploading updates existing RA# rows, hindi duplicate.</p>
<div class="card">
  <form method="post" enctype="multipart/form-data">
    <input type="file" name="csv" accept=".csv" required>
    <br><button type="submit">Upload &amp; Import</button>
  </form>
  <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
    <div class="result">
      ✅ Imported/Updated: <?= $success ?><br>
      ⚠️ Skipped (no RA#): <?= $skipped ?>
      <?php if ($errors): ?><div class="err"><?= implode('<br>', array_slice($errors, 0, 20)) ?></div><?php endif; ?>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
