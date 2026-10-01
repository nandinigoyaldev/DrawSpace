<?php



declare(strict_types=1);

$host     = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1';
$port     = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '3306';
$dbname   = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'draw_space';
$username = $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root';
$password = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '';
$sslVar   = strtolower((string)($_ENV['DB_SSL'] ?? getenv('DB_SSL') ?: ''));
$appDebug = strtolower((string)($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: '')) === 'true';

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// Enable SSL for remote/cloud hosts (e.g. Aiven) unless explicitly disabled
if ($sslVar === 'true' || ($sslVar !== 'false' && $host !== '127.0.0.1' && $host !== 'localhost')) {
    if (defined('Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT')) {
        $options[\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT] = false;
    } elseif (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
        @$options[constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')] = false;
    }
}

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        $options
    );
} catch (PDOException $e) {
    // If the database is unknown on cloud MySQL (e.g. Aiven default is 'defaultdb'), retry with defaultdb
    if ((int)$e->getCode() === 1049 && $dbname !== 'defaultdb') {
        try {
            $pdo = new PDO(
                "mysql:host=$host;port=$port;dbname=defaultdb;charset=utf8mb4",
                $username,
                $password,
                $options
            );
        } catch (PDOException $fallbackErr) {
            handleDbError($fallbackErr, $appDebug);
        }
    } else {
        handleDbError($e, $appDebug);
    }
}

function handleDbError(PDOException $e, bool $debug): never
{
    error_log('[DrawSpace] Database connection error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    
    $message = $debug ? ('Database error: ' . $e->getMessage()) : 'Database connection failed.';
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}
