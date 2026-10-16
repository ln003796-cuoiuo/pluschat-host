<?php
declare(strict_types=1);

/*
 * Pluschat security API routes.
 * Business logic remains in this feature module; shared HTTP/database helpers
 * are loaded by public/index.php through bootstrap.php.
 */
require_once dirname(__DIR__) . '/services/DeviceService.php';

function pc_security_dispatch(string $path, string $method): bool
{
    if ($path === '/api/v1/devices' && $method === 'GET') {
        $user = pc_require_verified_user();
        pc_json(200, ['ok' => true, 'devices' => PCDeviceService::list((int)$user['id'])]);
    }

    if ($path === '/api/v1/auth/sessions/revoke-others' && $method === 'POST') {
        $user = pc_require_verified_user();
        $result = PCDeviceService::revokeOtherSessions((int)$user['id'], $_SERVER['HTTP_AUTHORIZATION'] ?? '');
        pc_json($result['status'], $result['body']);
    }

    if (preg_match('#^/api/v1/devices/([0-9]+)/revoke$#', $path, $matches) && $method === 'POST') {
        $user = pc_require_verified_user();
        $result = PCDeviceService::revokeDevice((int)$user['id'], (int)$matches[1]);
        pc_json($result['status'], $result['body']);
    }

    return false;
}
