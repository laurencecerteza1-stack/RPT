<?php
// ============================================
// import_slli_records.php — CSV Importer papunta sa slli_records
// I-lagay sa: htdocs/rptsystem/import_slli_records.php
// I-access sa: http://localhost/rptsystem/import_slli_records.php
//
// Upload ang slli_records_import.csv.
// Gagamitin nito ang slli_records + slli_activity_log tables
// (parehong ginagamit na ng SLLI tab mismo sa app mo).
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
    CREATE TABLE IF NOT EXISTS slli_records (
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
        date_received   VARCHAR(20)  DEFAULT NULL,
        turnover_mars   VARCHAR(20)  DEFAULT NULL,
        created_by      VARCHAR(100) DEFAULT NULL,
        date_saved      DATETIME DEFAULT NOW()
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$conn->query("
    CREATE TABLE IF NOT EXISTS slli_activity_log (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        slli_id       INT NOT NULL,
        action        VARCHAR(20) NOT NULL,
        field_name    VARCHAR(50)  DEFAULT NULL,
        old_value     VARCHAR(255) DEFAULT NULL,
        new_value     VARCHAR(255) DEFAULT NULL,
        note          VARCHAR(255) DEFAULT NULL,
        changed_by    VARCHAR(100) DEFAULT NULL,
        changed_at    DATETIME DEFAULT NOW(),
        INDEX idx_slli_id (slli_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

function logSlliActivity($conn, $slliId, $action, $changedBy, $note = null) {
    $stmt = $conn->prepare("INSERT INTO slli_activity_log (slli_id, action, note, changed_by, changed_at) VALUES (?,?,?,?,NOW())");
    $stmt->bind_param("isss", $slliId, $action, $note, $changedBy);
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

    $sql = "INSERT INTO slli_records
                (ra_no, subd, ph, blk, lot, description, buyer, tra_no, remarks, date_received, turnover_mars, created_by, date_saved)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);

    $conn->begin_transaction();

    while (($row = fgetcsv($handle)) !== false) {
        // CSV columns: source_year, request_date, subd, phase, blk, lot, description, buyers_name, tra_no, remarks, date_received, turnover_to_mars
        $request_date    = trim($row[1] ?? '');
        $subd            = trim($row[2] ?? '');
        $phase           = trim($row[3] ?? '');
        $blk             = trim($row[4] ?? '');
        $lot             = trim($row[5] ?? '');
        $description     = trim($row[6] ?? '');
        $buyer           = trim($row[7] ?? '');
        $tra_no          = trim($row[8] ?? '');
        $remarks         = trim($row[9] ?? '');
        $date_received   = trim($row[10] ?? '');
        $turnover_mars   = trim($row[11] ?? '');

        if (empty($subd) || empty($lot)) {
            $skipped++;
            continue;
        }

        $ra_no      = null; // walang RA# sa source xlsm, iniiwan lang blangko
        $created_by = 'IMPORT (CSV)';
        $date_saved = $request_date ? $request_date . ' 00:00:00' : date('Y-m-d H:i:s');

        $stmt->bind_param(
            "sssssssssssss",
            $ra_no, $subd, $phase, $blk, $lot, $description, $buyer, $tra_no, $remarks, $date_received, $turnover_mars, $created_by, $date_saved
        );

        if ($stmt->execute()) {
            $newId = $conn->insert_id;
            logSlliActivity($conn, $newId, 'created',
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

$recentLogs = $conn->query("SELECT * FROM slli_activity_log ORDER BY changed_at DESC LIMIT 15");
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Import SLLI Records</title>
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
<h2>📄 Import SLLI Records</h2>
<p class="sub">Upload ang <code>slli_records_import.csv</code>. Diretso itong ipapasok sa <code>slli_records</code> — parehong table na ginagamit ng SLLI page/app mo.</p>
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
    <div class="warn">⚠️ Tandaan: walang unique key check ang import na ito. I-run lang ito nang ISANG BESES para maiwasan ang duplicate.</div>
  <?php endif; ?>
</div>

<div class="card">
  <h3 style="margin-top:0">🕒 Recent SLLI Activity Log</h3>
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
