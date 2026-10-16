<?php
declare(strict_types=1);

/*
 * Pluschat users API routes.
 * Business logic remains in this feature module; shared HTTP/database helpers
 * are loaded by public/index.php through bootstrap.php.
 */
require_once dirname(__DIR__) . '/services/UserService.php';

function pc_users_dispatch(string $path, string $method): bool
{
    if ($path === '/api/v1/users/search' && $method === 'GET') {
        $user = pc_require_verified_user();
        $result = PCUserService::search((int)$user['id'], $_GET['q'] ?? null);
        pc_json($result['status'], $result['body']);
    }

    if (preg_match('#^/api/v1/users/([0-9]+)/block$#', $path, $matches) && $method === 'POST') {
        $user = pc_require_verified_user();
        $result = PCUserService::block((int)$user['id'], (int)$matches[1]);
        pc_json($result['status'], $result['body']);
    }

    if (preg_match('#^/api/v1/users/([0-9]+)/unblock$#', $path, $matches) && $method === 'POST') {
        $user = pc_require_verified_user();
        $result = PCUserService::unblock((int)$user['id'], (int)$matches[1]);
        pc_json($result['status'], $result['body']);
    }

    return false;
}
