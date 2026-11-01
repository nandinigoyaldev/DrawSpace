<?php
/**
 * POST /api/undo – remove the current user's most recent stroke on a board.
 */

declare(strict_types=1);

require_once __DIR__ . '/room_service.php';

require_method('POST');

$session = require_write_session();
$pdo = db();

$body = read_json_body(4096);
$roomId = normalize_room_id(is_string($body['room'] ?? null) ? $body['room'] : '');

$room = find_room($pdo, $roomId);
$userId = (int) $session['user_id'];

rate_limit($pdo, rate_limit_key('undo', (string) $userId), 120, 60);

$result = with_transaction($pdo, static function (PDO $pdo) use ($room, $userId): array {
    $roomInternalId = (int) $room['id'];

    $statement = $pdo->prepare(
        'SELECT id, points FROM strokes
         WHERE room_id = :room AND user_id = :user
         ORDER BY id DESC LIMIT 1'
    );
    $statement->execute([':room' => $roomInternalId, ':user' => $userId]);
    $stroke = $statement->fetch();

    if (!$stroke) {
        $versionStatement = $pdo->prepare('SELECT version FROM rooms WHERE id = :id');
        $versionStatement->execute([':id' => $roomInternalId]);

        return ['removed' => false, 'version' => (int) $versionStatement->fetchColumn()];
    }

    $points = count(decode_points((string) $stroke['points']));

    $delete = $pdo->prepare('DELETE FROM strokes WHERE id = :id AND user_id = :user');
    $delete->execute([':id' => (int) $stroke['id'], ':user' => $userId]);

    // A concurrent undo may have removed the same row already. Only adjust the
    // point counter when this request actually deleted something.
    if ($delete->rowCount() === 0) {
        $versionStatement = $pdo->prepare('SELECT version FROM rooms WHERE id = :id');
        $versionStatement->execute([':id' => $roomInternalId]);

        return ['removed' => false, 'version' => (int) $versionStatement->fetchColumn()];
    }

    $countStatement = $pdo->prepare('SELECT point_count FROM rooms WHERE id = :id');
    $countStatement->execute([':id' => $roomInternalId]);
    $remaining = max(0, (int) $countStatement->fetchColumn() - $points);

    $pdo->prepare(
        'UPDATE rooms SET point_count = :points, version = version + 1, updated_at = :now WHERE id = :id'
    )->execute([':points' => $remaining, ':now' => now(), ':id' => $roomInternalId]);

    $versionStatement = $pdo->prepare('SELECT version FROM rooms WHERE id = :id');
    $versionStatement->execute([':id' => $roomInternalId]);

    return ['removed' => true, 'version' => (int) $versionStatement->fetchColumn()];
});

respond([
    'ok'      => true,
    'removed' => $result['removed'],
    'version' => $result['version'],
]);
