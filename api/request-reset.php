<?php

declare(strict_types=1);

require_once __DIR__ . '/mail.php';

require_method('POST');
verify_same_origin();

$body = read_json_body(4096);
$email = normalize_email($body['email'] ?? null);
$pdo = db();

rate_limit($pdo, rate_limit_key('reset-ip', client_ip()), 5, 900);
rate_limit($pdo, rate_limit_key('reset-email', $email), 3, 900);

$statement = $pdo->prepare('SELECT id, email FROM users WHERE email = :email LIMIT 1');
$statement->execute([':email' => $email]);
$user = $statement->fetch();

if ($user) {
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('DELETE FROM password_resets WHERE user_id = :user_id')->execute([':user_id' => (int) $user['id']]);
    $pdo->prepare(
        'INSERT INTO password_resets (token_hash, user_id, expires_at, created_at, used_at)
         VALUES (:token_hash, :user_id, :expires_at, :created_at, NULL)'
    )->execute([
        ':token_hash' => hash('sha256', $token),
        ':user_id' => (int) $user['id'],
        ':expires_at' => now() + 1800,
        ':created_at' => now(),
    ]);

    send_password_reset_email($email, $token);
}

respond(['ok' => true, 'message' => 'If that email belongs to DrawSpace, a reset link is on its way.']);
