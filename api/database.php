<?php

$host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1';
$port = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '3306';
$dbname = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'draw_space';
$username = $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root';
$password = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '';
$ssl = strtolower((string)($_ENV['DB_SSL'] ?? getenv('DB_SSL') ?: ''));

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
];

if ($ssl === 'true' || ($ssl !== 'false' && $host !== '127.0.0.1' && $host !== 'localhost')) {
    if (defined('Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT')) {
        $options[\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT] = false;
    } elseif (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
        @$options[constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')] = false;
    }
}

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $username, $password, $options);
} catch (PDOException $e) {
    if ((int)$e->getCode() === 1049 && $dbname !== 'defaultdb') {
        try {
            $pdo = new PDO("mysql:host=$host;port=$port;dbname=defaultdb;charset=utf8mb4", $username, $password, $options);
        } catch (PDOException $err) {
            http_response_code(500);
            echo json_encode(['error' => $err->getMessage()]);
            exit;
        }
    } else {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}
