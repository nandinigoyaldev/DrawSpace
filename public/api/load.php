<?php

/**
 * DrawSpace · GET /api/load.php
 * Returns the current board state so polling clients can stay in sync.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

require_once __DIR__ . '/../../app/database.php';

try {
    $stmt = $pdo->query(
        'SELECT drawing_data FROM drawings WHERE id = 1'
    );

    $row = $stmt->fetch();
} catch (PDOException $e) {
    // Clients keep their current board on a 5xx instead of wiping it.
    error_log('[DrawSpace] load failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not load drawing.']);
    exit;
}

$drawing = [];

if (is_array($row) && is_string($row['drawing_data'])) {
    $decoded = json_decode($row['drawing_data'], true);

    if (isValidDrawingPayload($decoded)) {
        $drawing = $decoded;
    }
}

echo json_encode($drawing);

/** Guard against corrupt or legacy rows — always hand the client a strokes array. */
function isValidDrawingPayload(mixed $data): bool
{
    if (!is_array($data)) {
        return false;
    }

    foreach ($data as $stroke) {
        if (!is_array($stroke)) {
            return false;
        }

        foreach ($stroke as $point) {
            if (!is_array($point) || !isset($point['x'], $point['y'])) {
                return false;
            }
        }
    }

    return true;
}
