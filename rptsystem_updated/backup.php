<?php
// ============================================
// backup.php — Automatic/Manual DB Backup (XAMPP / localhost)
// ============================================
// Creates a SQL dump (structure + data) of all tables in the rpt_system
// database. Each backup is now saved as a FOLDER containing one .sql file
// per table (big tables are further split into part1/part2/... chunks so
// no single file gets too large — easier to upload manually if needed,
// and faster/safer to write & restore).
//
// Can be triggered:
//   - Manually: button in the app (Backup Now)
//   - Automatically: auto-check whenever the app is opened (1x per day)
//   - Truly scheduled: Windows Task Scheduler / cron:
//       php.exe C:\xampp\htdocs\rptsystem\backup.php action=run
// ============================================

@ini_set('display_errors', 0);
error_reporting(0);

// ============================================
// ACCESS CONTROL
// This file handles backup download/restore/delete of the WHOLE database,
// so it must never be reachable by an anonymous browser request. The only
// exception is a true CLI cron/Task Scheduler run (php.exe backup.php),
// which has no browser session to check in the first place.
// ============================================
if (php_sapi_name() !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (($_SESSION['rpt_role'] ?? null) !== 'admin') {
        http_response_code(403);
        header("Content-Type: application/json");
        echo json_encode(["error" => "Access denied. Admin login required."]);
        exit();
    }
}

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'rpt_system');

define('BACKUP_DIR', __DIR__ . '/backups');
define('KEEP_LAST_N', 30);          // ilang backup FOLDERS ang itatago (pinaka-lumang tatanggalin na)
define('AUTO_BACKUP_HOURS', 24);    // gaano kadalas mag-auto-backup kung binuksan ang app
define('MAX_PART_BYTES', 15 * 1024 * 1024); // 15MB max bawat split file ng malaking table

// Tables na kasama sa backup.
// NOTE: AUTO-DETECT lahat ng tables sa database (SHOW TABLES), para kahit
// magdagdag ka pa ng bagong table/module sa hinaharap, kasama na agad ito
// sa susunod na backup nang walang kailangan i-edit ang file na ito.
function getAllTables($db) {
    $tables = [];
    $res = $db->query("SHOW TABLES");
    if ($res) { while ($row = $res->fetch_row()) $tables[] = $row[0]; }
    return $tables;
}

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

if (!is_dir(BACKUP_DIR)) @mkdir(BACKUP_DIR, 0755, true);
// protektahan ang backups folder mula sa direktang pag-browse
$htaccess = BACKUP_DIR . '/.htaccess';
if (!file_exists($htaccess)) @file_put_contents($htaccess, "Deny from all\n");

function sendJSON($data) { echo json_encode($data); exit(); }

function getDB() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) sendJSON(["error" => "DB Connection failed: " . $conn->connect_error]);
    $conn->set_charset("utf8mb4");
    return $conn;
}

// ---------- Helpers para sa FOLDER-based na backup ----------

// Kunin lahat ng backup folders (bago -> luma)
function getBackupFolders() {
    $dirs = glob(BACKUP_DIR . '/backup_*', GLOB_ONLYDIR);
    usort($dirs, fn($a, $b) => filemtime($b) - filemtime($a));
    return $dirs;
}

function folderTotalSize($dir) {
    $size = 0;
    foreach (glob($dir . '/*') as $f) if (is_file($f)) $size += filesize($f);
    return $size;
}

function listBackups() {
    $dirs = getBackupFolders();
    $out = [];
    foreach ($dirs as $d) {
        $meta = null;
        $metaPath = $d . '/00_meta.json';
        if (file_exists($metaPath)) $meta = json_decode(file_get_contents($metaPath), true);
        $out[] = [
            "file" => basename($d),                 // pangalan ng backup (folder)
            "size" => folderTotalSize($d),
            "date" => $meta['date'] ?? date("Y-m-d H:i:s", filemtime($d)),
            "tables" => $meta['tables'] ?? null,
            "parts" => $meta['files'] ?? null,
        ];
    }
    usort($out, fn($a, $b) => strcmp($b['date'], $a['date']));
    return $out;
}

