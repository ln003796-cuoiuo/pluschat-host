<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/SearchService.php';

function pc_search_dispatch(string $path, string $method): bool
{
    if ($path === '/api/v1/search' && $method === 'GET') {
        $user = pc_require_verified_user();
        $result = PCSearchService::search((int)$user['id'], $_GET['q'] ?? null);
        pc_json($result['status'], $result['body']);
    }
    return false;
}
