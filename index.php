<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/api/v1' || str_starts_with($path, '/api/v1/')) {
    require __DIR__ . '/api/v1/index.php';
    exit;
}

if ($path === '/' || $path === '/index.php') {
    http_response_code(200);
    echo json_encode([
        'service' => 'Pluschat main server',
        'status' => 'entrypoint_available',
        'health' => '/api/v1/health',
        'api_version' => 1,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(404);
echo json_encode([
    'error' => [
        'code' => 'not_found',
        'message' => 'Маршрут не найден.',
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
