<?php
// ============================================
// router.php — RPT System Backend (XAMPP / localhost)
// Split from the original monolithic api.php.
// This file wires up all modules and then runs the same
// GET/POST router that used to live at the top of api.php.
// ============================================

require_once __DIR__ . '/bootstrap.php';

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/birthdays.php';
require_once __DIR__ . '/chat.php';
require_once __DIR__ . '/taxrates.php';
require_once __DIR__ . '/liaison.php';
require_once __DIR__ . '/subdivmon.php';
require_once __DIR__ . '/slrdi.php';
require_once __DIR__ . '/slli.php';
require_once __DIR__ . '/released.php';
require_once __DIR__ . '/orissuance.php';
require_once __DIR__ . '/asmc.php';


// ============================================
// ROUTER
// ============================================
$method = $_SERVER['REQUEST_METHOD'];
$type   = $_GET['type'] ?? 'records';

if ($method === 'GET') {
    // Server-side gate: no session, no data. Prevents hitting api.php?type=records
    // (or any other GET view) directly in a browser/curl without logging in first.
    if (currentSessionUsername() === null) {
        sendJSON(["error" => "Session expired or not logged in. Please log in again."]);
    }
    if ($type === 'chat') {
        getChats();
    } elseif ($type === 'taxrates') {
        getTaxRates();
    } else {
        getRecords();
    }
} elseif ($method === 'POST') {
    $body = json_decode(file_get_contents("php://input"), true);
    if (!$body) {
        sendJSON(["error" => "Invalid JSON body"]);
    }

    $action = $body['action'] ?? 'save';

    // Server-side source of truth: block mutating actions for viewer sessions
    // regardless of what the client claims about its own role.
    enforceViewerRestriction($action);

    switch ($action) {
        case 'login':          loginUser($body);        break;
        case 'logout':         clearSessionUser(); sendJSON(["success" => true]); break;
        case 'chat':           saveChat($body);         break;
        case 'delete':         deleteRecord($body);     break;
        case 'checkLotRecord': checkLotRecord($body);   break;
        case 'getBirthdays':    getBirthdays();          break;
        case 'saveBirthday':    saveBirthday($body);      break;
        case 'deleteBirthday':  deleteBirthday($body);    break;
        case 'getUsers':       getUsers();              break;
        case 'heartbeat':      heartbeat($body);        break;
        case 'getOnlineUsers': getOnlineUsers();        break;
        case 'addUser':        addUser($body);          break;
        case 'deleteUser':     deleteUser($body);       break;
        case 'changePassword': changePassword($body);   break;
        case 'saveProfile':    saveProfile($body);      break;
        case 'getProfile':     getProfile($body);       break;
        case 'saveTaxRate':    saveTaxRate($body);      break;
        case 'deleteTaxRate':  deleteTaxRate($body);    break;
        case 'importTaxRates': importTaxRates($body);   break;
        case 'getLiaisonRecords':        getLiaisonRecords();               break;
        case 'saveLiaisonRecord':        saveLiaisonRecord($body);          break;
        case 'deleteLiaisonRecord':      deleteLiaisonRecord($body);        break;
        case 'getLiaisonActivityLog':    getLiaisonActivityLog($body);      break;
        case 'getRecordActivityLog':     getRecordActivityLog($body);       break;
        case 'bulkImportLiaisonRecords': bulkImportLiaisonRecords($body);   break;
        case 'getLotInventoryByRA': getLotInventoryByRA($body);   break;
        case 'getLiaisonRecordsByRA': getLiaisonRecordsByRA($body); break;
        case 'getLiaisonAttachments': getLiaisonAttachments($body); break;
        case 'getAllLiaisonAttachments': getAllLiaisonAttachments(); break;
        case 'addLiaisonAttachment': addLiaisonAttachment($body); break;
        case 'deleteLiaisonAttachment': deleteLiaisonAttachment($body); break;
        case 'searchLotInventory':  searchLotInventory($body);    break;
        case 'updateLotInventory':  updateLotInventory($body);    break;
        case 'deleteLotInventory':  deleteLotInventory($body);    break;
        case 'getSlrdiRecords':       getSlrdiRecords();            break;
        case 'saveSlrdiRecord':       saveSlrdiRecord($body);       break;
        case 'deleteSlrdiRecord':     deleteSlrdiRecord($body);     break;
        case 'getSlrdiActivityLog':   getSlrdiActivityLog($body);   break;
        case 'getSlliRecords':        getSlliRecords();             break;
        case 'saveSlliRecord':        saveSlliRecord($body);        break;
        case 'deleteSlliRecord':      deleteSlliRecord($body);      break;
        case 'getSlliActivityLog':    getSlliActivityLog($body);    break;
        case 'lookupLotInventoryByLot': lookupLotInventoryByLot($body); break;
        case 'getLotInventorySubdivisions': getLotInventorySubdivisions(); break;
        case 'getSubdivisionMonitorTree':   getSubdivisionMonitorTree($body);   break;
        case 'getSubdivisionMonitorMunicipals': getSubdivisionMonitorMunicipals(); break;
        case 'getSubdivisionMonitorSubdivisions': getSubdivisionMonitorSubdivisions($body); break;
        case 'getSubdivisionMonitorStatuses': getSubdivisionMonitorStatuses($body); break;
        case 'importAsmcDataset': importAsmcDataset($body); break;
        case 'listAsmcDataset': listAsmcDataset($body); break;
        case 'lookupAsmcByAS': lookupAsmcByAS($body); break;
        case 'addAsmcRow': addAsmcRow($body); break;
        case 'updateAsmcRow': updateAsmcRow($body); break;
        case 'deleteAsmcRow': deleteAsmcRow($body); break;
        case 'getSubdivisionMonitorBlocks': getSubdivisionMonitorBlocks($body); break;
        case 'getSubdivisionMonitorLots':   getSubdivisionMonitorLots($body);   break;
        case 'searchSubdivisionMonitorLots': searchSubdivisionMonitorLots($body); break;
        case 'getSubdivisionMonitorLotDetail': getSubdivisionMonitorLotDetail($body); break;
        case 'importSubdivisionMonitorUpdate': importSubdivisionMonitorUpdate($body); break;
        case 'updateLotOrHistory':  updateLotOrHistory($body);  break;
        case 'deleteLotOrHistory':  deleteLotOrHistory($body);  break;
        case 'relinkAllLiaisonRecords':     relinkAllLiaisonRecords($body); break;
        case 'searchReleasedTitles':   searchReleasedTitles($body);   break;
        case 'saveReleasedRecord':    saveReleasedRecord($body);     break;
        case 'deleteReleasedRecord':  deleteReleasedRecord($body);   break;
        case 'getReleasedActivityLog': getReleasedActivityLog($body); break;
        case 'getOrIssuanceRecords':   getOrIssuanceRecords();        break;
        case 'saveOrIssuanceRecord':   saveOrIssuanceRecord($body);   break;
        case 'deleteOrIssuanceRecord': deleteOrIssuanceRecord($body); break;
        case 'getOrIssuanceActivityLog': getOrIssuanceActivityLog($body); break;
        default:               saveRecord($body);       break;
    }
}