function deleteFolderRecursive($dir) {
    if (!is_dir($dir)) return;
    foreach (glob($dir . '/*') as $f) {
        is_dir($f) ? deleteFolderRecursive($f) : @unlink($f);
    }
    @rmdir($dir);
}

function cleanupOldBackups() {
    $dirs = getBackupFolders();
    $excess = array_slice($dirs, KEEP_LAST_N);
    foreach ($excess as $d) deleteFolderRecursive($d);
}

// I-dump ang isang table papunta sa isa o higit pang files sa $destDir.
// Ibinabalik ang listahan ng filenames (relative) na ginawa, sunod-sunod.
function dumpTableToFiles($db, $table, $destDir) {
    $filesWritten = [];
    $header = "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n";

    $res = $db->query("SHOW CREATE TABLE `$table`");
    if (!$res) return $filesWritten;
    $row = $res->fetch_assoc();
    $schemaSql = "-- ----------------------------\n-- Table: $table\n-- ----------------------------\n";
    $schemaSql .= "DROP TABLE IF EXISTS `$table`;\n";
    $schemaSql .= $row['Create Table'] . ";\n\n";

    $data = $db->query("SELECT * FROM `$table`");
    $cols = [];
    if ($data) while ($f = $data->fetch_field()) $cols[] = "`{$f->name}`";
    $colList = implode(",", $cols);

    $partNum = 1;
    $currentBuffer = $header . $schemaSql; // schema laging sa part1
    $currentBatch = [];

    $flushBatch = function() use (&$currentBatch, &$currentBuffer, $table, $colList) {
        if ($currentBatch) {
            $currentBuffer .= "INSERT INTO `$table` ($colList) VALUES\n" . implode(",\n", $currentBatch) . ";\n";
            $currentBatch = [];
        }
    };

    $writePart = function() use (&$partNum, &$currentBuffer, $table, $destDir, &$filesWritten, $header) {
        $fname = ($partNum === 1) ? "table_{$table}.sql" : "table_{$table}_part{$partNum}.sql";
        // kapag sinabi nang may susunod na part, palitan ang filename ng part1 papuntang may "_part1"
        file_put_contents($destDir . '/' . $fname, $currentBuffer);
        $filesWritten[] = $fname;
        $partNum++;
        $currentBuffer = $header; // susunod na parts: header lang, walang ulit na schema
    };

    if ($data && $data->num_rows > 0) {
        $batch = [];
        $batchRows = 0;
        while ($rowData = $data->fetch_row()) {
            $vals = array_map(function($v) use ($db) {
                if ($v === null) return "NULL";
                return "'" . $db->real_escape_string($v) . "'";
            }, $rowData);
            $currentBatch[] = "(" . implode(",", $vals) . ")";
            $batchRows++;
            if ($batchRows >= 200) {
                $flushBatch();
                $batchRows = 0;
            }
            if (strlen($currentBuffer) >= MAX_PART_BYTES) {
                $flushBatch();
                $writePart();
            }
        }
        $flushBatch();
    }
    // isulat ang natitirang buffer (kahit walang laman na table, para sumulat ng schema)
    if (trim($currentBuffer) !== trim($header)) {
        $writePart();
    } elseif ($partNum === 1) {
        // walang laman ang table pero kailangan pa rin isulat ang schema
        $currentBuffer = $header . $schemaSql;
        $writePart();
    }

    // Kung may naiwang filename na hindi naka-rename dahil part1 lang pala pero
    // nagtapos sa "_part1" naming — ayusin: kapag iisa lang ang part, tanggalin ang "_partN"
    if (count($filesWritten) === 1 && strpos($filesWritten[0], '_part') !== false) {
        $old = $destDir . '/' . $filesWritten[0];
        $new = $destDir . "/table_{$table}.sql";
        @rename($old, $new);
        $filesWritten[0] = "table_{$table}.sql";
    }

    return $filesWritten;
}

