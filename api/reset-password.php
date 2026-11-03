<?php

declare(strict_types=1);

require_once __DIR__ . '/room_service.php';

require_method('POST');
verify_same_origin();

$body = read_json_body(8192);
$token = is_string($body['token'] ?? null) ? trim($body['token']) : '';
$password = $body['password'] ?? null;

if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    fail(422, 'That reset link is invalid or expired.');
}

$pdo = db();
$statement = $pdo->prepare(
    'SELECT user_id FROM password_resets
     WHERE token_hash = :token_hash AND expires_at > :now AND used_at IS NULL
     LIMIT 1'
);
$statement->execute([':token_hash' => hash('sha256', $token), ':now' => now()]);
$reset = $statement->fetch();

if (!$reset) {
    fail(422, 'That reset link is invalid or expired.');
}

$userStatement = $pdo->prepare('SELECT username FROM users WHERE id = :id LIMIT 1');
$userStatement->execute([':id' => (int) $reset['user_id']]);
$user = $userStatement->fetch();

if (!$user) {
    fail(422, 'That reset link is invalid or expired.');
}

$newPassword = validate_password($password, (string) $user['username']);

with_transaction($pdo, static function (PDO $pdo) use ($reset, $newPassword, $token): void {
    $updated = $pdo->prepare(
        'UPDATE password_resets SET used_at = :used_at
         WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > :now'
    );
    $updated->execute([
        ':used_at' => now(),
        ':token_hash' => hash('sha256', $token),
        ':now' => now(),
    ]);

    if ($updated->rowCount() !== 1) {
        fail(422, 'That reset link is invalid or expired.');
    }

    $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
        ->execute([':hash' => hash_password($newPassword), ':id' => (int) $reset['user_id']]);
    $pdo->prepare('DELETE FROM sessions WHERE user_id = :id')
        ->execute([':id' => (int) $reset['user_id']]);
});

respond(['ok' => true, 'message' => 'Your password was reset. You can sign in now.']);
