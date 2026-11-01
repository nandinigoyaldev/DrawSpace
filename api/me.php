<?php
/**
 * GET /api/me – current user + CSRF token for the browser.
 */

declare(strict_types=1);

require_once __DIR__ . '/room_service.php';

require_method('GET');

$session = require_session();

respond([
    'ok'        => true,
    'user'      => public_user($session),
    'csrfToken' => (string) $session['csrf_token'],
    'expiresAt' => (int) $session['expires_at'],
]);
