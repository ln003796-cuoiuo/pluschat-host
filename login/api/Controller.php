<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/LoginService.php';

function pc_login_dispatch(string $path, string $method): bool
{
    if ($path === '/api/v1/auth/login' && $method === 'POST') {
        $result = PCLoginService::login(pc_input());
        pc_json($result['status'], $result['body']);
    }

    if ($path === '/api/v1/auth/me' && $method === 'GET') {
        $user = pc_require_verified_user();
        $stmt = pc_db()->prepare('SELECT id, email, username, first_name, last_name, birth_date, email_verified_at, created_at FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        pc_json(200, ['ok' => true, 'user' => pc_public_user($stmt->fetch() ?: $user)]);
    }

    if ($path === '/api/v1/auth/logout' && $method === 'POST') {
        pc_token_user(true);
        PCLoginRepository::revokeBearer(pc_db(), $_SERVER['HTTP_AUTHORIZATION'] ?? '');
        pc_json(200, ['ok' => true, 'logged_out' => true]);
    }

    return false;
}
