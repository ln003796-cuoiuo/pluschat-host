<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (str_starts_with($path, '/api/v1/')) {
    require dirname(__DIR__) . '/api/v1/index.php';
    return true;
}
$file = dirname(__DIR__) . $path;
if ($path !== '/' && is_file($file)) return false;
http_response_code(404);
echo 'Not found';
return true;
