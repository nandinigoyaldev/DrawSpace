<?php
/**
 * DrawSpace – board (room) lookups, validation and stroke encoding.
 *
 * A board's public id is an unguessable capability: anybody signed in who has
 * the link can draw on it. Only the owner can rename or clear it.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

const MAX_STROKES_PER_ROOM = 2500;
const MAX_POINTS_PER_STROKE = 1500;
const MAX_POINTS_PER_ROOM = 150000;
const MAX_ROOMS_PER_USER = 40;
const DEFAULT_ROOM_NAME = 'Untitled board';

/* ─────────────────────────────────── Rooms ───────────────────────────────── */

function room_public_id(): string
{
    return bin2hex(random_bytes(9)); // 18 lowercase hex characters
}

/** @return array<string, mixed> */
function find_room(PDO $pdo, string $publicId): array
{
    $statement = $pdo->prepare(
        'SELECT r.*, u.username AS owner_username
         FROM rooms r
         JOIN users u ON u.id = r.owner_id
         WHERE r.public_id = :public_id
         LIMIT 1'
    );
    $statement->execute([':public_id' => normalize_room_id($publicId)]);
    $room = $statement->fetch();

    if (!$room) {
        fail(404, 'That board does not exist or was deleted.');
    }

    return $room;
}

/** @return array<string, mixed> */
function find_owned_room(PDO $pdo, string $publicId, int $userId): array
{
    $room = find_room($pdo, $publicId);

    if ((int) $room['owner_id'] !== $userId) {
        fail(403, 'Only the board owner can do that.');
    }

    return $room;
}

function validate_room_name(mixed $raw): string
{
    if (!is_string($raw)) {
        fail(422, 'Please give the board a name.');
    }

    $name = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $raw) ?? '';
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

    if ($name === '') {
        return DEFAULT_ROOM_NAME;
    }

    if (mb_strlen($name) > 80) {
        fail(422, 'Board names can be at most 80 characters.');
    }

    return $name;
}

/** @return array<string, mixed> */
function create_room(PDO $pdo, int $ownerId, string $name): array
{
    $name = validate_room_name($name);
    $created = now();

    $countStatement = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE owner_id = :owner');
    $countStatement->execute([':owner' => $ownerId]);

    if ((int) $countStatement->fetchColumn() >= MAX_ROOMS_PER_USER) {
        fail(409, 'You have reached the maximum number of boards.');
    }

    for ($attempt = 0; $attempt < 4; $attempt++) {
        $publicId = room_public_id();

        try {
            $pdo->prepare(
                'INSERT INTO rooms (public_id, name, owner_id, version, point_count, created_at, updated_at)
                 VALUES (:public_id, :name, :owner, 0, 0, :created, :updated)'
            )->execute([
                ':public_id' => $publicId,
                ':name'      => $name,
                ':owner'     => $ownerId,
                ':created'   => $created,
                ':updated'   => $created,
            ]);

            return find_room($pdo, $publicId);
        } catch (PDOException $error) {
            if ($error->getCode() !== '23000') {
                throw $error;
            }
        }
    }

    fail(500, 'Could not create the board right now.');
}

/** @return array<string, mixed> */
function room_payload(array $room, array $session): array
{
    return [
        'id'          => (string) $room['public_id'],
        'name'        => (string) $room['name'],
        'owner'       => (string) $room['owner_username'],
        'isOwner'     => (int) $room['owner_id'] === (int) $session['user_id'],
        'version'     => (int) $room['version'],
        'strokeCount' => isset($room['stroke_count']) ? (int) $room['stroke_count'] : null,
        'createdAt'   => (int) $room['created_at'],
        'updatedAt'   => (int) $room['updated_at'],
    ];
}

/** @return list<array<string, mixed>> */
function rooms_for_user(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare(
        'SELECT r.*, u.username AS owner_username,
                (SELECT COUNT(*) FROM strokes s WHERE s.room_id = r.id) AS stroke_count
         FROM rooms r
         JOIN users u ON u.id = r.owner_id
         WHERE r.owner_id = :owner
         ORDER BY r.updated_at DESC, r.id DESC'
    );
    $statement->execute([':owner' => $userId]);

    $rooms = [];

    foreach ($statement->fetchAll() as $room) {
        $rooms[] = [
            'id'          => (string) $room['public_id'],
            'name'        => (string) $room['name'],
            'owner'       => (string) $room['owner_username'],
            'isOwner'     => true,
            'version'     => (int) $room['version'],
            'strokeCount' => (int) $room['stroke_count'],
            'createdAt'   => (int) $room['created_at'],
            'updatedAt'   => (int) $room['updated_at'],
        ];
    }

    return $rooms;
}

