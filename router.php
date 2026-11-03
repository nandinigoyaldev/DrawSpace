<?php
/**
 * Router for PHP's built-in server. Not used in production.
 *
 *   php -S 127.0.0.1:8000 router.php
 *
 * Mirrors the clean URLs configured for Vercel/Apache: `/` and `/login` serve
 * the static pages, `/css`, `/js`, `/assets` serve the frontend, and
 * `/api/<name>` maps to the PHP endpoints.
 */

declare(strict_types=1);

$uri = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$uri = is_string($uri) ? rawurldecode($uri) : '/';

function serve_file(string $path, string $mime, bool $isHtml = false): bool
{
    if (!is_file($path)) {
        return false;
    }

    header_remove('X-Powered-By');
    header('Content-Type: ' . $mime . ($isHtml ? '; charset=utf-8' : ''));
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

    if ($isHtml) {
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
        header('Cache-Control: no-store');
    }

    readfile($path);

    return true;
}

if ($uri === '/' || $uri === '/index.html') {
    serve_file(__DIR__ . '/app/index.html', 'text/html', true);
    return true;
}

if ($uri === '/login' || $uri === '/login.html') {
    serve_file(__DIR__ . '/app/login.html', 'text/html', true);
    return true;
}

if ($uri === '/reset' || $uri === '/reset.html') {
    serve_file(__DIR__ . '/app/reset.html', 'text/html', true);
    return true;
}

if ($uri === '/demo' || $uri === '/demo.html') {
    serve_file(__DIR__ . '/app/demo.html', 'text/html', true);
    return true;
}

if ($uri === '/favicon.ico' || $uri === '/favicon.svg') {
    serve_file(__DIR__ . '/app/assets/favicon.svg', 'image/svg+xml');
    return true;
}

if (preg_match('#^/(css|js|assets)/([A-Za-z0-9._-]+)$#', $uri, $matches) === 1) {
    $mime = match (pathinfo($matches[2], PATHINFO_EXTENSION)) {
        'css'   => 'text/css',
        'js'    => 'text/javascript',
        'svg'   => 'image/svg+xml',
        'png'   => 'image/png',
        'webp'  => 'image/webp',
        'woff2' => 'font/woff2',
        default => 'application/octet-stream',
    };

    serve_file(__DIR__ . '/app/' . $matches[1] . '/' . $matches[2], $mime);
    return true;
}

if (preg_match('#^/api/([a-z][a-z0-9-]*)(?:\.php)?$#', $uri, $matches) === 1) {
    $endpoint = __DIR__ . '/api/' . $matches[1] . '.php';

    if (is_file($endpoint)) {
        require $endpoint;
        return true;
    }
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not found';
return true;
