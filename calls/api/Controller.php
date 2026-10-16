<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/CallService.php';

function pc_calls_dispatch(string $path, string $method): bool
{
    if ($path === '/api/v1/calls' && $method === 'GET') {
        $user = pc_require_verified_user();
        $limit = filter_var($_GET['limit'] ?? 50, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]) ?: 50;
        pc_json(200, ['ok' => true, 'calls' => PCCallRepository::history(pc_db(), (int)$user['id'], $limit)]);
    }

    if ($path === '/api/v1/calls' && $method === 'POST') {
        $user = pc_require_verified_user();
        $result = PCCallService::create((int)$user['id'], pc_input());
        pc_json($result['status'], $result['body']);
    }

    if (preg_match('#^/api/v1/calls/([0-9]+)$#', $path, $matches) && $method === 'GET') {
        $user = pc_require_verified_user();
        $result = PCCallService::poll((int)$user['id'], (int)$matches[1], $_GET['after'] ?? 0);
        pc_json($result['status'], $result['body']);
    }

    if (preg_match('#^/api/v1/calls/([0-9]+)/signals$#', $path, $matches) && $method === 'POST') {
        $user = pc_require_verified_user();
        $result = PCCallService::signal((int)$user['id'], (int)$matches[1], pc_input());
        pc_json($result['status'], $result['body']);
    }

    return false;
}
