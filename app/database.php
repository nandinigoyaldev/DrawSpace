<?php

/**
 * DrawSpace · PDO connection
 *
 * Credentials are read from environment variables, optionally loaded from
 * the git-ignored `.env` file at the project root. Nothing secret is ever
 * stored in a tracked file.
 */

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

        // Real environment variables always win over .env values.
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

// Check for full database URL (e.g. from Aiven, Railway, or Vercel: DATABASE_URL / MYSQL_URL)
$databaseUrl = $getEnvVar('DATABASE_URL', $getEnvVar('MYSQL_URL', $getEnvVar('AIVEN_DATABASE_URL', '')));

$host     = '127.0.0.1';
$port     = '3306';
$dbname   = 'drawspace';
$username = 'drawspace_user';
$password = '';
$useSsl   = false;

if ($databaseUrl !== '') {
    $parsed = parse_url($databaseUrl);
    if ($parsed !== false) {
        $host     = $parsed['host'] ?? $host;
        $port     = isset($parsed['port']) ? (string)$parsed['port'] : $port;
        $username = isset($parsed['user']) ? urldecode($parsed['user']) : $username;
        $password = isset($parsed['pass']) ? urldecode($parsed['pass']) : $password;
        if (isset($parsed['path'])) {
            $dbname = ltrim($parsed['path'], '/');
        }
        $useSsl = true; // URIs from cloud DB providers like Aiven require SSL
    }
} else {
    $host     = $getEnvVar('DB_HOST', $getEnvVar('MYSQLHOST', '127.0.0.1'));
    $port     = $getEnvVar('DB_PORT', $getEnvVar('MYSQLPORT', '3306'));
    $dbname   = $getEnvVar('DB_NAME', $getEnvVar('MYSQLDATABASE', 'drawspace'));
    $username = $getEnvVar('DB_USER', $getEnvVar('MYSQLUSER', 'drawspace_user'));
    $password = $getEnvVar('DB_PASS', $getEnvVar('MYSQLPASSWORD', $getEnvVar('DB_PASSWORD', '')));
    
    // Auto-enable SSL if not on localhost, or if DB_SSL is explicitly set
    $sslVar = strtolower($getEnvVar('DB_SSL', ''));
    if ($sslVar === 'true' || $sslVar === '1' || ($host !== '127.0.0.1' && $host !== 'localhost' && $sslVar !== 'false')) {
        $useSsl = true;
    }
}

$appDebug = strtolower($getEnvVar('APP_DEBUG', 'false')) === 'true';

try {
    $pdoOptions = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    // Configure SSL for Aiven / Cloud MySQL databases
    if ($useSsl) {
        if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            $pdoOptions[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }

        $caPath = $getEnvVar('DB_SSL_CA', '');
        if ($caPath !== '' && file_exists($caPath)) {
            $pdoOptions[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
        } else {
            // Check common system CA bundles if available
            $systemCaCertBundles = [
                '/etc/ssl/certs/ca-certificates.crt', // Debian/Ubuntu/Vercel Lambda
                '/etc/pki/tls/certs/ca-bundle.crt',   // RedHat/CentOS/Amazon Linux
                '/etc/ssl/cert.pem',                   // Alpine/macOS
            ];
            foreach ($systemCaCertBundles as $bundle) {
                if (file_exists($bundle)) {
                    $pdoOptions[PDO::MYSQL_ATTR_SSL_CA] = $bundle;
                    break;
                }
            }
        }
    }

    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        $pdoOptions
    );

    // Ensure the shared board table and initial row exist
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS drawings (
            id INT UNSIGNED NOT NULL PRIMARY KEY,
            drawing_data LONGTEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec("INSERT IGNORE INTO drawings (id, drawing_data) VALUES (1, '[]')");
} catch (PDOException $e) {
    // Full details go to the server log
    error_log('[DrawSpace] DB connection failed: ' . $e->getMessage());

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');

    $errorMsg = $appDebug
        ? 'Database connection failed: ' . $e->getMessage()
        : 'Database connection failed. Please check Vercel environment variables & Aiven SSL settings.';

    die(json_encode(['ok' => false, 'error' => $errorMsg]));
}
