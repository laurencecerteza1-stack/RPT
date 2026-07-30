<?php
// ============================================
// import_lot_full.php — Buong import galing ang subdivision Excel file (parehong format: S/P/B/L + Status + per-year OR columns):
//   1. lot_details.csv  -> lot_inventory (lahat ng available na field:
//      area, house area, code, cts_no, buyer, location, lot_owner, title/tct,
//      status, date of fullpayment, remarks, pin, td, assessed value,
//      last OR#, SOA batch, SOA year)
//   2. or_history.csv   -> BAGONG table na lot_or_history (per-year na
//      OR/AS/JV/MC breakdown, 2007-2026 — hiwalay dahil "repeating group"
//      ito sa source file, isang row bawat taon bawat lot)
//
// Matching: RA Number muna (exact), fallback sa Subd/Ph/Blk/Lot
// (normalized — parehong logic ng Subdivision Monitoring, kaya walang
// leading-zero / extra-space na isyu).
//
// I-lagay sa: htdocs/rptsystem/import_lot_full.php
// I-access sa: http://localhost/rptsystem/import_lot_full.php
// Mag-upload ng lot_details.csv MUNA, i-click Import Lot Details.
// Pagkatapos, i-upload ang or_history.csv, i-click Import OR History.
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

// ---- Idempotent column/table setup ----
function ensureCol($conn, $table, $col, $ddl) {
    $r = $conn->query("SHOW COLUMNS FROM $table LIKE '$col'");
    if ($r && $r->num_rows === 0) $conn->query("ALTER TABLE $table ADD COLUMN $ddl");
}
ensureCol($conn, 'lot_inventory', 'assessed_value', "assessed_value DECIMAL(15,2) DEFAULT NULL");
ensureCol($conn, 'lot_inventory', 'td1', "td1 VARCHAR(100) DEFAULT NULL");
ensureCol($conn, 'lot_inventory', 'td2', "td2 VARCHAR(100) DEFAULT NULL");
ensureCol($conn, 'lot_inventory', 'td3', "td3 VARCHAR(100) DEFAULT NULL");
ensureCol($conn, 'lot_inventory', 'td_based_on_soa', "td_based_on_soa VARCHAR(100) DEFAULT NULL");
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

// Format ng RA/CTS code na ginagamit sa system: SUB + PH (3-digit, zero
// padded) + BLK (3-digit zero-padded KUNG puro numero, kung may letra
// gaya ng "0RL" ay as-is na lang) + LOT (4-digit zero-padded).
// Halimbawa: sub=CPB, ph=1, blk=0RL, lot=1  ->  CPB0010RL0001
//            sub=MRT, ph=1, blk=1,   lot=6  ->  MRT0010010006
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

// ============================================
// SPEED FIX: dating gumagawa ng 1-2 SELECT query bawat CSV row (isa
// pang may REGEXP), kaya sa 10,000+ rows ay 10,000-20,000+ hiwalay na
// query papunta sa MySQL — ito ang dahilan kung bakit matagal.
// Ngayon, isang beses lang kukuha ng LAHAT ng existing lot_inventory
// rows papunta sa memory (2 PHP array bilang index), at doon na lang
// titingin ang bawat CSV row — walang query na kailangan bawat row.
// ============================================
$RA_INDEX = [];   // ra_number => id
$KEY_INDEX = [];  // "SUB|PH|BLKNORM|LOTNORM" => id

$preload = $conn->query("SELECT id, ra_number, sub, ph, blk, lot FROM lot_inventory");
while ($r = $preload->fetch_assoc()) {
    if ($r['ra_number'] !== '' && $r['ra_number'] !== null) {
        $RA_INDEX[$r['ra_number']] = (int)$r['id'];
    }
    if ($r['sub'] !== null && $r['blk'] !== null && $r['lot'] !== null && $r['sub'] !== '' && $r['blk'] !== '' && $r['lot'] !== '') {
        $k = _normPlain($r['sub']) . '|' . _normPlain($r['ph']) . '|' . _normPart($r['blk']) . '|' . _normPart($r['lot']);
        $KEY_INDEX[$k] = (int)$r['id'];
    }
}