function runBackup($tables = null) {
    $db = getDB();
    $tables = $tables ?? getAllTables($db);

    $folderName = "backup_" . date("Y-m-d_His");
    $destDir = BACKUP_DIR . '/' . $folderName;
    @mkdir($destDir, 0755, true);

    $allFiles = [];
    foreach ($tables as $t) {
        $check = $db->query("SHOW TABLES LIKE '$t'");
        if ($check && $check->num_rows > 0) {
            $files = dumpTableToFiles($db, $t, $destDir);
            $allFiles = array_merge($allFiles, $files);
        }
    }
    $db->close();

    $meta = [
        "date" => date("Y-m-d H:i:s"),
        "tables" => $tables,
        "files" => $allFiles, // sunod-sunod na pagkakasunod para sa restore
    ];
    file_put_contents($destDir . '/00_meta.json', json_encode($meta, JSON_PRETTY_PRINT));

    cleanupOldBackups();

    return [
        "file" => $folderName,
        "size" => folderTotalSize($destDir),
        "date" => $meta['date'],
        "parts" => count($allFiles),
    ];
}

function restoreBackup($folderName) {
    $folder = basename($folderName);
    $dir = BACKUP_DIR . '/' . $folder;
    if (!$folder || !is_dir($dir) || !preg_match('/^backup_[\w\-]+$/', $folder)) {
        return ["error" => "Backup folder not found."];
    }

    $metaPath = $dir . '/00_meta.json';
    if (!file_exists($metaPath)) return ["error" => "Missing backup metadata (00_meta.json)."];
    $meta = json_decode(file_get_contents($metaPath), true);
    $files = $meta['files'] ?? [];
    if (!$files) return ["error" => "Walang laman ang backup na ito."];

    $db = getDB();
    $db->autocommit(false);

    try {
        foreach ($files as $fname) {
            $path = $dir . '/' . $fname;
            if (!file_exists($path)) continue;
            $sql = file_get_contents($path);
            if ($sql === false) throw new Exception("Hindi mabasa ang $fname");

            if (!$db->multi_query($sql)) {
                throw new Exception("$fname: " . $db->error);
            }
            do {
                if ($result = $db->store_result()) $result->free();
            } while ($db->more_results() && $db->next_result());

            if ($db->errno) throw new Exception("$fname: " . $db->error);
        }

        $db->commit();
        $db->autocommit(true);
        $db->close();
        return ["success" => true, "restoredFrom" => $folder, "date" => date("Y-m-d H:i:s")];
    } catch (Exception $e) {
        $db->rollback();
        $db->autocommit(true);
        $db->close();
        return ["error" => "Restore failed: " . $e->getMessage()];
    }
}

// I-zip ang isang backup folder papunta sa isang pansamantalang .zip para sa download
function zipBackupFolder($dir, $zipPath) {
    if (!class_exists('ZipArchive')) return false;
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;
    foreach (glob($dir . '/*') as $f) {
        if (is_file($f)) $zip->addFile($f, basename($f));
    }
    $zip->close();
    return true;
}

$action = $_GET['action'] ?? ($_POST['action'] ?? 'status');

