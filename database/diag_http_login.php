<?php

declare(strict_types=1);

$base = 'http://127.0.0.1:8765/api';
$email = $argv[1] ?? 'cict.dean@scheduleguard.test';
$password = 'Password123!';

function request(string $url, string $method = 'GET', ?array $body = null, ?string $token = null): array
{
    $headers = "Accept: application/json\r\n";
    if ($body !== null) {
        $headers .= "Content-Type: application/json\r\n";
    }
    if ($token) {
        $headers .= "Authorization: Bearer {$token}\r\n";
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => $headers,
            'content' => $body !== null ? json_encode($body) : null,
            'ignore_errors' => true,
        ],
    ]);
    $raw = file_get_contents($url, false, $ctx);
    $status = $http_response_header[0] ?? 'unknown';
    $json = json_decode((string) $raw, true);
    return ['status' => $status, 'json' => $json, 'raw' => $raw];
}

$login = request("{$base}/auth/login.php", 'POST', [
    'email' => $email,
    'password' => $password,
]);
echo "Login: {$login['status']}\n";
if (empty($login['json']['success'])) {
    echo ($login['json']['error'] ?? $login['raw']) . "\n";
    exit(1);
}
$token = (string) ($login['json']['data']['token'] ?? '');
echo "Token len: " . strlen($token) . "\n";

foreach (['/faculty/index.php', '/rooms/index.php', '/schedules/faculty-view.php'] as $path) {
    $res = request("{$base}{$path}", 'GET', null, $token);
    echo "\n{$path}\n  {$res['status']}\n";
    if (empty($res['json']['success'])) {
        echo '  error: ' . ($res['json']['error'] ?? $res['raw']) . "\n";
        continue;
    }
    $data = $res['json']['data'] ?? [];
    foreach (['faculty', 'rooms', 'schedules'] as $key) {
        if (isset($data[$key])) {
            echo "  {$key}: " . count($data[$key]) . "\n";
        }
    }
}
