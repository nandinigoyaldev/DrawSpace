<?php
/**
 * POST /api/clear – erase every stroke on a board (owner only).
 */

declare(strict_types=1);

require_once __DIR__ . '/room_service.php';

require_method('POST');

$session = require_write_session();
$pdo = db();

$body = read_json_body(4096);
$roomId = normalize_room_id(is_string($body['room'] ?? null) ? $body['room'] : '');

$room = find_owned_room($pdo, $roomId, (int) $session['user_id']);

rate_limit($pdo, rate_limit_key('clear', (string) $session['user_id']), 10, 60);

$version = with_transaction($pdo, static function (PDO $pdo) use ($room): int {
    $roomInternalId = (int) $room['id'];

    $pdo->prepare('DELETE FROM strokes WHERE room_id = :room')->execute([':room' => $roomInternalId]);

    $pdo->prepare(
        'UPDATE rooms SET point_count = 0, version = version + 1, updated_at = :now WHERE id = :id'
    )->execute([':now' => now(), ':id' => $roomInternalId]);

    $versionStatement = $pdo->prepare('SELECT version FROM rooms WHERE id = :id');
    $versionStatement->execute([':id' => $roomInternalId]);

    return (int) $versionStatement->fetchColumn();
});

respond(['ok' => true, 'version' => $version]);
