<?php
declare(strict_types=1);

/*
 * Pluschat admin API routes.
 * Business logic remains in this feature module; shared HTTP/database helpers
 * are loaded by public/index.php through bootstrap.php.
 */
require_once dirname(__DIR__) . '/services/ReportService.php';

function pc_admin_dispatch(string $path, string $method): bool
{
    if ($path === '/api/v1/admin/reports' && $method === 'GET') {
        $user = pc_require_verified_user();
        $result = PCReportService::listOpen((int)$user['id']);
        pc_json($result['status'], $result['body']);
    }

    if ($path === '/api/v1/reports' && $method === 'POST') {
        $user = pc_require_verified_user();
        $result = PCReportService::submit((int)$user['id'], pc_input());
        pc_json($result['status'], $result['body']);
    }
    return false;
}
