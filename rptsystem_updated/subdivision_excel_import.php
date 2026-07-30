<?php
// ============================================
// subdivision_excel_import.php — Drag-and-drop ng RAW subdivision
// monitoring Excel file(s) (kahit anong layout — S/P/B/L + Status +
// per-year OR columns), kusang:
//   1. Tinatawag ang extract_lot_data_v2.py (Python, header-driven na
//      parser) para gawing lot_details.csv + or_history.csv
//   2. Direktang ini-import ang parehong CSV papunta sa lot_inventory
//      at lot_or_history (parehong logic ng import_lot_full.php)
//   3. Naka-log ang orihinal na filename sa source_file column
// KAILANGAN: python3 (o py -3) na naka-install at accessible sa PATH,
// pati openpyxl (`pip install openpyxl`), at extract_lot_data_v2.py sa
// parehong folder nito.
// I-access sa: http://localhost/rptsystem/subdivision_excel_import.php
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

function ensureCol($conn, $table, $col, $ddl) {
    $r = $conn->query("SHOW COLUMNS FROM $table LIKE '$col'");
    if ($r && $r->num_rows === 0) $conn->query("ALTER TABLE $table ADD COLUMN $ddl");
}
ensureCol($conn, 'lot_inventory', 'assessed_value', "assessed_value DECIMAL(15,2) DEFAULT NULL");
ensureCol($conn, 'lot_inventory', 'td1', "td1 VARCHAR(100) DEFAULT NULL");
ensureCol($conn, 'lot_inventory', 'td2', "td2 VARCHAR(100) DEFAULT NULL");
ensureCol($conn, 'lot_inventory', 'td3', "td3 VARCHAR(100) DEFAULT NULL");
ensureCol($conn, 'lot_inventory', 'td_extra', "td_extra TEXT DEFAULT NULL");
ensureCol($conn, 'lot_inventory', 'source_file', "source_file VARCHAR(150) DEFAULT NULL");
ensureCol($conn, 'lot_inventory', 'status', "status VARCHAR(100) DEFAULT NULL");
ensureCol($conn, 'lot_inventory', 'date_fullpayment', "date_fullpayment VARCHAR(50) DEFAULT NULL");
ensureCol($conn, 'lot_inventory', 'last_or', "last_or VARCHAR(100) DEFAULT NULL");
ensureCol($conn, 'lot_inventory', 'soa_batch', "soa_batch VARCHAR(100) DEFAULT NULL");
ensureCol($conn, 'lot_inventory', 'soa_year', "soa_year VARCHAR(50) DEFAULT NULL");

