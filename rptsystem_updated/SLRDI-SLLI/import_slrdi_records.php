<?php
// ============================================
// import_slrdi_records.php — CSV Importer papunta sa slrdi_records
// (existing table/system na ginagamit na ng api.php mo)
// I-lagay sa: htdocs/rptsystem/import_slrdi_records.php
// I-access sa: http://localhost/rptsystem/import_slrdi_records.php
//
// Upload ang td_requests_import.csv.
// Gagamitin nito ang parehong slrdi_records + slrdi_activity_log
// tables na ginagamit na ng saveSlrdiRecord() sa api.php mo,
// kaya makikita rin ito sa SLRDI page/app mo mismo (hindi bagong table).
// ============================================

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'rpt_system');

session_start();
$current_user = $_SESSION['username'] ?? 'ADMIN';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset("utf8mb4");

// ---- Same table structure na ginagamit ng api.php mo (ensureSlrdiRecordsTable) ----
$conn->query("
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

$conn->query("
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

function logSlrdiActivity($conn, $slrdiId, $action, $changedBy, $note = null) {
    $stmt = $conn->prepare("INSERT INTO slrdi_activity_log (slrdi_id, action, note, changed_by, changed_at) VALUES (?,?,?,?,NOW())");
    $stmt->bind_param("isss", $slrdiId, $action, $note, $changedBy);
    $stmt->execute();
    $stmt->close();
}

$success = 0;
$skipped = 0;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv'])) {
    set_time_limit(0);
    $file = $_FILES['csv']['tmp_name'];
    $orig_name = $_FILES['csv']['name'];
    $handle = fopen($file, 'r');
    fgetcsv($handle); // skip header row

    $sql = "INSERT INTO slrdi_records
                (ra_no, subd, ph, blk, lot, description, buyer, tra_no, turn_over_date, remarks, created_by, date_saved)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);

    $conn->begin_transaction();

    while (($row = fgetcsv($handle)) !== false) {
        // CSV columns: source_year, request_date, seq_no, subd, phase, blk, lot, description, buyers_name, tra_no, turnover_date, remarks
        $request_date  = trim($row[1] ?? '');
        $subd          = trim($row[3] ?? '');
        $phase         = trim($row[4] ?? '');
        $blk           = trim($row[5] ?? '');
        $lot           = trim($row[6] ?? '');
        $description   = trim($row[7] ?? '');
        $buyer         = trim($row[8] ?? '');
        $tra_no        = trim($row[9] ?? '');
        $turnover_date = trim($row[10] ?? '');
        $remarks       = trim($row[11] ?? '');

        if (empty($subd) && empty($lot) && empty($description)) {
            $skipped++;
            continue;
        }

        $ra_no      = null; // walang RA# sa source xlsm, iniiwan lang blangko
        $created_by = 'IMPORT (CSV)';
        // gamitin ang request_date (kung meron) bilang date_saved, kung wala default NOW()
        $date_saved = $request_date ? $request_date . ' 00:00:00' : date('Y-m-d H:i:s');

        $stmt->bind_param(
            "ssssssssssss",
            $ra_no, $subd, $phase, $blk, $lot, $description, $buyer, $tra_no, $turnover_date, $remarks, $created_by, $date_saved
        );

        if ($stmt->execute()) {
            $newId = $conn->insert_id;
            logSlrdiActivity($conn, $newId, 'created',
                $created_by,
                "Imported from CSV '$orig_name' (Subd: $subd, Lot: $lot, Buyer: $buyer)"
            );
            $success++;
        } else {
            $errors[] = "Row (subd $subd, lot $lot): " . $stmt->error;
        }
    }

    $conn->commit();
    $stmt->close();
    fclose($handle);
}

$recentLogs = $conn->query("SELECT * FROM slrdi_activity_log ORDER BY changed_at DESC LIMIT 15");
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Import SLRDI Records</title>
<style>
body{font-family:Arial,sans-serif;background:#0f1117;color:#e2e8f0;padding:40px;max-width:800px;margin:0 auto}
h2{margin-bottom:4px}
p.sub{color:#94a3b8;font-size:13px;margin-top:0}
.card{background:#1a1f2e;border:1px solid #2d3748;border-radius:12px;padding:24px;margin-top:20px}
input[type=file]{margin:14px 0;color:#e2e8f0}
button{background:#f1c40f;color:#1a1f2e;border:none;padding:10px 20px;border-radius:8px;font-weight:600;cursor:pointer}
.result{margin-top:16px;padding:12px;border-radius:8px;background:#14291e;color:#4ade80;font-size:13px}
.err{color:#f87171;font-size:12px;margin-top:6px}
table{width:100%;border-collapse:collapse;margin-top:10px;font-size:12px}
th,td{padding:6px 8px;border-bottom:1px solid #2d3748;text-align:left}
th{color:#94a3b8}
.warn{color:#facc15;font-size:12px;margin-top:8px}
</style>
</head>
<body>
<h2>📄 Import SLRDI Records</h2>
<p class="sub">Upload ang <code>td_requests_import.csv</code>. Diretso itong ipapasok sa <code>slrdi_records</code> — parehong table na ginagamit ng SLRDI page/app mo (hindi bagong table).</p>
<div class="card">
  <form method="post" enctype="multipart/form-data">
    <input type="file" name="csv" accept=".csv" required>
    <br><button type="submit">Upload &amp; Import</button>
  </form>
  <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
    <div class="result">
      ✅ Naitala: <?= $success ?><br>
      ⚠️ Skipped (walang laman): <?= $skipped ?>
      <?php if ($errors): ?><div class="err"><?= implode('<br>', array_slice($errors, 0, 20)) ?></div><?php endif; ?>
    </div>
    <div class="warn">⚠️ Tandaan: walang unique key check ang import na ito (para hindi masira ang existing structure ng slrdi_records). Kung ito ang 2nd/3rd mo nang pag-upload ng parehong CSV, ma-du-duplicate ang mga record. I-run lang ito nang ISANG BESES.</div>
  <?php endif; ?>
</div>

<div class="card">
  <h3 style="margin-top:0">🕒 Recent SLRDI Activity Log</h3>
  <table>
    <tr><th>Date</th><th>Action</th><th>By</th><th>Note</th></tr>
    <?php while ($log = $recentLogs->fetch_assoc()): ?>
    <tr>
      <td><?= htmlspecialchars($log['changed_at']) ?></td>
      <td><?= htmlspecialchars($log['action']) ?></td>
      <td><?= htmlspecialchars($log['changed_by']) ?></td>
      <td><?= htmlspecialchars($log['note']) ?></td>
    </tr>
    <?php endwhile; ?>
  </table>
</div>
</body>
</html>
