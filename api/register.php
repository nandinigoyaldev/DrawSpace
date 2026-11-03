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
$email = normalize_email($body['email'] ?? null);
$password = validate_password($body['password'] ?? null, $username);

$pdo = db();

rate_limit($pdo, rate_limit_key('register-ip', client_ip()), 5, 3600);

$createdAt = now();

try {
    [$userId, $room] = with_transaction($pdo, static function (PDO $pdo) use ($username, $email, $password, $createdAt): array {
        $insert = $pdo->prepare(
            'INSERT INTO users (username, email, password_hash, created_at) VALUES (:username, :email, :hash, :created)'
            . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? ' RETURNING id' : '')
        );
        $insert->execute([
            ':username' => $username,
            ':email'    => $email,
            ':hash'     => hash_password($password),
            ':created'  => $createdAt,
        ]);

        $userId = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
            ? (int) $insert->fetchColumn()
            : (int) $pdo->lastInsertId();
        $room = create_room($pdo, $userId, 'My first board');

        return [$userId, $room];
    });
} catch (PDOException $error) {
    if (in_array($error->getCode(), ['23000', '23505'], true)) {
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
