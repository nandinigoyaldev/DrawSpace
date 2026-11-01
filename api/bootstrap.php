<?php
/**
 * DrawSpace – shared bootstrap.
 *
 * Loads configuration, installs a safe error handler and exposes the small
 * helpers every endpoint uses. Nothing here should ever print a stack trace
 * or a database message to the browser.
 */

declare(strict_types=1);

const APP_NAME = 'DrawSpace';
const APP_VERSION = '1.0.0';
const SCHEMA_VERSION = '1';

/* ─────────────────────────────── Environment ─────────────────────────────── */

function env(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return (string) $value;
}

function env_bool(string $key, bool $default = false): bool
{
    $value = env($key);

    if ($value === null) {
        return $default;
    }

    return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
}

function load_env_file(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $separator = strpos($line, '=');

        if ($separator === false) {
            continue;
        }

        $key = trim(substr($line, 0, $separator));
        $value = trim(substr($line, $separator + 1));

        if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            continue;
        }

        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $value = trim($value, $value[0]);
        } elseif (($comment = strpos($value, ' #')) !== false) {
            $value = rtrim(substr($value, 0, $comment));
        }

        // Real environment variables (Docker, Vercel, hosting panel) win.
        if (getenv($key) === false && !isset($_ENV[$key])) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

load_env_file(dirname(__DIR__) . '/.env');
load_env_file(dirname(__DIR__) . '/.env.local');

/* ─────────────────────────────── Error handling ──────────────────────────── */

final class ApiException extends RuntimeException
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $extra = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $error): void {
    $isDebug = env_bool('APP_DEBUG');

    if ($error instanceof ApiException) {
        foreach ($error->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        respond(array_merge(['error' => $error->getMessage()], $error->extra), $error->status);
        return;
    }

    error_log(sprintf(
        '[DrawSpace] %s: %s in %s:%d',
        $error::class,
        $error->getMessage(),
        $error->getFile(),
        $error->getLine()
    ));

    $payload = ['error' => 'Something went wrong. Please try again.'];

    if ($isDebug) {
        $payload['debug'] = [
            'type'    => $error::class,
            'message' => $error->getMessage(),
            'file'    => basename($error->getFile()),
            'line'    => $error->getLine(),
        ];
    }

    respond($payload, 500);
});

/* ─────────────────────────────── HTTP helpers ────────────────────────────── */

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }

    return trusted_proxy()
        && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function trusted_proxy(): bool
{
    // Vercel terminates TLS before invoking PHP and sets VERCEL=1. Its
    // forwarded headers are therefore trusted without requiring an extra
    // project variable; other proxies remain opt-in.
    return env('VERCEL') !== null || env_bool('TRUST_PROXY', false);
}

function send_api_headers(): void
{
    header_remove('X-Powered-By');
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(int $status, string $message, array $extra = [], array $headers = []): never
{
    throw new ApiException($status, $message, $extra, $headers);
}

function require_method(string ...$allowed): string
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        fail(405, 'Method not allowed.');
    }

    return $method;
}

/**
 * Read and validate a JSON object body. Rejects oversized payloads *before*
 * decoding and refuses top-level arrays/scalars.
 */
function read_json_body(int $maxBytes = 262144): array
{
    $declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

    if ($declared > $maxBytes) {
        fail(413, 'Request body is too large.');
    }

    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);

    if ($raw === false) {
        fail(400, 'Could not read the request body.');
    }

    if (strlen($raw) > $maxBytes) {
        fail(413, 'Request body is too large.');
    }

    if (trim($raw) === '') {
        return [];
    }

    try {
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        fail(400, 'Invalid JSON body.');
    }

    if (!is_array($data) || (array_is_list($data) && $data !== [])) {
        fail(400, 'Expected a JSON object.');
    }

    return $data;
}

function client_ip(): string
{
    if (trusted_proxy()) {
        $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');

        if ($forwarded !== '') {
            $candidate = trim(explode(',', $forwarded)[0]);

            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }
    }

    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '0.0.0.0';
}

function user_agent(): string
{
    return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

/**
 * Defence in depth against CSRF: browsers send Origin on state-changing
 * requests. If present, it must be the exact scheme + authority serving the
 * API. Default ports are normalised because HTTP_HOST sometimes includes them
 * while Origin omits them.
 */
function verify_same_origin(): void
{
    $scheme = is_https() ? 'https' : 'http';
    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));

    if ($host === '') {
        fail(403, 'Cross-origin request blocked.');
    }

    $defaultPort = $scheme === 'https' ? 443 : 80;

    if (str_ends_with($host, ':' . $defaultPort)) {
        $host = substr($host, 0, -strlen(':' . $defaultPort));
    }

    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $headerName) {
        $value = trim((string) ($_SERVER[$headerName] ?? ''));

        if ($value === '') {
            continue;
        }

        $parts = parse_url($value);

        if (
            $value === 'null'
            || $parts === false
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || strtolower((string) $parts['scheme']) !== $scheme
        ) {
            fail(403, 'Cross-origin request blocked.');
        }

        $originHost = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        if ($port !== null && $port !== $defaultPort) {
            $originHost .= ':' . $port;
        }

        if ($originHost !== $host) {
            fail(403, 'Cross-origin request blocked.');
        }
    }
}

function now(): int
{
    return time();
}

/** Validate and normalise a public room id. */
function normalize_room_id(string $roomId): string
{
    $roomId = strtolower(trim($roomId));

    if (!preg_match('/^[a-f0-9]{18}$/', $roomId)) {
        fail(400, 'Invalid board id.');
    }

    return $roomId;
}

if (PHP_SAPI !== 'cli') {
    send_api_headers();
}
