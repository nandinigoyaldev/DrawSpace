<?php
/**
 * GET /api/google/start and /api/google/callback – Google OAuth 2.0.
 *
 * The authorization-code exchange happens server-side. Google identities are
 * stored separately from password accounts; no email-based auto-merging occurs.
 */

declare(strict_types=1);

require_once __DIR__ . '/room_service.php';

$action = strtolower((string) ($_GET['action'] ?? 'start'));

function google_safe_next(mixed $raw): string
{
    if (!is_string($raw) || $raw === '' || !str_starts_with($raw, '/') || str_starts_with($raw, '//') || str_contains($raw, '\\')) {
        return '/';
    }

    return $raw;
}

function google_http(string $url, array $options = []): array
{
    if (!function_exists('curl_init')) {
        fail(503, 'Google sign-in is unavailable on this server.');
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, $options + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    unset($curl);

    if ($body === false || $error !== '' || $status < 200 || $status >= 300) {
        error_log('[DrawSpace] Google request failed: ' . ($error !== '' ? $error : 'HTTP ' . $status));
        fail(502, 'Google sign-in could not be completed.');
    }

    $payload = json_decode((string) $body, true);

    if (!is_array($payload)) {
        fail(502, 'Google returned an invalid response.');
    }

    return $payload;
}

function google_state_cookie_options(int $expires): array
{
    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

if ($action === 'start') {
    if (!google_is_configured()) {
        fail(503, 'Google sign-in is not configured for this deployment.');
    }

    $next = google_safe_next($_GET['next'] ?? null);
    $state = bin2hex(random_bytes(32));
    setcookie('drawspace_google_state', $state, google_state_cookie_options(now() + 600));
    setcookie('drawspace_google_next', $next, google_state_cookie_options(now() + 600));

    $query = http_build_query([
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'redirect_uri' => google_redirect_uri(),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'access_type' => 'online',
        'prompt' => 'select_account',
    ]);

    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $query, true, 302);
    exit;
}

if ($action !== 'callback') {
    fail(404, 'Google sign-in route not found.');
}

$state = (string) ($_GET['state'] ?? '');
$cookieState = (string) ($_COOKIE['drawspace_google_state'] ?? '');

if ($state === '' || $cookieState === '' || !hash_equals($cookieState, $state)) {
    fail(400, 'Google sign-in expired. Please try again.');
}

setcookie('drawspace_google_state', '', google_state_cookie_options(now() - 3600));
$next = google_safe_next($_COOKIE['drawspace_google_next'] ?? null);
setcookie('drawspace_google_next', '', google_state_cookie_options(now() - 3600));

if (isset($_GET['error'])) {
    header('Location: /login?next=' . rawurlencode($next) . '&google=cancelled', true, 302);
    exit;
}

$code = (string) ($_GET['code'] ?? '');

if ($code === '' || strlen($code) > 2048) {
    fail(400, 'Google did not return an authorization code.');
}

$token = google_http('https://oauth2.googleapis.com/token', [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'code' => $code,
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => google_redirect_uri(),
        'grant_type' => 'authorization_code',
    ]),
    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
]);

$accessToken = is_string($token['access_token'] ?? null) ? $token['access_token'] : '';

if ($accessToken === '') {
    fail(502, 'Google did not provide an access token.');
}

$profile = google_http('https://openidconnect.googleapis.com/v1/userinfo', [
    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $accessToken],
]);

$subject = is_string($profile['sub'] ?? null) ? $profile['sub'] : '';
$email = is_string($profile['email'] ?? null) ? strtolower(trim($profile['email'])) : '';
$verified = ($profile['email_verified'] ?? false) === true;

if ($subject === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$verified) {
    fail(400, 'Google returned an account without a verified email address.');
}

$pdo = db();
$lookup = $pdo->prepare(
    'SELECT u.id FROM oauth_accounts a JOIN users u ON u.id = a.user_id
     WHERE a.provider = :provider AND a.subject = :subject LIMIT 1'
);
$lookup->execute([':provider' => 'google', ':subject' => $subject]);
$userId = (int) ($lookup->fetchColumn() ?: 0);

if ($userId === 0) {
    $base = preg_replace('/[^a-z0-9_]+/', '_', strtolower((string) strstr($email, '@', true))) ?: 'artist';
    $base = trim(substr($base, 0, 15), '_') ?: 'artist';
    $username = $base;

    for ($suffix = 0; $suffix < 100; $suffix++) {
        $candidate = $suffix === 0 ? $base : substr($base, 0, 14) . '_' . $suffix;
        $check = $pdo->prepare('SELECT 1 FROM users WHERE username = :username LIMIT 1');
        $check->execute([':username' => $candidate]);

        if (!$check->fetchColumn()) {
            $username = $candidate;
            break;
        }
    }

    $created = now();
    $password = bin2hex(random_bytes(32));
    $pdo->beginTransaction();

    try {
        $insert = $pdo->prepare(
            'INSERT INTO users (username, password_hash, created_at) VALUES (:username, :hash, :created)'
            . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? ' RETURNING id' : '')
        );
        $insert->execute([
            ':username' => $username,
            ':hash' => hash_password($password),
            ':created' => $created,
        ]);
        $userId = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
            ? (int) $insert->fetchColumn()
            : (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO oauth_accounts (provider, subject, user_id, email, created_at)
             VALUES (:provider, :subject, :user_id, :email, :created)'
        )->execute([
            ':provider' => 'google',
            ':subject' => $subject,
            ':user_id' => $userId,
            ':email' => $email,
            ':created' => $created,
        ]);
        create_room($pdo, $userId, 'My first board');
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

$session = create_session($pdo, $userId);
header('Location: ' . $next, true, 302);
exit;
