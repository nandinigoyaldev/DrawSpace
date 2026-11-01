<?php
/**
 * /api/rooms
 *   GET    – boards owned by the signed-in user
 *   POST   – create a board
 *   PATCH  – rename a board
 *   DELETE – delete a board (and its strokes)
 */

declare(strict_types=1);

require_once __DIR__ . '/room_service.php';

$method = require_method('GET', 'POST', 'PATCH', 'DELETE');
$pdo = db();

if ($method === 'GET') {
    $session = require_session();

    respond([
        'ok'    => true,
        'rooms' => rooms_for_user($pdo, (int) $session['user_id']),
    ]);
}

$session = require_write_session();
$userId = (int) $session['user_id'];

if ($method === 'POST') {
    rate_limit($pdo, rate_limit_key('room-create', (string) $userId), 10, 3600);

    $body = read_json_body(4096);
    $room = create_room($pdo, $userId, $body['name'] ?? DEFAULT_ROOM_NAME);

    respond(['ok' => true, 'room' => room_payload($room, $session)], 201);
}

$body = read_json_body(4096);
$publicId = normalize_room_id(is_string($body['id'] ?? null) ? $body['id'] : '');

if ($method === 'PATCH') {
    $room = find_owned_room($pdo, $publicId, $userId);
    $name = validate_room_name($body['name'] ?? null);

    rate_limit($pdo, rate_limit_key('room-rename', (string) $userId), 60, 3600);

    with_transaction($pdo, static function (PDO $pdo) use ($room, $name): void {
        $pdo->prepare(
            'UPDATE rooms SET name = :name, version = version + 1, updated_at = :now WHERE id = :id'
        )->execute([':name' => $name, ':now' => now(), ':id' => (int) $room['id']]);
    });

    $updated = find_room($pdo, $publicId);

    respond(['ok' => true, 'room' => room_payload($updated, $session)]);
}

// DELETE
$room = find_owned_room($pdo, $publicId, $userId);

with_transaction($pdo, static function (PDO $pdo) use ($room): void {
    $pdo->prepare('DELETE FROM rooms WHERE id = :id')->execute([':id' => (int) $room['id']]);
});

respond(['ok' => true, 'deleted' => $publicId]);
