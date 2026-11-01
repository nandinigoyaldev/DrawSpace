<?php
/**
 * POST /api/register – create an account and its first board.
 */

declare(strict_types=1);

require_once __DIR__ . '/room_service.php';

require_method('POST');
verify_same_origin();

$body = read_json_body(8192);
$username = normalize_username($body['username'] ?? null);
$password = validate_password($body['password'] ?? null, $username);

$pdo = db();

rate_limit($pdo, rate_limit_key('register-ip', client_ip()), 5, 3600);

$createdAt = now();

try {
    [$userId, $room] = with_transaction($pdo, static function (PDO $pdo) use ($username, $password, $createdAt): array {
        $pdo->prepare(
            'INSERT INTO users (username, password_hash, created_at) VALUES (:username, :hash, :created)'
        )->execute([
            ':username' => $username,
            ':hash'     => hash_password($password),
            ':created'  => $createdAt,
        ]);

        $userId = (int) $pdo->lastInsertId();
        $room = create_room($pdo, $userId, 'My first board');

        return [$userId, $room];
    });
} catch (PDOException $error) {
    if ($error->getCode() === '23000') {
        fail(409, 'That username is already taken.');
    }

    throw $error;
}

$session = create_session($pdo, $userId);

respond([
    'ok'        => true,
    'user'      => [
        'id'        => $userId,
        'username'  => $username,
        'createdAt' => $createdAt,
    ],
    'csrfToken' => $session['csrf'],
    'expiresAt' => $session['expires'],
    'room'      => room_payload($room, ['user_id' => $userId]),
], 201);
