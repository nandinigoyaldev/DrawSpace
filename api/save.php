<?php

/**
 * DrawSpace · POST /api/save.php
 * Persists the current board state.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function fail(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST, OPTIONS');
    fail(405, 'Method not allowed.');
}

$raw = file_get_contents('php://input');

if ($raw === false || trim($raw) === '') {
    fail(400, 'No drawing data received.');
}

if (strlen($raw) > 1_000_000) {
    fail(413, 'Drawing too large.');
}

$strokes = json_decode($raw, true);

if (!isValidDrawing($strokes)) {
    fail(400, 'Invalid drawing data.');
}

require_once __DIR__ . '/database.php';

try {
    $stmt = $pdo->prepare('UPDATE drawings SET drawing_data = :drawing_data WHERE id = 1');
    $stmt->execute([
        'drawing_data' => json_encode($strokes),
    ]);
} catch (PDOException $e) {
    error_log('[DrawSpace] Save failed: ' . $e->getMessage());
    $msg = ($appDebug ?? false) ? ('Could not save drawing: ' . $e->getMessage()) : 'Could not save drawing.';
    fail(500, $msg);
}

echo json_encode(['ok' => true]);

function isValidDrawing(mixed $data): bool
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

            if (!is_int($point['x']) && !is_float($point['x'])) {
                return false;
            }

            if (!is_int($point['y']) && !is_float($point['y'])) {
                return false;
            }
        }
    }

    return true;
}