switch ($action) {
    case 'run':
        $result = runBackup(); // auto-detect lahat ng tables
        sendJSON(["success" => true, "backup" => $result]);
        break;

    case 'status':
        $backups = listBackups();
        $last = $backups[0] ?? null;
        $needsAuto = true;
        if ($last) {
            $lastTime = strtotime($last['date']);
            $needsAuto = (time() - $lastTime) >= (AUTO_BACKUP_HOURS * 3600);
        }
        sendJSON(["lastBackup" => $last, "needsAuto" => $needsAuto, "totalBackups" => count($backups)]);
        break;

    case 'list':
        sendJSON(["backups" => listBackups()]);
        break;

    case 'download':
        $file = basename($_GET['file'] ?? '');
        $dir = BACKUP_DIR . '/' . $file;
        if (!$file || !is_dir($dir) || !preg_match('/^backup_[\w\-]+$/', $file)) {
            http_response_code(404);
            sendJSON(["error" => "Backup not found."]);
        }
        $zipPath = sys_get_temp_dir() . '/' . $file . '.zip';
        if (!zipBackupFolder($dir, $zipPath)) {
            http_response_code(500);
            sendJSON(["error" => "Hindi ma-zip ang backup (ZipArchive extension needed)."]);
        }
        header("Content-Type: application/zip");
        header("Content-Disposition: attachment; filename=\"$file.zip\"");
        header("Content-Length: " . filesize($zipPath));
        readfile($zipPath);
        @unlink($zipPath);
        exit();

    case 'delete':
        $file = basename($_POST['file'] ?? ($_GET['file'] ?? ''));
        $dir = BACKUP_DIR . '/' . $file;
        if ($file && is_dir($dir) && preg_match('/^backup_[\w\-]+$/', $file)) {
            deleteFolderRecursive($dir);
            sendJSON(["success" => true]);
        }
        sendJSON(["error" => "Backup not found."]);
        break;

    case 'restore':
        $file = basename($_POST['file'] ?? ($_GET['file'] ?? ''));
        if (!$file) sendJSON(["error" => "Walang binigay na backup."]);

        // safety: gumawa muna ng snapshot ng kasalukuyang data bago mag-restore
        runBackup();

        $result = restoreBackup($file);
        if (isset($result['error'])) {
            http_response_code(400);
            sendJSON($result);
        }
        sendJSON($result);
        break;

    case 'restore_upload':
        // para sa pag-upload ng ibang .sql file (o .zip ng backup folder) na wala sa backups/
        if (!isset($_FILES['sqlfile']) || $_FILES['sqlfile']['error'] !== UPLOAD_ERR_OK) {
            sendJSON(["error" => "Walang na-upload na file."]);
        }
        $origName = $_FILES['sqlfile']['name'];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        // safety snapshot muna
        runBackup();

        if ($ext === 'sql') {
            // isang .sql file lang — gawan ng sariling folder na may 1 file
            $folderName = "backup_" . date("Y-m-d_His") . "_uploaded";
            $destDir = BACKUP_DIR . '/' . $folderName;
            @mkdir($destDir, 0755, true);
            $destFile = $destDir . '/table_uploaded.sql';
            if (!move_uploaded_file($_FILES['sqlfile']['tmp_name'], $destFile)) {
                sendJSON(["error" => "Hindi na-save ang na-upload na file."]);
            }
            $meta = ["date" => date("Y-m-d H:i:s"), "tables" => null, "files" => ["table_uploaded.sql"]];
            file_put_contents($destDir . '/00_meta.json', json_encode($meta, JSON_PRETTY_PRINT));
            $result = restoreBackup($folderName);
        } elseif ($ext === 'zip') {
            // .zip ng isang buong backup folder (galing sa 'download' action na ito)
            $folderName = "backup_" . date("Y-m-d_His") . "_uploaded";
            $destDir = BACKUP_DIR . '/' . $folderName;
            @mkdir($destDir, 0755, true);
            $tmpZip = sys_get_temp_dir() . '/' . uniqid('up_') . '.zip';
            if (!move_uploaded_file($_FILES['sqlfile']['tmp_name'], $tmpZip)) {
                sendJSON(["error" => "Hindi na-save ang na-upload na zip."]);
            }
            $zip = new ZipArchive();
            if ($zip->open($tmpZip) !== true) sendJSON(["error" => "Hindi mabuksan ang zip file."]);
            $zip->extractTo($destDir);
            $zip->close();
            @unlink($tmpZip);

            if (!file_exists($destDir . '/00_meta.json')) {
                // walang meta — gawing default: lahat ng .sql sa loob, pagkasunod-sunod (alpabetikal)
                $files = glob($destDir . '/*.sql');
                sort($files);
                $files = array_map('basename', $files);
                $meta = ["date" => date("Y-m-d H:i:s"), "tables" => null, "files" => $files];
                file_put_contents($destDir . '/00_meta.json', json_encode($meta, JSON_PRETTY_PRINT));
            }
            $result = restoreBackup($folderName);
        } else {
            sendJSON(["error" => "Kailangan .sql o .zip file lang."]);
        }

        if (isset($result['error'])) {
            http_response_code(400);
            sendJSON($result);
        }
        sendJSON($result);
        break;

    default:
        sendJSON(["error" => "Unknown action."]);
}