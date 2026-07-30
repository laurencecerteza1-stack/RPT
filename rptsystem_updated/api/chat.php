<?php
function getChats() {
    $db = getDB();
    ensureChatTable($db);
    // Get the latest 100 messages (DESC + LIMIT), then reverse to ASC for display.
    // Previous version used ORDER BY timestamp ASC LIMIT 100, which always
    // returned the OLDEST 100 messages once the table passed 100 rows —
    // meaning new messages saved fine to DB but never showed up in the chat.
    $result = $db->query("
        SELECT
            sender,
            message,
            DATE_FORMAT(timestamp, '%Y-%m-%dT%H:%i:%s') AS timestamp
        FROM chat_messages
        ORDER BY timestamp DESC
        LIMIT 100
    ");
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $rows = array_reverse($rows);
    $db->close();
    sendJSON($rows);
}

// ============================================
// SAVE CHAT MESSAGE
// ============================================
function saveChat($body) {
    $db = getDB();
    ensureChatTable($db);
    $sender  = trim($body['sender'] ?? 'UNKNOWN');
    $message = trim($body['message'] ?? '');
    if (empty($message)) sendJSON(["error" => "Message is empty"]);

    $stmt = $db->prepare("INSERT INTO chat_messages (sender, message, timestamp) VALUES (?, ?, NOW())");
    $stmt->bind_param("ss", $sender, $message);

    if ($stmt->execute()) {
        sendJSON(["success" => true]);
    } else {
        sendJSON(["error" => $stmt->error]);
    }
    $stmt->close();
    $db->close();
}