// Hinahanap ang lot_inventory.id gamit RA# muna, fallback sa Sub/Ph/Blk/Lot
// — mula na lang sa in-memory index (walang query).
function findLotId($conn, $raNumber, $sub, $ph, $blk, $lot) {
    global $RA_INDEX, $KEY_INDEX;
    if ($raNumber !== '' && isset($RA_INDEX[$raNumber])) {
        return $RA_INDEX[$raNumber];
    }
    if ($sub !== '' && $blk !== '' && $lot !== '') {
        $k = _normPlain($sub) . '|' . _normPlain($ph) . '|' . _normPart($blk) . '|' . _normPart($lot);
        if (isset($KEY_INDEX[$k])) return $KEY_INDEX[$k];
    }
    return 0;
}

// Tinatanggihan ang mga row na malinaw namang hindi tunay na lot data —
// halimbawa kapag na-upload ang isang exported REPORT (may title/label
// rows sa itaas bago ang tunay na datos) sa halip na ang raw lot_details.csv.
// Kung nag-match ang alinman sa mga row values dito sa mga kilalang
// header/title na text, huwag nang gawan ng bagong lot_inventory row.
function _looksLikeGarbageRow($raNumber, $sub, $ph, $blk, $lot) {
    $vals = array_map('trim', [$raNumber, $sub, $ph, $blk, $lot]);
    // Report title / subtitle lines (e.g. "Subdivision Monitor Report - Generated: ...",
    // "Municipal: CALAMBA - Subdivision: CRC")
    foreach ($vals as $v) {
        if ($v === '') continue;
        if (stripos($v, 'Subdivision Monitor Report') !== false) return true;
        if (stripos($v, 'Generated:') !== false) return true;
        if (preg_match('/^Municipal\s*:/i', $v)) return true;
    }
    // Literal column-header labels na na-reimport bilang datos
    // (nangyayari kapag mismatched ang column layout ng na-upload na file)
    $headerLabels = ['class','sub','subd','subdivision','ph','phase','blk','block','lot',
        'ra number','ra#','buyer\'s name','buyers name','tct no.','tct no','assessed value',
        'status','particulars','lot owner','td no.','td no'];
    $normVals = array_map(fn($v) => mb_strtolower(trim($v)), $vals);
    $matches = 0;
    foreach ($normVals as $v) if ($v !== '' && in_array($v, $headerLabels, true)) $matches++;
    // Kung 2 o higit pa sa 5 fields ay eksaktong tugma sa alam na header labels,
    // malamang header row ito, hindi datos.
    if ($matches >= 2) return true;
    return false;
}

// (Lumang query-per-row na findLotId() inalis — ginagamit na ang mabilis
// na in-memory version sa itaas.)