/* ────────────────────────────────── Strokes ──────────────────────────────── */

/**
 * Validate a stroke coming from the browser. Coordinates are normalised to
 * 0…1 so the same board renders correctly on every screen size.
 *
 * @param array<string, mixed> $raw
 * @return array{mode:string, color:string, width:int, points:list<array{0:float,1:float}>}
 */
function validate_stroke(array $raw): array
{
    $mode = $raw['mode'] ?? 'pen';

    if (!is_string($mode) || !in_array($mode, ['pen', 'eraser'], true)) {
        fail(422, 'Unknown drawing mode.');
    }

    $color = $raw['color'] ?? '#111827';

    if (!is_string($color) || !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        fail(422, 'Invalid colour.');
    }

    $width = filter_var($raw['width'] ?? 4, FILTER_VALIDATE_INT);

    if ($width === false || $width < 1 || $width > 64) {
        fail(422, 'Brush size must be between 1 and 64 pixels.');
    }

    $points = $raw['points'] ?? null;

    if (!is_array($points) || !array_is_list($points) || $points === []) {
        fail(422, 'A stroke needs at least one point.');
    }

    if (count($points) > MAX_POINTS_PER_STROKE) {
        fail(422, 'That stroke has too many points. Try a shorter line.');
    }

    $clean = [];

    foreach ($points as $point) {
        if (!is_array($point) || !array_is_list($point) || count($point) !== 2) {
            fail(422, 'Invalid stroke data.');
        }

        [$x, $y] = $point;

        if ((!is_int($x) && !is_float($x)) || (!is_int($y) && !is_float($y))) {
            fail(422, 'Invalid stroke data.');
        }

        $x = (float) $x;
        $y = (float) $y;

        if (!is_finite($x) || !is_finite($y)) {
            fail(422, 'Invalid stroke data.');
        }

        // Small tolerance so a pointer dragged just past the edge still works.
        if ($x < -0.01 || $x > 1.01 || $y < -0.01 || $y > 1.01) {
            fail(422, 'Stroke coordinates are out of bounds.');
        }

        $clean[] = [round(max(0.0, min(1.0, $x)), 4), round(max(0.0, min(1.0, $y)), 4)];
    }

    return [
        'mode'   => $mode,
        'color'  => strtolower($color),
        'width'  => (int) $width,
        'points' => $clean,
    ];
}

/** @return list<array{0:float,1:float}> */
function decode_points(string $json): array
{
    try {
        $points = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return [];
    }

    if (!is_array($points)) {
        return [];
    }

    $clean = [];

    foreach ($points as $point) {
        if (!is_array($point) || count($point) !== 2) {
            continue;
        }

        [$x, $y] = array_values($point);

        if (!is_int($x) && !is_float($x)) {
            continue;
        }

        if (!is_int($y) && !is_float($y)) {
            continue;
        }

        $x = (float) $x;
        $y = (float) $y;

        if (!is_finite($x) || !is_finite($y)) {
            continue;
        }

        $clean[] = [round(max(0.0, min(1.0, $x)), 4), round(max(0.0, min(1.0, $y)), 4)];

        if (count($clean) >= MAX_POINTS_PER_STROKE) {
            break;
        }
    }

    return $clean;
}

/** @return array<string, mixed> */
function stroke_payload(array $row, array $session): array
{
    return [
        'id'       => (int) $row['id'],
        'mode'     => (string) $row['mode'],
        'color'    => (string) $row['color'],
        'width'    => (int) $row['width'],
        'points'   => decode_points((string) $row['points']),
        'author'   => (int) $row['user_id'] === (int) $session['user_id'] ? 'you' : (string) $row['username'],
        'mine'     => (int) $row['user_id'] === (int) $session['user_id'],
        'createdAt' => (int) $row['created_at'],
    ];
}

/* ───────────────────────────────── Transactions ──────────────────────────── */

/**
 * @template T
 * @param callable(PDO):T $callback
 * @return T
 */
function with_transaction(PDO $pdo, callable $callback): mixed
{
    if ($pdo->inTransaction()) {
        return $callback($pdo);
    }

    $pdo->beginTransaction();

    try {
        $result = $callback($pdo);
        $pdo->commit();

        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }
}

function bump_room_version(PDO $pdo, int $roomId): int
{
    $pdo->prepare('UPDATE rooms SET version = version + 1, updated_at = :now WHERE id = :id')
        ->execute([':now' => now(), ':id' => $roomId]);

    $statement = $pdo->prepare('SELECT version FROM rooms WHERE id = :id');
    $statement->execute([':id' => $roomId]);

    return (int) $statement->fetchColumn();
}
