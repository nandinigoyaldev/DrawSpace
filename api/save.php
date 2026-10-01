<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$data = file_get_contents('php://input');

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'No data received']);
    exit;
}

require_once __DIR__ . '/database.php';

$escaped = mysqli_real_escape_string($conn, $data);
$sql = "UPDATE drawings SET drawing_data = '$escaped' WHERE id = 1";

if (mysqli_query($conn, $sql)) {
    echo json_encode(['ok' => true]);
} else {
    http_response_code(500);
    echo json_encode(['error' => mysqli_error($conn)]);
}

mysqli_close($conn);
