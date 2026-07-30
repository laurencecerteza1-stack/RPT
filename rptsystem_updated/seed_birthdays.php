<?php
// ============================================
// ONE-TIME SEED: populate rpt_birthdays for the
// celebrants who have actual rpt_users accounts.
// Open this file once in the browser, then delete it.
// ============================================
require_once __DIR__ . '/api/bootstrap.php';
require_once __DIR__ . '/api/birthdays.php';

$db = getDB();
ensureBirthdaysTable($db);
$db->close();

// username => [full_name, birth_date Y-m-d]
$seed = [
    "ROSELYN"   => ["Roselyn Bustos",             "1996-01-23"],
    "GIL"       => ["Gilbert Pugoy",              "1997-03-21"],
    "ANN"       => ["Mary Ann Gerolia",           "1994-04-27"],
    "LAURENCE"  => ["Laurence Anthony Certeza",   "2003-04-01"],
    "LENARD"    => ["Lenard Manaligod",           "1997-05-07"],
    "RHEA"      => ["Rhea Jane Ocay",             "1999-08-20"],
    "CJ"        => ["Christine Joy Maglangit",    "1998-08-10"],
    "EGI"       => ["Egison Hinlog",              "1996-09-30"],
    "LOURDES"   => ["Maria Lourdes Reyes",        "1998-11-18"],
];

$results = [];
foreach ($seed as $username => $info) {
    [$fullName, $birthDate] = $info;
    try {
        saveBirthdaySeeded($username, $fullName, $birthDate);
        $results[] = "✅ $username → $fullName ($birthDate)";
    } catch (Exception $e) {
        $results[] = "❌ $username → " . $e->getMessage();
    }
}

function saveBirthdaySeeded($username, $fullName, $birthDate) {
    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO rpt_birthdays (username, full_name, birth_date)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), birth_date = VALUES(birth_date)
    ");
    $stmt->bind_param("sss", $username, $fullName, $birthDate);
    $stmt->execute();
    $stmt->close();
    $db->close();
}

header("Content-Type: text/plain");
echo "Birthday seed results:\n\n";
echo implode("\n", $results);
echo "\n\nTapos na. I-delete mo na itong seed_birthdays.php pagkatapos i-run.";
