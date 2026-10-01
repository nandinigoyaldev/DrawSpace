<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$data = file_get_contents('php://input');

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'No data']);
    exit;
}

require_once __DIR__ . '/database.php';

try {
    $stmt = $pdo->prepare('UPDATE drawings SET drawing_data = :drawing_data WHERE id = 1');
    $stmt->execute(['drawing_data' => $data]);
    echo json_encode(['ok' => true]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
