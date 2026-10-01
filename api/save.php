<?php

/**
 * DrawSpace · POST /api/save.php
 * Persists the current board state (the full stroke list).
 */

declare(strict_types=1);

// CORS and response headers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight
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

/** Reject anything that isn't a well-formed array of strokes: [{x,y},...],[...]. */
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

/** Best-effort per-IP throttle. Fails open if temp files are unavailable. */
function allowSave(string $ip, int $max, int $windowSeconds): bool
{
    $file = sys_get_temp_dir() . '/drawspace_rate_' . sha1($ip);
    $fh   = @fopen($file, 'c+');

    if ($fh === false) {
        return true;
    }

    @flock($fh, LOCK_EX);

    $now  = time();
    $hits = [];

    foreach (explode("\n", (string) stream_get_contents($fh)) as $ts) {
        if (ctype_digit($ts) && (int) $ts > $now - $windowSeconds) {
            $hits[] = (int) $ts;
        }
    }

    if (count($hits) >= $max) {
        @flock($fh, LOCK_UN);
        fclose($fh);
        return false;
    }

    $hits[] = $now;

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, implode("\n", $hits));
    @flock($fh, LOCK_UN);
    fclose($fh);

    return true;
}

$ip = 'unknown';
if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
    $ip = trim($parts[0]);
} elseif (!empty($_SERVER['REMOTE_ADDR'])) {
    $ip = $_SERVER['REMOTE_ADDR'];
}

if (!allowSave($ip, 120, 60)) {
    header('Retry-After: 60');
    fail(429, 'Too many saves, slow down.');
}

$raw = file_get_contents('php://input');

if ($raw === false || trim($raw) === '') {
    fail(400, 'No drawing data received.');
}

// Hard cap so one client can't fill the column or the disk.
if (strlen($raw) > 1_000_000) {
    fail(413, 'Drawing too large.');
}

$strokes = json_decode($raw, true);

if (!isValidDrawing($strokes)) {
    fail(400, 'Invalid drawing data.');
}

if (file_exists(__DIR__ . '/database.php')) {
    require_once __DIR__ . '/database.php';
} else {
    require_once __DIR__ . '/../app/database.php';
}

try {
    // Store the validated, re-encoded payload — never the raw request body.
    $stmt = $pdo->prepare(
        'UPDATE drawings SET drawing_data = :drawing_data WHERE id = 1'
    );

    $stmt->execute([
        'drawing_data' => json_encode($strokes),
    ]);
} catch (PDOException $e) {
    error_log('[DrawSpace] save failed: ' . $e->getMessage());
    $msg = ($appDebug ?? false) ? ('Could not save drawing: ' . $e->getMessage()) : 'Could not save drawing.';
    fail(500, $msg);
}

echo json_encode(['ok' => true]);
