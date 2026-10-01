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
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

$env = static function (string $name, string $default = ''): string {
    $value = getenv($name);
    return $value === false ? $default : $value;
};

$host      = $env('DB_HOST', '127.0.0.1');
$port      = $env('DB_PORT', '3306');
$dbname    = $env('DB_NAME', 'drawspace');
$username  = $env('DB_USER', 'drawspace_user');
$password  = $env('DB_PASS', '');
$appDebug  = strtolower($env('APP_DEBUG', 'false')) === 'true';

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    // Full details go to the server log, never to the browser.
    error_log('[DrawSpace] DB connection failed: ' . $e->getMessage());

    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');

    if ($appDebug) {
        die('Database connection failed: ' . $e->getMessage());
    }

    die('Database connection failed. Check the .env configuration on the server.');
}
