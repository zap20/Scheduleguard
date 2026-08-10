<?php

declare(strict_types=1);

function jsonResponse(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonSuccess(array $data = [], int $status = 200): never
{
    jsonResponse($status, ['success' => true, 'data' => $data]);
}

function jsonError(string $message, int $status = 400, array $extra = []): never
{
    jsonResponse($status, array_merge([
        'success' => false,
        'error' => $message,
    ], $extra));
}
