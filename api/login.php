<?php
/**
 * POST /api/login – verify credentials and issue a fresh session.
 */

declare(strict_types=1);

require_once __DIR__ . '/room_service.php';

require_method('POST');
verify_same_origin();

$body = read_json_body(8192);
$rawLogin = is_string($body['username'] ?? null) ? $body['username'] : '';
// Usernames are at most 20 characters; capping keeps hostile inputs out of
// query keys and logs without changing how real accounts behave.
$login = mb_substr(mb_strtolower(trim($rawLogin)), 0, 64);
$password = is_string($body['password'] ?? null) ? $body['password'] : '';

if ($login === '' || $password === '') {
    fail(422, 'Enter your username and password.');
}

$pdo = db();

$accountKey = rate_limit_key('login-account', client_ip() . '|' . $login);
$ipKey = rate_limit_key('login-ip', client_ip());

// Check before doing any expensive work.
rate_limit_guard($pdo, $accountKey, MAX_LOGIN_ATTEMPTS, LOGIN_WINDOW_SECONDS);
rate_limit_guard($pdo, $ipKey, MAX_LOGIN_ATTEMPTS_PER_IP, LOGIN_WINDOW_SECONDS);

$statement = $pdo->prepare('SELECT id, username, password_hash, created_at FROM users WHERE username = :username LIMIT 1');
$statement->execute([':username' => $login]);
$user = $statement->fetch();

$hash = $user ? (string) $user['password_hash'] : dummy_password_hash();
$valid = verify_password($password, $hash) && $user !== false;

if (!$valid) {
    rate_limit_hit($pdo, $accountKey, LOGIN_WINDOW_SECONDS);
    rate_limit_hit($pdo, $ipKey, LOGIN_WINDOW_SECONDS);

    // Strip control characters and cap the value: usernames come straight
    // from an untrusted request and end up in the server log.
    $safeLogin = mb_substr(preg_replace('/[^\x20-\x7E]/', '?', $login) ?? '', 0, 64);
    error_log(sprintf('[DrawSpace] Failed login for "%s" from %s', $safeLogin, client_ip()));
    fail(401, 'Incorrect username or password.');
}

// A successful login clears the per-account failure counter, but not the
// broader per-IP one (that keeps distributed guessing expensive).
rate_limit_clear($pdo, $accountKey);

$userId = (int) $user['id'];

if (password_needs_upgrade((string) $user['password_hash'])) {
    $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
        ->execute([':hash' => hash_password($password), ':id' => $userId]);
}

// Rotate the session on every login to defeat session fixation.
$existing = current_session();

if ($existing !== null) {
    $pdo->prepare('DELETE FROM sessions WHERE token_hash = :hash')
        ->execute([':hash' => $existing['token_hash']]);
}

$session = create_session($pdo, $userId);

respond([
    'ok'        => true,
    'user'      => [
        'id'        => $userId,
        'username'  => (string) $user['username'],
        'createdAt' => (int) $user['created_at'],
    ],
    'csrfToken' => $session['csrf'],
    'expiresAt' => $session['expires'],
]);