$conn->query("
    CREATE TABLE IF NOT EXISTS lot_or_history (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        lot_inventory_id INT NOT NULL,
        yr               INT NOT NULL,
        as_no            VARCHAR(100) DEFAULT NULL,
        jv_no            VARCHAR(100) DEFAULT NULL,
        mc_liaison       VARCHAR(100) DEFAULT NULL,
        or_number        VARCHAR(100) DEFAULT NULL,
        fr               VARCHAR(50)  DEFAULT NULL,
        `to`             VARCHAR(50)  DEFAULT NULL,
        amount           DECIMAL(15,2) DEFAULT NULL,
        or_date          VARCHAR(50)  DEFAULT NULL,
        remarks          VARCHAR(255) DEFAULT NULL,
        source           VARCHAR(50)  DEFAULT 'crc_import',
        UNIQUE KEY uniq_lot_year_or (lot_inventory_id, yr, or_number),
        INDEX idx_lot (lot_inventory_id)
    )
");

function _normPart($v) {
    $v = trim((string)$v);
    if ($v === '') return '';
    if (preg_match('/^[0-9]+$/', $v)) return (string)((int)$v);
    return mb_strtoupper($v);
}
function _normPlain($v) { $v = trim((string)$v); return $v === '' ? '' : mb_strtoupper($v); }

function _padCode($v, $len) {
    $v = trim((string)$v);
    if ($v === '') return str_repeat('0', $len);
    if (preg_match('/^[0-9]+$/', $v)) return str_pad($v, $len, '0', STR_PAD_LEFT);
    return mb_strtoupper($v);
}
function buildLotCode($sub, $ph, $blk, $lot) {
    $sub = mb_strtoupper(trim($sub));
    if ($sub === '') return '';
    return $sub . _padCode($ph, 3) . _padCode($blk, 3) . _padCode($lot, 4);
}

function _looksLikeGarbageRow($raNumber, $sub, $ph, $blk, $lot) {
    $vals = array_map('trim', [$raNumber, $sub, $ph, $blk, $lot]);
    foreach ($vals as $v) {
        if ($v === '') continue;
        if (stripos($v, 'Subdivision Monitor Report') !== false) return true;
        if (stripos($v, 'Generated:') !== false) return true;
        if (preg_match('/^Municipal\s*:/i', $v)) return true;
    }
    $headerLabels = ['class','sub','subd','subdivision','ph','phase','blk','block','lot',
        'ra number','ra#','buyer\'s name','buyers name','tct no.','tct no','assessed value',
        'status','particulars','lot owner','td no.','td no'];
    $normVals = array_map(fn($v) => mb_strtolower(trim($v)), $vals);
    $matches = 0;
    foreach ($normVals as $v) if ($v !== '' && in_array($v, $headerLabels, true)) $matches++;
    if ($matches >= 2) return true;
    return false;
}

$RA_INDEX = [];
$KEY_INDEX = [];
$preload = $conn->query("SELECT id, ra_number, sub, ph, blk, lot FROM lot_inventory");
while ($r = $preload->fetch_assoc()) {
    if ($r['ra_number'] !== '' && $r['ra_number'] !== null) $RA_INDEX[$r['ra_number']] = (int)$r['id'];
    if ($r['sub'] !== null && $r['blk'] !== null && $r['lot'] !== null && $r['sub'] !== '' && $r['blk'] !== '' && $r['lot'] !== '') {
        $k = _normPlain($r['sub']) . '|' . _normPlain($r['ph']) . '|' . _normPart($r['blk']) . '|' . _normPart($r['lot']);
        $KEY_INDEX[$k] = (int)$r['id'];
    }
}

function findLotId($conn, $raNumber, $sub, $ph, $blk, $lot) {
    global $RA_INDEX, $KEY_INDEX;
    if ($raNumber !== '' && isset($RA_INDEX[$raNumber])) return $RA_INDEX[$raNumber];
    if ($sub !== '' && $blk !== '' && $lot !== '') {
        $k = _normPlain($sub) . '|' . _normPlain($ph) . '|' . _normPart($blk) . '|' . _normPart($lot);
        if (isset($KEY_INDEX[$k])) return $KEY_INDEX[$k];
    }
    return 0;
}

function createLotIfMissing($conn, $raNumber, $sub, $ph, $blk, $lot, $code = '') {
    global $RA_INDEX, $KEY_INDEX;
    static $stmt = null;
    $raNumber = trim($raNumber); $sub = trim($sub); $ph = trim($ph); $blk = trim($blk); $lot = trim($lot); $code = trim($code);
    if ($raNumber === '' && $sub === '' && $blk === '' && $lot === '') return 0;
    $finalRA = $raNumber !== '' ? $raNumber : $code;
    if ($finalRA === '') {
        $base = buildLotCode($sub, $ph, $blk, $lot);
        if ($base === '') return 0;
        $finalRA = $base;
        $suffix = 2;
        while (isset($RA_INDEX[$finalRA])) { $finalRA = $base . '-' . $suffix; $suffix++; }
    }
    if ($stmt === null) {
        $stmt = $conn->prepare("INSERT INTO lot_inventory (ra_number, sub, ph, blk, lot) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id");
    }
    $stmt->bind_param("sssss", $finalRA, $sub, $ph, $blk, $lot);
    $stmt->execute();
    $newId = $stmt->insert_id;
    if (!$newId) {
        $f = $conn->prepare("SELECT id FROM lot_inventory WHERE ra_number = ? LIMIT 1");
        $f->bind_param("s", $finalRA);
        $f->execute();
        $res = $f->get_result()->fetch_assoc();
        $newId = $res ? (int)$res['id'] : 0;
    }
    if ($newId) {
        $RA_INDEX[$finalRA] = $newId;
        if ($sub !== '' && $blk !== '' && $lot !== '') {
            $k = _normPlain($sub) . '|' . _normPlain($ph) . '|' . _normPart($blk) . '|' . _normPart($lot);
            $KEY_INDEX[$k] = $newId;
        }
    }
    return $newId;
}

function importLotDetailsCsv($conn, $csvPath, $sourceFile, &$stats) {
    $handle = fopen($csvPath, 'r');
    if (!$handle) return;
    fgetcsv($handle); // skip header
    $stmt = $conn->prepare("
        UPDATE lot_inventory
        SET lot_area = IF(?='', lot_area, ?), house_area = IF(?='', house_area, ?),
            code = IF(?='', code, ?), cts_no = IF(?='', cts_no, ?),
            buyers_name = IF(?='', buyers_name, ?), location = IF(?='', location, ?),
            lot_owner = IF(?='', lot_owner, ?), tct_no = IF(?='', tct_no, ?),
            status = IF(?='', status, ?), date_fullpayment = IF(?='', date_fullpayment, ?),
            remarks = IF(?='', remarks, ?), pin_no = IF(?='', pin_no, ?),
            td1 = IF(?='', td1, ?), td2 = IF(?='', td2, ?), td3 = IF(?='', td3, ?),
            td_extra = IF(?='', td_extra, ?),
            assessed_value = IF(?='', assessed_value, ?), last_or = IF(?='', last_or, ?),
            soa_batch = IF(?='', soa_batch, ?), soa_year = IF(?='', soa_year, ?),
            source_file = IF(?='', source_file, ?)
        WHERE id = ?
    ");
    $conn->autocommit(false);
    $rowsInTxn = 0;
    while (($row = fgetcsv($handle)) !== false) {
        [$raNumber,$sub,$ph,$blk,$lot,$area,$houseArea,$code,$cts,$buyer,$location,$lotOwner,
         $title,$status,$dateFullpay,$remarks,$pin,$td1,$td2,$td3,$tdExtra,$av,$lastOr,$soaBatch,$soaYear] =
            array_pad($row, 25, '');

        $lotId = findLotId($conn, trim($raNumber), trim($sub), trim($ph), trim($blk), trim($lot));
        if (!$lotId) {
            if (_looksLikeGarbageRow($raNumber, $sub, $ph, $blk, $lot)) { $stats['skipped']++; continue; }
            $lotId = createLotIfMissing($conn, $raNumber, $sub, $ph, $blk, $lot, $code);
            if (!$lotId) { $stats['notFound']++; continue; }
            $stats['created']++;
        }
        $avStr = trim($av) === '' ? '' : number_format((float)$av, 2, '.', '');
        $stmt->bind_param(
            "ssssssssssssssssssssssssssssssssssssssssssi",
            $area,$area, $houseArea,$houseArea, $code,$code, $cts,$cts, $buyer,$buyer,
            $location,$location, $lotOwner,$lotOwner, $title,$title, $status,$status,
            $dateFullpay,$dateFullpay, $remarks,$remarks, $pin,$pin,
            $td1,$td1, $td2,$td2, $td3,$td3, $tdExtra,$tdExtra,
            $avStr,$avStr, $lastOr,$lastOr, $soaBatch,$soaBatch, $soaYear,$soaYear,
            $sourceFile,$sourceFile,
            $lotId
        );
        $stmt->execute();
        $stats['updated']++;
        $rowsInTxn++;
        if ($rowsInTxn >= 2000) { $conn->commit(); $rowsInTxn = 0; }
    }
    $conn->commit();
    $conn->autocommit(true);
    fclose($handle);
}

function importOrHistoryCsv($conn, $csvPath, &$stats) {
    $handle = fopen($csvPath, 'r');
    if (!$handle) return;
    fgetcsv($handle);
    $stmt = $conn->prepare("
        INSERT INTO lot_or_history (lot_inventory_id, yr, as_no, jv_no, mc_liaison, or_number, fr, `to`, amount, or_date, remarks)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE as_no=VALUES(as_no), jv_no=VALUES(jv_no), mc_liaison=VALUES(mc_liaison),
            amount=VALUES(amount), or_date=VALUES(or_date), remarks=VALUES(remarks)
    ");
    $conn->autocommit(false);
    $rowsInTxn = 0;
    while (($row = fgetcsv($handle)) !== false) {
        [$raNumber,$sub,$ph,$blk,$lot,$year,$asNo,$jvNo,$mcLiaison,$orNumber,$fr,$to,$amount,$date,$remarks] =
            array_pad($row, 15, '');

        $lotId = findLotId($conn, trim($raNumber), trim($sub), trim($ph), trim($blk), trim($lot));
        if (!$lotId) {
            if (_looksLikeGarbageRow($raNumber, $sub, $ph, $blk, $lot)) { $stats['skipped']++; continue; }
            $lotId = createLotIfMissing($conn, $raNumber, $sub, $ph, $blk, $lot);
            if (!$lotId) { $stats['notFound']++; continue; }
            $stats['created']++;
        }
        $yr = (int)$year;
        $amt = trim((string)$amount) === '' ? null : (float)$amount;
        $orNumberSafe = trim($orNumber) !== '' ? trim($orNumber) : ('-' . $yr . '-' . $lotId);
        $stmt->bind_param("iissssssdss", $lotId, $yr, $asNo, $jvNo, $mcLiaison, $orNumberSafe, $fr, $to, $amt, $date, $remarks);
        $stmt->execute();
        $stats['updated']++;
        $rowsInTxn++;
        if ($rowsInTxn >= 2000) { $conn->commit(); $rowsInTxn = 0; }
    }
    $conn->commit();
    $conn->autocommit(true);
    fclose($handle);
}

// ---- Hanapin ang python executable ----
function findPython(&$diag) {
    if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))))) {
        $diag[] = "Naka-disable ang PHP exec()/shell_exec() sa server (tingnan ang disable_functions sa php.ini).";
        return null;
    }
    $candidates = ['python3', 'python', 'py -3', 'py'];
    // Karaniwang lokasyon ng Python sa Windows kapag hindi naka-add sa PATH ng Apache service
    $winGlobs = array_merge(
        glob('C:/Python3*/python.exe') ?: [],
        glob('C:/Users/*/AppData/Local/Programs/Python/Python3*/python.exe') ?: [],
        glob('C:/xampp/python/python.exe') ?: []
    );
    foreach ($winGlobs as $w) $candidates[] = '"' . $w . '"';

    foreach ($candidates as $cand) {
        $out = [];
        @exec("$cand --version 2>&1", $out, $code);
        $diag[] = "Sinubukan: `$cand --version` -> exit code $code, output: " . implode(' ', $out);
        if ($code === 0) return $cand;
    }
    return null;
}

