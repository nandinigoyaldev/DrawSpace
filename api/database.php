<?php

$host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost';
$port = (int)($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306);
$username = $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root';
$password = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '';
$database = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'draw_space';

$conn = mysqli_init();

try {
    if ($host !== 'localhost' && $host !== '127.0.0.1') {
        mysqli_ssl_set($conn, NULL, NULL, NULL, NULL, NULL);
        mysqli_real_connect($conn, $host, $username, $password, $database, $port, NULL, MYSQLI_CLIENT_SSL);
    } else {
        mysqli_real_connect($conn, $host, $username, $password, $database, $port);
    }
} catch (mysqli_sql_exception $e) {
    if ($e->getCode() === 1049 && $database !== 'defaultdb') {
        $conn = mysqli_init();
        if ($host !== 'localhost' && $host !== '127.0.0.1') {
            mysqli_ssl_set($conn, NULL, NULL, NULL, NULL, NULL);
            mysqli_real_connect($conn, $host, $username, $password, 'defaultdb', $port, NULL, MYSQLI_CLIENT_SSL);
        } else {
            mysqli_real_connect($conn, $host, $username, $password, 'defaultdb', $port);
        }
    } else {
        http_response_code(500);
        die(json_encode(['error' => 'Connection failed: ' . $e->getMessage()]));
    }
}