// Gumagawa ng BAGONG row sa lot_inventory kung walang match na nahanap.
// Kung walang tunay na RA#/CTS/code sa source (karaniwan ito sa Road Lots,
// open space, o iba pang "non-saleable" na common areas na likas namang
// walang individual RA/CTS number), gumagawa tayo ng MALINIS at
// DETERMINISTIC na placeholder base sa Sub-Ph-Blk-Lot (hal. "CPB-1-0RL-1")
// sa halip na yung lumang random/time-based na "AUTO-...-<timestamp>".
// Mahalaga ang determinism: kapag pareho ang Sub/Ph/Blk/Lot sa susunod na
// pag-import, MAG-MATCH ito sa parehong row (via RA_INDEX) sa halip na
// gumawa ng panibagong duplicate row bawat re-import.
function createLotIfMissing($conn, $raNumber, $sub, $ph, $blk, $lot, $code = '') {
    global $RA_INDEX, $KEY_INDEX;
    static $stmt = null, $f = null;
    $raNumber = trim($raNumber); $sub = trim($sub); $ph = trim($ph); $blk = trim($blk); $lot = trim($lot); $code = trim($code);

    // Walang sapat na info para gumawa ng makabuluhang row — huwag nang idagdag.
    if ($raNumber === '' && $sub === '' && $blk === '' && $lot === '') return 0;

    $finalRA = $raNumber !== '' ? $raNumber : $code;

    if ($finalRA === '') {
        $base = buildLotCode($sub, $ph, $blk, $lot);
        if ($base === '') return 0; // wala talagang laman, huwag nang gawan
        $finalRA = $base;
        // Kung may nagkataong existing RA na (o kaunti pang possibility ng
        // clash), i-check muna sa RA_INDEX at lagyan ng suffix kung kailangan.
        $suffix = 2;
        while (isset($RA_INDEX[$finalRA])) {
            $finalRA = $base . '-' . $suffix;
            $suffix++;
        }
    }

    if ($stmt === null) {
        $stmt = $conn->prepare("
            INSERT INTO lot_inventory (ra_number, sub, ph, blk, lot)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE id = id
        ");
        $f = $conn->prepare("SELECT id FROM lot_inventory WHERE ra_number = ? LIMIT 1");
    }
    $stmt->bind_param("sssss", $finalRA, $sub, $ph, $blk, $lot);
    $stmt->execute();
    $newId = $stmt->insert_id;

    // Kung na-trigger ang ON DUPLICATE KEY (nagkataon lang magkasabay na
    // insert ng parehong RA#), i-fetch ulit ang tunay na id.
    if (!$newId) {
        $f->bind_param("s", $finalRA);
        $f->execute();
        $row = $f->get_result()->fetch_assoc();
        if ($row) $newId = (int)$row['id'];
    }
    $newId = (int)$newId;

    // I-update din ang in-memory indexes para ma-hit agad ng susunod na
    // CSV rows sa parehong import kung sakaling ma-reference ulit itong
    // bagong lot (hal. sa or_history.csv pagkatapos ng lot_details.csv).
    if ($newId) {
        if ($finalRA !== '') $RA_INDEX[$finalRA] = $newId;
        if ($sub !== '' && $blk !== '' && $lot !== '') {
            $k = _normPlain($sub) . '|' . _normPlain($ph) . '|' . _normPart($blk) . '|' . _normPart($lot);
            $KEY_INDEX[$k] = $newId;
        }
    }
    return $newId;
}

$mode = $_POST['mode'] ?? '';
$updated = 0; $notFound = 0; $skipped = 0; $created = 0; $notFoundList = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv']) && $mode === 'lot_details') {
    set_time_limit(0);
    $sourceFile = trim($_POST['source_file'] ?? '');
    $handle = fopen($_FILES['csv']['tmp_name'], 'r');
    $header = fgetcsv($handle);
    // ra_number,sub,ph,blk,lot,area,house_area,code,cts_no,buyer,location,lot_owner,title,status,date_fullpayment,remarks,pin,td1,td2,td3,td_based_on_soa,assessed_value,last_or,soa_batch,soa_year
    //
    // Note: bawat field ay OVERWRITE kapag may laman ang bagong value sa CSV
    // (para gumana ang "export -> edit sa Excel -> re-upload -> update"
    // na workflow nang tama). Kung blangko ang bagong value sa CSV, hindi
    // babaguhin ang existing na laman sa DB (para hindi mawala ang datos
    // kung partial lang ang binago mong Excel).
    $stmt = $conn->prepare("
        UPDATE lot_inventory
        SET lot_area         = IF(?='', lot_area, ?),
            house_area        = IF(?='', house_area, ?),
            code              = IF(?='', code, ?),
            cts_no            = IF(?='', cts_no, ?),
            buyers_name       = IF(?='', buyers_name, ?),
            location          = IF(?='', location, ?),
            lot_owner         = IF(?='', lot_owner, ?),
            tct_no            = IF(?='', tct_no, ?),
            status            = IF(?='', status, ?),
            date_fullpayment  = IF(?='', date_fullpayment, ?),
            remarks           = IF(?='', remarks, ?),
            pin_no            = IF(?='', pin_no, ?),
            td1               = IF(?='', td1, ?),
            td2               = IF(?='', td2, ?),
            td3               = IF(?='', td3, ?),
            td_based_on_soa   = IF(?='', td_based_on_soa, ?),
            assessed_value    = IF(?='', assessed_value, ?),
            last_or           = IF(?='', last_or, ?),
            soa_batch         = IF(?='', soa_batch, ?),
            soa_year          = IF(?='', soa_year, ?),
            source_file       = IF(?='', source_file, ?)
        WHERE id = ?
    ");

    // SPEED FIX: nang walang transaction, nag-a-autocommit ang MySQL
    // (nagsu-sync sa disk) sa BAWAT row — ito rin ang malaking dahilan ng
    // pagkabagal. Ngayon, isang transaction para sa buong batch (may
    // periodic commit bawat 2000 rows para hindi sumobra kalaki ang
    // undo log kung malaking file).
    $conn->autocommit(false);
    $rowsInTxn = 0;

    while (($row = fgetcsv($handle)) !== false) {
        [$raNumber,$sub,$ph,$blk,$lot,$area,$houseArea,$code,$cts,$buyer,$location,$lotOwner,
         $title,$status,$dateFullpay,$remarks,$pin,$td1,$td2,$td3,$tdSoa,$av,$lastOr,$soaBatch,$soaYear] =
            array_pad($row, 25, '');

        $lotId = findLotId($conn, trim($raNumber), trim($sub), trim($ph), trim($blk), trim($lot));
        if (!$lotId) {
            if (_looksLikeGarbageRow($raNumber, $sub, $ph, $blk, $lot)) {
                $skipped++; continue;
            }
            $lotId = createLotIfMissing($conn, $raNumber, $sub, $ph, $blk, $lot, $code);
            if (!$lotId) { $notFound++; if (count($notFoundList)<200) $notFoundList[]=$raNumber?:"$sub/$ph/$blk/$lot"; continue; }
            $created++;
        }

        $avStr = trim($av) === '' ? '' : number_format((float)$av, 2, '.', '');
        $stmt->bind_param(
            "ssssssssssssssssssssssssssssssssssssssssssi",
            $area,$area, $houseArea,$houseArea, $code,$code, $cts,$cts, $buyer,$buyer,
            $location,$location, $lotOwner,$lotOwner, $title,$title, $status,$status,
            $dateFullpay,$dateFullpay, $remarks,$remarks, $pin,$pin,
            $td1,$td1, $td2,$td2, $td3,$td3, $tdSoa,$tdSoa,
            $avStr,$avStr, $lastOr,$lastOr, $soaBatch,$soaBatch, $soaYear,$soaYear,
            $sourceFile,$sourceFile,
            $lotId
        );
        $stmt->execute();
        $updated++;

        $rowsInTxn++;
        if ($rowsInTxn >= 2000) { $conn->commit(); $rowsInTxn = 0; }
    }
    $conn->commit();
    $conn->autocommit(true);
    fclose($handle);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv']) && $mode === 'or_history') {
    set_time_limit(0);
    $handle = fopen($_FILES['csv']['tmp_name'], 'r');
    $header = fgetcsv($handle);
    // ra_number,sub,ph,blk,lot,year,as_no,jv_no,mc_liaison,or_number,fr,to,amount,date,remarks
    $stmt = $conn->prepare("
        INSERT INTO lot_or_history (lot_inventory_id, yr, as_no, jv_no, mc_liaison, or_number, fr, `to`, amount, or_date, remarks)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE as_no=VALUES(as_no), jv_no=VALUES(jv_no), mc_liaison=VALUES(mc_liaison),
            fr=VALUES(fr), `to`=VALUES(`to`), amount=VALUES(amount), or_date=VALUES(or_date), remarks=VALUES(remarks)
    ");

    $conn->autocommit(false);
    $rowsInTxn = 0;

    while (($row = fgetcsv($handle)) !== false) {
        [$raNumber,$sub,$ph,$blk,$lot,$year,$asNo,$jvNo,$mcLiaison,$orNumber,$fr,$to,$amount,$date,$remarks] =
            array_pad($row, 15, '');

        $lotId = findLotId($conn, trim($raNumber), trim($sub), trim($ph), trim($blk), trim($lot));
        if (!$lotId) {
            if (_looksLikeGarbageRow($raNumber, $sub, $ph, $blk, $lot)) {
                $skipped++; continue;
            }
            $lotId = createLotIfMissing($conn, $raNumber, $sub, $ph, $blk, $lot);
            if (!$lotId) { $notFound++; if (count($notFoundList)<200) $notFoundList[]=$raNumber?:"$sub/$ph/$blk/$lot"; continue; }
            $created++;
        }

        $yr = (int)$year;
        $amt = trim((string)$amount) === '' ? null : (float)$amount;
        $orNumberSafe = trim($orNumber) !== '' ? trim($orNumber) : ('-' . $yr . '-' . $lotId); // para hindi ma-skip ng UNIQUE KEY kung walang OR#
        $stmt->bind_param("iissssssdss", $lotId, $yr, $asNo, $jvNo, $mcLiaison, $orNumberSafe, $fr, $to, $amt, $date, $remarks);
        $stmt->execute();
        $updated++;

        $rowsInTxn++;
        if ($rowsInTxn >= 2000) { $conn->commit(); $rowsInTxn = 0; }
    }
    $conn->commit();
    $conn->autocommit(true);
    fclose($handle);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode([
        "success"      => true,
        "mode"         => $mode,
        "updated"      => $updated,
        "created"      => $created,
        "notFound"     => $notFound,
        "notFoundList" => $notFoundList,
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Full Import (Lot Inventory)</title>
<style>body{font-family:sans-serif;background:#111;color:#eee;padding:24px}
input,button{padding:8px;margin:6px 0}
.ok{color:#4ade80}.warn{color:#f87171}
pre{background:#1a1a1a;padding:12px;border-radius:8px;max-height:300px;overflow:auto}
form{border:1px solid #333;border-radius:8px;padding:16px;margin-bottom:20px}
</style></head>
<body>
<h2>Full Import — Lot Details + Per-Year OR History (Lot Inventory)</h2>

<?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
  <p class="ok">✅ Na-update: <?= $updated ?> row(s), Bagong lot na naidagdag: <?= $created ?> (mode: <?= htmlspecialchars($mode) ?>)</p>
  <p class="warn">⚠️ Walang sapat na info para i-match/gawa: <?= $notFound ?> row(s)</p>
  <?php if ($notFoundList): ?>
    <p>Sample ng hindi nahanap:</p>
    <pre><?= htmlspecialchars(implode("\n", $notFoundList)) ?></pre>
  <?php endif; ?>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
  <h3>1. Lot Details (lot_details.csv)</h3>
  <p>Area, House Area, Code, CTS No., Buyer, Location, Lot Owner, TCT, Status, Date of Fullpayment, Remarks, PIN, TD#, Assessed Value, Last OR#, SOA Batch, SOA Year — dinadagdag lang kung wala pang laman ang field (status at assessed value ay ino-overwrite kung may bagong value).</p>
  <input type="hidden" name="mode" value="lot_details">
  <label>Source file / batch label (optional, para malaman saang Excel galing): <input type="text" name="source_file" placeholder="hal. BAGUIO - Lourdes / PWG PH1"></label><br>
  <input type="file" name="csv" accept=".csv" required><br>
  <button type="submit">Import Lot Details</button>
</form>

<form method="post" enctype="multipart/form-data">
  <h3>2. Per-Year OR History (or_history.csv)</h3>
  <p>I-upload ITO PAGKATAPOS ng Lot Details sa itaas. Isang row bawat taon bawat lot (AS#, JV#, MC#/Liaison, OR Number, FR, TO, Amount, Date, Remarks) papunta sa bagong <code>lot_or_history</code> table.</p>
  <input type="hidden" name="mode" value="or_history">
  <input type="file" name="csv" accept=".csv" required><br>
  <button type="submit">Import OR History</button>
</form>

</body>
</html>