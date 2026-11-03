<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function app_url(): string
{
    $configured = rtrim((string) env('APP_URL', ''), '/');

    if ($configured !== '') {
        return $configured;
    }

    return (is_https() ? 'https://' : 'http://') . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function send_password_reset_email(string $recipient, string $token): void
{
    $apiKey = env('RESEND_API_KEY');
    $from = env('MAIL_FROM');

    if ($apiKey === null || $from === null) {
        throw new RuntimeException('Password recovery email is not configured.');
    }

    $resetUrl = app_url() . '/reset?token=' . rawurlencode($token);
    $payload = json_encode([
        'from' => $from,
        'to' => [$recipient],
        'subject' => 'Reset your DrawSpace password',
        'text' => "Use this link to reset your DrawSpace password:\n\n{$resetUrl}\n\nThis link expires in 30 minutes and can only be used once.",
        'html' => '<p>Use this link to reset your DrawSpace password:</p><p><a href="' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '">Reset my password</a></p><p>This link expires in 30 minutes and can only be used once.</p>',
    ], JSON_THROW_ON_ERROR);

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Authorization: Bearer {$apiKey}\r\nContent-Type: application/json\r\n",
            'content' => $payload,
            'ignore_errors' => true,
            'timeout' => 8,
        ],
    ]);
    $response = file_get_contents('https://api.resend.com/emails', false, $context);
    $responseHeaders = function_exists('http_get_last_response_headers')
        ? http_get_last_response_headers()
        : [];

    if ($response === false || !isset($responseHeaders[0]) || !str_contains($responseHeaders[0], ' 2')) {
        throw new RuntimeException('Resend rejected the password recovery email.');
    }
}
