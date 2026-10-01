<?php

/**
 * DrawSpace · Diagnostics endpoint
 * Visit /api/db-check.php in your browser to inspect database configuration and connectivity.
 */

declare(strict_types=1);

@ini_set('display_errors', '0');
@error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');

$envFile = dirname(__DIR__) . '/.env';

if (is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value, " \t\"'");
        if ($key !== '' && getenv($key) === false && !isset($_ENV[$key])) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

$getEnvVar = static function (string $name, string $default = ''): string {
    if (isset($_ENV[$name]) && $_ENV[$name] !== '') {
        return (string)$_ENV[$name];
    }
    if (isset($_SERVER[$name]) && $_SERVER[$name] !== '') {
        return (string)$_SERVER[$name];
    }
    $val = getenv($name);
    return ($val !== false && $val !== '') ? (string)$val : $default;
};

$databaseUrl = $getEnvVar('DATABASE_URL', $getEnvVar('MYSQL_URL', $getEnvVar('AIVEN_DATABASE_URL', '')));

$host     = '127.0.0.1';
$port     = '3306';
$dbname   = 'drawspace';
$username = 'drawspace_user';
$password = '';
$source   = 'default';
$useSsl   = false;

if ($databaseUrl !== '') {
    $source = 'DATABASE_URL / MYSQL_URL';
    $parsed = parse_url($databaseUrl);
    if ($parsed !== false) {
        $host     = $parsed['host'] ?? $host;
        $port     = isset($parsed['port']) ? (string)$parsed['port'] : $port;
        $username = isset($parsed['user']) ? urldecode($parsed['user']) : $username;
        $password = isset($parsed['pass']) ? urldecode($parsed['pass']) : $password;
        if (isset($parsed['path'])) {
            $dbname = ltrim($parsed['path'], '/');
        }
        $useSsl = true;
    }
} else {
    $source   = 'individual DB_* vars';
    $host     = $getEnvVar('DB_HOST', $getEnvVar('MYSQLHOST', '127.0.0.1'));
    $port     = $getEnvVar('DB_PORT', $getEnvVar('MYSQLPORT', '3306'));
    $dbname   = $getEnvVar('DB_NAME', $getEnvVar('MYSQLDATABASE', 'drawspace'));
    $username = $getEnvVar('DB_USER', $getEnvVar('MYSQLUSER', 'drawspace_user'));
    $password = $getEnvVar('DB_PASS', $getEnvVar('MYSQLPASSWORD', $getEnvVar('DB_PASSWORD', '')));
    
    $sslVar = strtolower($getEnvVar('DB_SSL', ''));
    if ($sslVar === 'true' || $sslVar === '1' || ($host !== '127.0.0.1' && $host !== 'localhost' && $sslVar !== 'false')) {
        $useSsl = true;
    }
}

$report = [
    'status' => 'testing',
    'config_detected' => [
        'source'            => $source,
        'host'              => $host,
        'port'              => $port,
        'database_name'     => $dbname,
        'username'          => $username,
        'password_is_set'   => $password !== '',
        'ssl_enabled'       => $useSsl,
        'env_keys_present'  => array_values(array_filter([
            isset($_ENV['DATABASE_URL']) || getenv('DATABASE_URL') ? 'DATABASE_URL' : null,
            isset($_ENV['MYSQL_URL']) || getenv('MYSQL_URL') ? 'MYSQL_URL' : null,
            isset($_ENV['DB_HOST']) || getenv('DB_HOST') ? 'DB_HOST' : null,
            isset($_ENV['DB_PORT']) || getenv('DB_PORT') ? 'DB_PORT' : null,
            isset($_ENV['DB_NAME']) || getenv('DB_NAME') ? 'DB_NAME' : null,
            isset($_ENV['DB_USER']) || getenv('DB_USER') ? 'DB_USER' : null,
            isset($_ENV['DB_PASS']) || getenv('DB_PASS') ? 'DB_PASS' : null,
        ])),
    ],
    'connection' => null,
    'table_check' => null,
    'row_check' => null,
];

try {
    $pdoOptions = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 5,
    ];

    if ($useSsl) {
        if (defined('Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT')) {
            $pdoOptions[\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT] = false;
        } elseif (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            @$pdoOptions[constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')] = false;
        }
    }

    $pdo = null;
    try {
        $pdo = new PDO(
            "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
            $username,
            $password,
            $pdoOptions
        );
        $report['connection'] = "SUCCESS: Connected to database `$dbname`.";
    } catch (PDOException $e) {
        if ((int)$e->getCode() === 1049 || str_contains($e->getMessage(), 'Unknown database')) {
            // Try connecting to defaultdb (Aiven standard)
            $pdo = new PDO(
                "mysql:host=$host;port=$port;dbname=defaultdb;charset=utf8mb4",
                $username,
                $password,
                $pdoOptions
            );
            $report['connection'] = "SUCCESS: Connected via fallback database `defaultdb`.";
        } else {
            throw $e;
        }
    }

    // Check table
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS drawings (
            id INT UNSIGNED NOT NULL PRIMARY KEY,
            drawing_data LONGTEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $report['table_check'] = 'SUCCESS: Table `drawings` exists or was created.';

    // Check row
    $pdo->exec("INSERT IGNORE INTO drawings (id, drawing_data) VALUES (1, '[]')");
    $stmt = $pdo->query("SELECT id, LENGTH(drawing_data) as stroke_bytes FROM drawings WHERE id = 1");
    $row = $stmt->fetch();

    $report['row_check'] = [
        'status' => 'SUCCESS',
        'row' => $row,
    ];
    $report['status'] = 'ALL_OK';
} catch (PDOException $e) {
    http_response_code(500);
    $report['status'] = 'FAILED';
    $report['connection_error'] = [
        'message' => $e->getMessage(),
        'code'    => $e->getCode(),
    ];
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
