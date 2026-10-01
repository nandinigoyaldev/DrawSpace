<?php

/**
 * DrawSpace · GET /api/load.php
 * Returns the current board state.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET, OPTIONS');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

require_once __DIR__ . '/database.php';

try {
    $stmt = $pdo->query('SELECT drawing_data FROM drawings WHERE id = 1');
    $row = $stmt->fetch();
} catch (PDOException $e) {
    error_log('[DrawSpace] Load failed: ' . $e->getMessage());
    http_response_code(500);
    $msg = ($appDebug ?? false) ? ('Could not load drawing: ' . $e->getMessage()) : 'Could not load drawing.';
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$drawing = [];

if (is_array($row) && isset($row['drawing_data']) && is_string($row['drawing_data'])) {
    $decoded = json_decode($row['drawing_data'], true);
    if (isValidDrawingPayload($decoded)) {
        $drawing = $decoded;
    }
}

echo json_encode($drawing);

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
