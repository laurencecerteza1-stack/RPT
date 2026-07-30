<?php
function getTaxRates() {
    $db = getDB();
    ensureTaxRatesTable($db);
    $result = $db->query("SELECT lot_code, tax_rate, municipal FROM tax_rates ORDER BY lot_code ASC");
    $rates = [];
    while ($row = $result->fetch_assoc()) {
        $rates[$row['lot_code']] = [
            'taxRate'   => $row['tax_rate'],
            'municipal' => $row['municipal']
        ];
    }
    $db->close();
    sendJSON($rates);
}

// ============================================
// SAVE / UPDATE TAX RATE (upsert)
// ============================================
function saveTaxRate($body) {
    $db = getDB();
    ensureTaxRatesTable($db);
    $lotCode   = strtoupper(trim($body['lotCode'] ?? ''));
    $taxRate   = trim($body['taxRate'] ?? '2%');
    $municipal = trim($body['municipal'] ?? '');

    if (empty($lotCode)) sendJSON(["error" => "Lot code is required."]);

    $stmt = $db->prepare("INSERT INTO tax_rates (lot_code, tax_rate, municipal) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE tax_rate = VALUES(tax_rate), municipal = VALUES(municipal), updated_at = NOW()");
    $stmt->bind_param("sss", $lotCode, $taxRate, $municipal);

    if ($stmt->execute()) {
        sendJSON(["success" => true, "lotCode" => $lotCode, "taxRate" => $taxRate, "municipal" => $municipal]);
    } else {
        sendJSON(["error" => $stmt->error]);
    }
    $stmt->close();
    $db->close();
}

// ============================================
// DELETE TAX RATE
// ============================================
function deleteTaxRate($body) {
    $db = getDB();
    ensureTaxRatesTable($db);
    $lotCode = strtoupper(trim($body['lotCode'] ?? ''));
    if (empty($lotCode)) sendJSON(["error" => "Lot code is required."]);

    $stmt = $db->prepare("DELETE FROM tax_rates WHERE lot_code = ?");
    $stmt->bind_param("s", $lotCode);

    if ($stmt->execute()) {
        sendJSON(["success" => true, "deleted" => $lotCode]);
    } else {
        sendJSON(["error" => $stmt->error]);
    }
    $stmt->close();
    $db->close();
}

// ============================================
// BULK IMPORT TAX RATES
// ============================================
function importTaxRates($body) {
    $db = getDB();
    ensureTaxRatesTable($db);

    $rates = $body['rates'] ?? [];
    if (empty($rates) || !is_array($rates)) {
        sendJSON(["error" => "No rates provided."]);
    }

    $stmt = $db->prepare("INSERT INTO tax_rates (lot_code, tax_rate, municipal) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE tax_rate = VALUES(tax_rate), municipal = VALUES(municipal), updated_at = NOW()");
    $inserted = 0;
    $errors   = [];

    foreach ($rates as $lotCode => $entry) {
        $lotCode = strtoupper(trim($lotCode));
        if (empty($lotCode)) continue;

        // Support both legacy string format ("2%") and new object format ({taxRate, municipal})
        if (is_array($entry)) {
            $taxRate   = trim($entry['taxRate'] ?? '');
            $municipal = trim($entry['municipal'] ?? '');
        } else {
            $taxRate   = trim($entry);
            $municipal = '';
        }

        $stmt->bind_param("sss", $lotCode, $taxRate, $municipal);
        if ($stmt->execute()) {
            $inserted++;
        } else {
            $errors[] = $lotCode . ': ' . $stmt->error;
        }
    }

    $stmt->close();
    $db->close();

    sendJSON([
        "success"  => true,
        "imported" => $inserted,
        "errors"   => $errors
    ]);
}
