<?php
/**
 * GET /api/load?room=<public-id>&v=<version>
 *
 * Returns a full snapshot of a board. When the caller's `v` matches the
 * server's board version, only `{unchanged: true}` is sent – polling stays
 * cheap while the board is idle.
 */

declare(strict_types=1);

require_once __DIR__ . '/room_service.php';

require_method('GET');

$session = require_session();
$pdo = db();

$roomId = normalize_room_id(is_string($_GET['room'] ?? null) ? $_GET['room'] : '');

$clientVersion = null;

if (isset($_GET['v'])) {
    $rawVersion = $_GET['v'];

    if (!is_string($rawVersion) || !preg_match('/^\d{1,18}$/', $rawVersion)) {
        fail(400, 'Invalid board version.');
    }

    $clientVersion = (int) $rawVersion;
}

$room = find_room($pdo, $roomId);

if ($clientVersion !== null && $clientVersion === (int) $room['version']) {
    respond(['ok' => true, 'unchanged' => true, 'version' => $clientVersion]);
}

$statement = $pdo->prepare(
    'SELECT s.id, s.user_id, s.mode, s.color, s.width, s.points, s.created_at,
            u.username
     FROM strokes s
     JOIN users u ON u.id = s.user_id
     WHERE s.room_id = :room
     ORDER BY s.id ASC
     LIMIT ' . MAX_STROKES_PER_ROOM
);
$statement->execute([':room' => (int) $room['id']]);

$strokes = [];

foreach ($statement->fetchAll() as $row) {
    $stroke = stroke_payload($row, $session);

    // Never send malformed legacy rows to the canvas.
    if ($stroke['points'] !== []) {
        $strokes[] = $stroke;
    }
}

$room['stroke_count'] = count($strokes);

respond([
    'ok'      => true,
    'room'    => room_payload($room, $session),
    'strokes' => $strokes,
    'version' => (int) $room['version'],
]);
