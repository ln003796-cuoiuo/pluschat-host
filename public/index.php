<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'name' => 'Pluschat API',
    'status' => 'online',
    'api' => '/api/v1/',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
