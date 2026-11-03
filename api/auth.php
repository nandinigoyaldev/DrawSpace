<?php
/**
 * DrawSpace – authentication, sessions and rate limiting.
 *
 * Sessions are opaque random tokens stored as SHA-256 hashes in the database.
 * The raw token only ever exists in the visitor's HttpOnly cookie, so a
 * database leak cannot be replayed as a login. CSRF uses the classic
 * synchroniser-token pattern with a per-session secret.
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';

const SESSION_COOKIE = 'drawspace_session';
const SESSION_TOKEN_BYTES = 32;
const MAX_LOGIN_ATTEMPTS = 6;
const LOGIN_WINDOW_SECONDS = 900;
const MAX_LOGIN_ATTEMPTS_PER_IP = 40;

// Valid hashes with no usable plaintext. Verifying against one of these keeps
// "unknown user" and "wrong password" response times close, which mitigates
// username enumeration by timing. Prefer a verifier that matches the current
// hashing algorithm so the comparison cost is comparable.
const DUMMY_ARGON2ID_HASH = '$argon2id$v=19$m=65536,t=4,p=1$REMuVi5HSXozeE51MzgySw$gojuOOJpJtQYvVqXmhjjlGcJ044vZ8ttMF29xAt6avA';
const DUMMY_BCRYPT_HASH = '$2y$12$c1fk.AhuRcHZzDElG50mBuyBGqJ1edo1Mpf9UTM6V7ouUDPekhZ0u';

const RESERVED_USERNAMES = ['admin', 'administrator', 'root', 'system', 'support', 'drawspace', 'api'];

/* ───────────────────────────────── Passwords ─────────────────────────────── */

function password_algorithm(): string|int
{
    return in_array('argon2id', password_algos(), true) ? 'argon2id' : PASSWORD_BCRYPT;
}

/** @return array<string, int> */
function password_options(): array
{
    if (password_algorithm() === 'argon2id') {
        return [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 1,
        ];
    }

    return ['cost' => 12];
}

function hash_password(string $password): string
{
    return password_hash($password, password_algorithm(), password_options());
}

function verify_password(string $password, string $hash): bool
{
    return password_verify($password, $hash);
}

function dummy_password_hash(): string
{
    return password_algorithm() === 'argon2id'
        ? DUMMY_ARGON2ID_HASH
        : DUMMY_BCRYPT_HASH;
}

function password_needs_upgrade(string $hash): bool
{
    return password_needs_rehash($hash, password_algorithm(), password_options());
}

/* ───────────────────────────────── Validation ────────────────────────────── */

function normalize_username(mixed $raw): string
{
    if (!is_string($raw)) {
        fail(422, 'Please choose a username.');
    }

    $username = mb_strtolower(trim($raw));

    if (!preg_match('/^[a-z0-9_]{3,20}$/', $username)) {
        fail(422, 'Usernames are 3–20 characters: letters, numbers and underscores only.');
    }

    if (in_array($username, RESERVED_USERNAMES, true)) {
        fail(422, 'That username is reserved. Please pick another one.');
    }

    return $username;
}

function validate_password(mixed $raw, string $username): string
{
    if (!is_string($raw)) {
        fail(422, 'Please choose a password.');
    }

    $length = mb_strlen($raw);

    if ($length < 10) {
        fail(422, 'Passwords need at least 10 characters.');
    }

    // bcrypt silently truncates after 72 bytes; refuse rather than mislead.
    if (strlen($raw) > 72) {
        fail(422, 'Passwords can be at most 72 bytes (about 72 characters).');
    }

    if (preg_match('/^(.)\1+$/u', $raw)) {
        fail(422, 'That password is too repetitive.');
    }

    $common = [
        'password12', 'password123', '1234567890', 'qwertyuiop', 'letmein123',
        'iloveyou12', 'welcome123', 'drawspace1', 'admin12345', 'changeme123',
    ];

    if (in_array(mb_strtolower($raw), $common, true)) {
        fail(422, 'That password is too common. Try a longer passphrase.');
    }

    if (mb_strtolower($raw) === $username) {
        fail(422, 'Your password cannot be the same as your username.');
    }

    return $raw;
}

