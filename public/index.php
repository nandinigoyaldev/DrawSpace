<?php

// ── Security headers ─────────────────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header(
    "Content-Security-Policy: default-src 'self'; " .
    "img-src 'self' data:; style-src 'self'; script-src 'self'; " .
    "connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'"
);

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Draw Space</title>

    <link rel="stylesheet" href="css/style.css">
</head>

<body>

    <header>
        <h1>Draw Space</h1>
        <p>Collaborative Drawing Space</p>
    </header>

    <main>
        <canvas id="drawingCanvas"></canvas>
    </main>

    <script src="js/draw.js"></script>

</body>
</html>
