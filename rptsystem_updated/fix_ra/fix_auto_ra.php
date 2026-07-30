<?php
// ============================================
// fix_auto_ra.php — Isang beses lang patakbuhin.
// Pinapalitan ang mga "AUTO-...timestamp" placeholder RA Number
// (na nagawa bago ang huling fix sa import_lot_full.php) ng
// mismong "code" value ng lot (hal. CRC0000RL0001) kung meron —
// mas malinaw at makabuluhan ito kaysa sa random placeholder.
//
// I-access sa: http://localhost/rptsystem/fix_auto_ra.php
// ============================================

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'rpt_system');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset("utf8mb4");

$fixed = 0; $skippedNoCode = 0; $skippedDup = 0; $log = [];

$res = $conn->query("SELECT id, ra_number, code FROM lot_inventory WHERE ra_number LIKE 'AUTO-%'");
while ($row = $res->fetch_assoc()) {
    $id = (int)$row['id'];
    $oldRA = $row['ra_number'];
    $code = trim((string)$row['code']);

    if ($code === '') { $skippedNoCode++; continue; }

    // Siguraduhing walang ibang row na gumagamit na ng code na ito bilang RA#.
    $chk = $conn->prepare("SELECT id FROM lot_inventory WHERE ra_number = ? AND id <> ? LIMIT 1");
    $chk->bind_param("si", $code, $id);
    $chk->execute();
    if ($chk->get_result()->fetch_assoc()) { $skippedDup++; $chk->close(); continue; }
    $chk->close();

    $upd = $conn->prepare("UPDATE lot_inventory SET ra_number = ? WHERE id = ?");
    $upd->bind_param("si", $code, $id);
    $upd->execute();
    $upd->close();

    $fixed++;
    if (count($log) < 300) $log[] = "$oldRA  ->  $code";
}
?>
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Fix AUTO- RA Numbers</title>
<style>body{font-family:sans-serif;background:#111;color:#eee;padding:24px}
.ok{color:#4ade80}.warn{color:#f87171}
pre{background:#1a1a1a;padding:12px;border-radius:8px;max-height:400px;overflow:auto}
</style></head>
<body>
<h2>Fix AUTO- Placeholder RA Numbers</h2>
<p class="ok">✅ Na-fix (naka-palit na ng code bilang RA#): <?= $fixed ?></p>
<p class="warn">⚠️ Nilaktawan (walang code): <?= $skippedNoCode ?></p>
<p class="warn">⚠️ Nilaktawan (may ibang lot na gumagamit na ng code na ito bilang RA#, dapat manual check): <?= $skippedDup ?></p>
<?php if ($log): ?>
  <p>Listahan ng na-palitan:</p>
  <pre><?= htmlspecialchars(implode("\n", $log)) ?></pre>
<?php endif; ?>
</body>
</html>