/* ────────────────────────────────── Sessions ─────────────────────────────── */

function session_ttl_seconds(): int
{
    $days = max(1, min(90, (int) env('SESSION_TTL_DAYS', '14')));

    return $days * 86400;
}

/** @return array{expires:int, path:string, secure:bool, httponly:bool, samesite:string} */
function session_cookie_options(int $expires): array
{
    return [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function set_session_cookie(string $token, int $expires): void
{
    setcookie(SESSION_COOKIE, $token, session_cookie_options($expires));
}

function clear_session_cookie(): void
{
    unset($_COOKIE[SESSION_COOKIE]);
    setcookie(SESSION_COOKIE, '', session_cookie_options(now() - 3600));
}

/**
 * Create a brand-new session (also rotates any previous one – callers should
 * have deleted the old row first when re-authenticating).
 *
 * @return array{token:string, csrf:string, expires:int}
 */
function create_session(PDO $pdo, int $userId): array
{
    $token = bin2hex(random_bytes(SESSION_TOKEN_BYTES));
    $csrf = bin2hex(random_bytes(32));
    $created = now();
    $expires = $created + session_ttl_seconds();

    $pdo->prepare(
        'INSERT INTO sessions (token_hash, user_id, csrf_token, created_at, expires_at, last_seen, ip, user_agent)
         VALUES (:token_hash, :user_id, :csrf, :created, :expires, :seen, :ip, :agent)'
    )->execute([
        ':token_hash' => hash('sha256', $token),
        ':user_id'    => $userId,
        ':csrf'       => $csrf,
        ':created'    => $created,
        ':expires'    => $expires,
        ':seen'       => $created,
        ':ip'         => client_ip(),
        ':agent'      => user_agent(),
    ]);

    set_session_cookie($token, $expires);

    return ['token' => $token, 'csrf' => $csrf, 'expires' => $expires];
}

function google_is_configured(): bool
{
    return env('GOOGLE_CLIENT_ID') !== null
        && env('GOOGLE_CLIENT_SECRET') !== null
        && env('GOOGLE_REDIRECT_URI') !== null;
}

function google_redirect_uri(): string
{
    $uri = env('GOOGLE_REDIRECT_URI');

    if ($uri === null || !filter_var($uri, FILTER_VALIDATE_URL)) {
        fail(503, 'Google sign-in is not configured.');
    }

    return $uri;
}

/** @return array<string, mixed>|null */
function current_session(): ?array
{
    static $loaded = false;
    static $session = null;

    if ($loaded) {
        return $session;
    }

    $loaded = true;
    $token = (string) ($_COOKIE[SESSION_COOKIE] ?? '');

    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    $pdo = db();
    $statement = $pdo->prepare(
        'SELECT s.token_hash, s.user_id, s.csrf_token, s.expires_at, s.last_seen,
                u.username, u.created_at AS user_created_at
         FROM sessions s
         JOIN users u ON u.id = s.user_id
         WHERE s.token_hash = :hash AND s.expires_at > :now
         LIMIT 1'
    );
    $statement->execute([':hash' => hash('sha256', $token), ':now' => now()]);
    $row = $statement->fetch();

    if (!$row) {
        clear_session_cookie();
        return null;
    }

    $session = [
        'token_hash' => (string) $row['token_hash'],
        'user_id'    => (int) $row['user_id'],
        'username'   => (string) $row['username'],
        'csrf_token' => (string) $row['csrf_token'],
        'expires_at' => (int) $row['expires_at'],
        'created_at' => (int) $row['user_created_at'],
    ];

    if ((int) $row['last_seen'] < now() - 300) {
        try {
            $pdo->prepare('UPDATE sessions SET last_seen = :now WHERE token_hash = :hash')
                ->execute([':now' => now(), ':hash' => $session['token_hash']]);
        } catch (Throwable $error) {
            error_log('[DrawSpace] Could not refresh session: ' . $error->getMessage());
        }
    }

    return $session;
}

/** @return array<string, mixed> */
function require_session(): array
{
    $session = current_session();

    if ($session === null) {
        fail(401, 'Please sign in to continue.');
    }

    return $session;
}

function require_csrf(array $session): void
{
    $provided = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    if ($provided === '' || !hash_equals((string) $session['csrf_token'], $provided)) {
        fail(403, 'Your session expired or the request could not be verified. Refresh and try again.');
    }
}

/**
 * Guard for every state-changing authenticated endpoint.
 *
 * @return array<string, mixed>
 */
function require_write_session(): array
{
    verify_same_origin();
    $session = require_session();
    require_csrf($session);

    return $session;
}

function destroy_session(PDO $pdo, string $tokenHash): void
{
    $pdo->prepare('DELETE FROM sessions WHERE token_hash = :hash')->execute([':hash' => $tokenHash]);
    clear_session_cookie();
}

function destroy_user_sessions(PDO $pdo, int $userId): void
{
    $pdo->prepare('DELETE FROM sessions WHERE user_id = :id')->execute([':id' => $userId]);
    clear_session_cookie();
}

/** @return array{id:int, username:string, createdAt:int} */
function public_user(array $session): array
{
    return [
        'id'        => (int) $session['user_id'],
        'username'  => (string) $session['username'],
        'createdAt' => (int) $session['created_at'],
    ];
}

/* ─────────────────────────────── Rate limiting ───────────────────────────── */

function rate_limit_key(string $bucket, string $value): string
{
    return $bucket . ':' . hash('sha256', $value);
}

/** Throws when the bucket is exhausted. Does not increment. */
function rate_limit_guard(PDO $pdo, string $key, int $max, int $window): void
{
    $statement = $pdo->prepare('SELECT rl_count, rl_reset FROM rate_limits WHERE rl_key = :k');
    $statement->execute([':k' => $key]);
    $row = $statement->fetch();

    if (!$row || (int) $row['rl_reset'] <= now()) {
        return;
    }

    if ((int) $row['rl_count'] >= $max) {
        $retry = max(1, (int) $row['rl_reset'] - now());

        fail(
            429,
            'Too many attempts. Please wait a moment and try again.',
            ['retryAfter' => $retry],
            ['Retry-After: ' . $retry]
        );
    }
}

/** Increment a bucket, starting a fresh window when the old one expired. */
function rate_limit_hit(PDO $pdo, string $key, int $window): void
{
    $now = now();

    $update = $pdo->prepare(
        'UPDATE rate_limits SET rl_count = rl_count + 1
         WHERE rl_key = :k AND rl_reset > :now'
    );
    $update->execute([':k' => $key, ':now' => $now]);

    if ($update->rowCount() > 0) {
        return;
    }

    try {
        $pdo->prepare(
            'INSERT INTO rate_limits (rl_key, rl_count, rl_reset) VALUES (:k, 1, :reset)'
        )->execute([':k' => $key, ':reset' => $now + $window]);
    } catch (PDOException) {
        // Another request created the row first.
        $pdo->prepare(
            'UPDATE rate_limits SET rl_count = rl_count + 1
             WHERE rl_key = :k AND rl_reset > :now'
        )->execute([':k' => $key, ':now' => $now]);
    }
}

/** Guard + increment in one call (for write throttles). */
function rate_limit(PDO $pdo, string $key, int $max, int $window): void
{
    rate_limit_guard($pdo, $key, $max, $window);
    rate_limit_hit($pdo, $key, $window);
}

function rate_limit_clear(PDO $pdo, string $key): void
{
    $pdo->prepare('DELETE FROM rate_limits WHERE rl_key = :k')->execute([':k' => $key]);
}