$results = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['excels'])) {
    $pyDiag = [];
    $python = findPython($pyDiag);
    if (!$python) {
        $errors[] = "Hindi mahanap ang python3/python sa server. I-install ang Python 3 + `pip install openpyxl` at siguraduhing naka-add sa PATH — o kaya i-check ang diagnostic log sa ibaba.";
        $errors[] = "<pre>" . htmlspecialchars(implode("\n", $pyDiag)) . "</pre>";
    } else {
        $workDir = sys_get_temp_dir() . '/subdiv_import_' . uniqid();
        mkdir($workDir);
        $uploadedPaths = [];
        $names = $_FILES['excels']['name'];
        $tmpNames = $_FILES['excels']['tmp_name'];
        foreach ($names as $i => $origName) {
            if ($_FILES['excels']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $safeName = preg_replace('/[^A-Za-z0-9._\- ]/', '_', $origName);
            $dest = $workDir . '/' . $safeName;
            if (move_uploaded_file($tmpNames[$i], $dest)) {
                $uploadedPaths[] = $dest;
            }
        }

        if (!$uploadedPaths) {
            $errors[] = "Walang na-upload na valid na .xlsx file.";
        } else {
            $scriptPath = __DIR__ . '/extract_lot_data_v2.py';
            $outDir = $workDir . '/out';
            mkdir($outDir);
            $cmdParts = [$python, escapeshellarg($scriptPath), '-o', escapeshellarg($outDir)];
            foreach ($uploadedPaths as $p) $cmdParts[] = escapeshellarg($p);
            $cmd = implode(' ', $cmdParts) . ' 2>&1';
            $output = shell_exec($cmd);

            $lotCsv = $outDir . '/lot_details.csv';
            $orCsv  = $outDir . '/or_history.csv';

            if (!file_exists($lotCsv) || !file_exists($orCsv)) {
                $errors[] = "Nabigo ang pag-convert ng Excel. Log ng parser:";
                $errors[] = $output;
            } else {
                $stats = ['updated' => 0, 'created' => 0, 'notFound' => 0, 'skipped' => 0];
                $label = implode(', ', array_map('basename', $uploadedPaths));
                importLotDetailsCsv($conn, $lotCsv, $label, $stats);
                importOrHistoryCsv($conn, $orCsv, $stats);
                $results = [
                    'files' => array_map('basename', $uploadedPaths),
                    'stats' => $stats,
                    'log'   => $output,
                ];
            }
        }

        // linisin ang temp files
        array_map('unlink', glob($workDir . '/*.xlsx'));
        @unlink($lotCsv ?? '');
        @unlink($orCsv ?? '');
        @rmdir($outDir ?? '');
        @rmdir($workDir);
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Subdivision Excel Auto-Import</title>
<style>
body{font-family:sans-serif;background:#111;color:#eee;padding:24px;max-width:700px;margin:0 auto}
h2{margin-bottom:4px}
.sub{color:#999;margin-top:0}
#dropzone{border:2px dashed #555;border-radius:12px;padding:40px;text-align:center;cursor:pointer;transition:.15s;margin:20px 0}
#dropzone.drag{border-color:#4ade80;background:#1a2e1a}
#dropzone p{margin:6px 0;color:#aaa}
#filelist{margin:10px 0;font-size:14px;color:#ddd}
button{padding:10px 20px;background:#4ade80;color:#111;border:none;border-radius:8px;font-weight:bold;cursor:pointer}
button:disabled{background:#555;color:#999;cursor:not-allowed}
.ok{color:#4ade80}.warn{color:#f87171}
pre{background:#1a1a1a;padding:12px;border-radius:8px;max-height:260px;overflow:auto;font-size:12px}
.card{border:1px solid #333;border-radius:8px;padding:16px;margin-top:16px}
</style>
</head>
<body>
<h2>📥 Subdivision Excel Auto-Import</h2>
<p class="sub">I-drag-drop ang raw subdivision monitoring Excel file(s) — kahit anong layout, kusa na itong iko-convert at direktang ii-import sa Lot Inventory + OR History.</p>

<?php if ($errors): ?>
  <div class="card"><p class="warn">⚠️ <?= implode('<br>', array_map(fn($e) => strpos($e, '<pre>') === 0 ? $e : htmlspecialchars($e), $errors)) ?></p></div>
<?php endif; ?>

<?php if ($results): ?>
  <div class="card">
    <p class="ok">✅ Na-import: <?= htmlspecialchars(implode(', ', $results['files'])) ?></p>
    <p>Na-update: <?= $results['stats']['updated'] ?> row(s) | Bagong lot: <?= $results['stats']['created'] ?> |
       Hindi na-match: <?= $results['stats']['notFound'] ?> | Skipped (garbage rows): <?= $results['stats']['skipped'] ?></p>
    <details><summary>Parser log</summary><pre><?= htmlspecialchars($results['log']) ?></pre></details>
  </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" id="uploadForm">
  <div id="dropzone">
    <p><strong>I-drop ang .xlsx file(s) dito</strong></p>
    <p>o i-click para pumili ng file</p>
    <input type="file" name="excels[]" id="fileInput" accept=".xlsx" multiple style="display:none">
  </div>
  <div id="filelist"></div>
  <button type="submit" id="submitBtn" disabled>Convert &amp; Import</button>
</form>

<script>
const dz = document.getElementById('dropzone');
const input = document.getElementById('fileInput');
const list = document.getElementById('filelist');
const btn = document.getElementById('submitBtn');
const form = document.getElementById('uploadForm');

dz.addEventListener('click', () => input.click());
['dragenter','dragover'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.add('drag'); }));
['dragleave','drop'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.remove('drag'); }));
dz.addEventListener('drop', e => {
  input.files = e.dataTransfer.files;
  renderList();
});
input.addEventListener('change', renderList);

function renderList() {
  const files = input.files;
  if (!files.length) { list.innerHTML = ''; btn.disabled = true; return; }
  list.innerHTML = '<strong>' + files.length + ' file(s):</strong><br>' +
    Array.from(files).map(f => '• ' + f.name).join('<br>');
  btn.disabled = false;
}
form.addEventListener('submit', () => { btn.disabled = true; btn.textContent = 'Kino-convert at ini-import...'; });
</script>
</body>
</html>