<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$email = $argv[1] ?? 'cict.dean@scheduleguard.test';
$stmt = db()->prepare('SELECT uid, apiToken FROM userProfile WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row || empty($row['apiToken'])) {
    fwrite(STDERR, "No token for {$email}\n");
    exit(1);
}

$token = (string) $row['apiToken'];
$base = 'http://127.0.0.1:8765/api';

function hit(string $url, string $token): void
{
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer {$token}\r\nAccept: application/json\r\n",
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $status = $http_response_header[0] ?? 'unknown';
    echo "{$url}\n  {$status}\n";
    if ($body === false) {
        echo "  (no body)\n";
        return;
    }
    $json = json_decode($body, true);
    if (!is_array($json)) {
        echo '  ' . substr($body, 0, 200) . "\n";
        return;
    }
    if (isset($json['success']) && !$json['success']) {
        echo '  error: ' . ($json['message'] ?? 'unknown') . "\n";
        return;
    }
    $data = $json['data'] ?? [];
    if (isset($data['faculty'])) {
        echo '  faculty: ' . count($data['faculty']) . "\n";
    }
    if (isset($data['rooms'])) {
        echo '  rooms: ' . count($data['rooms']) . "\n";
    }
    if (isset($data['schedules'])) {
        echo '  schedules: ' . count($data['schedules']) . "\n";
    }
}

foreach (['/faculty/index.php', '/rooms/index.php', '/schedules/faculty-view.php'] as $path) {
    hit($base . $path, $token);
}
