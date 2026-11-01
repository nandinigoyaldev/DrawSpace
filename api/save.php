<?php
/**
 * POST /api/save – append one validated stroke to a board.
 */

declare(strict_types=1);

require_once __DIR__ . '/room_service.php';

require_method('POST');

$session = require_write_session();
$pdo = db();

$body = read_json_body(196608);
$roomId = normalize_room_id(is_string($body['room'] ?? null) ? $body['room'] : '');
$stroke = validate_stroke(is_array($body['stroke'] ?? null) ? $body['stroke'] : []);

$room = find_room($pdo, $roomId);
$userId = (int) $session['user_id'];

rate_limit($pdo, rate_limit_key('save', (string) $userId), 360, 60);

$pointsJson = json_encode($stroke['points'], JSON_THROW_ON_ERROR);
$pointCount = count($stroke['points']);

$result = with_transaction($pdo, static function (PDO $pdo) use ($room, $stroke, $userId, $pointsJson, $pointCount): array {
    $roomInternalId = (int) $room['id'];

    $countStatement = $pdo->prepare('SELECT COUNT(*) FROM strokes WHERE room_id = :room');
    $countStatement->execute([':room' => $roomInternalId]);

    if ((int) $countStatement->fetchColumn() >= MAX_STROKES_PER_ROOM) {
        fail(409, 'This board is full. Clear it or start a new one.');
    }

    $update = $pdo->prepare(
        'UPDATE rooms
         SET version = version + 1,
             updated_at = :now,
             point_count = point_count + :points
         WHERE id = :id AND point_count + :points2 <= :max_points'
    );
    $update->execute([
        ':now'        => now(),
        ':points'     => $pointCount,
        ':id'         => $roomInternalId,
        ':points2'    => $pointCount,
        ':max_points' => MAX_POINTS_PER_ROOM,
    ]);

    if ($update->rowCount() === 0) {
        fail(409, 'This board is full. Clear it or start a new one.');
    }

    $pdo->prepare(
        'INSERT INTO strokes (room_id, user_id, mode, color, width, points, created_at)
         VALUES (:room, :user, :mode, :color, :width, :points, :created)'
    )->execute([
        ':room'    => $roomInternalId,
        ':user'    => $userId,
        ':mode'    => $stroke['mode'],
        ':color'   => $stroke['color'],
        ':width'   => $stroke['width'],
        ':points'  => $pointsJson,
        ':created' => now(),
    ]);

    $strokeId = (int) $pdo->lastInsertId();

    $versionStatement = $pdo->prepare('SELECT version FROM rooms WHERE id = :id');
    $versionStatement->execute([':id' => $roomInternalId]);

    return [
        'id'      => $strokeId,
        'version' => (int) $versionStatement->fetchColumn(),
    ];
});

respond(['ok' => true, 'id' => $result['id'], 'version' => $result['version']], 201);
