<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/database.php';

try {
    $stmt = $pdo->query('SELECT drawing_data FROM drawings WHERE id = 1');
    $row = $stmt->fetch();
    
    if ($row && isset($row['drawing_data'])) {
        echo $row['drawing_data'];
    } else {
        echo '[]';
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
