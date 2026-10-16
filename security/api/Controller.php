<?php
declare(strict_types=1);

/*
 * Pluschat security API routes.
 * Business logic remains in this feature module; shared HTTP/database helpers
 * are loaded by public/index.php through bootstrap.php.
 */
function pc_security_dispatch(string $path, string $method): bool
{
    if ($path === '/api/v1/devices' && $method === 'GET') {
            $user = pc_require_verified_user();
            $stmt = pc_db()->prepare('SELECT id, device_name, platform, last_seen_at, created_at FROM user_devices WHERE user_id = ? AND revoked_at IS NULL ORDER BY last_seen_at DESC');
            $stmt->execute([$user['id']]);
            pc_json(200, ['ok' => true, 'devices' => $stmt->fetchAll()]);
        }

    if ($path === '/api/v1/auth/sessions/revoke-others' && $method === 'POST') {
            $user = pc_require_verified_user();
            $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            preg_match('/^Bearer\\s+([a-f0-9]{64})$/i', $header, $matches);
            $currentHash = hash('sha256', $matches[1]);
            $stmt = pc_db()->prepare('DELETE FROM auth_tokens WHERE user_id = ? AND token_hash <> ?');
            $stmt->execute([$user['id'], $currentHash]);
            pc_json(200, ['ok' => true, 'revoked_sessions' => $stmt->rowCount()]);
        }
    return false;
}
