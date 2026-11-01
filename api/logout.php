<?php
/**
 * POST /api/logout – revoke the current session.
 */

declare(strict_types=1);

require_once __DIR__ . '/room_service.php';

require_method('POST');

$session = require_write_session();
$pdo = db();

destroy_session($pdo, (string) $session['token_hash']);

respond(['ok' => true]);
