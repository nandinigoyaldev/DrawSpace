<?php

declare(strict_types=1);

require_once __DIR__ . '/room_service.php';

require_method('POST');
verify_same_origin();

$session = require_session();
require_csrf($session);

$body = read_json_body(4096);
$email = normalize_email($body['email'] ?? null);
$pdo = db();

$statement = $pdo->prepare(
    'UPDATE users SET email = :email WHERE id = :id'
);
$statement->execute([
    ':email' => $email,
    ':id' => (int) $session['user_id'],
]);

respond([
    'ok' => true,
    'message' => 'Recovery email saved. You can now reset your password from the sign-in page.',
]);
