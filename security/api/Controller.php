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

    if (preg_match('#^/api/v1/devices/([0-9]+)/revoke$#', $path, $matches) && $method === 'POST') {
                $user = pc_require_verified_user();
                $deviceId = (int)$matches[1];
                $stmt = pc_db()->prepare('SELECT device_key_hash FROM user_devices WHERE id = ? AND user_id = ? AND revoked_at IS NULL LIMIT 1');
                $stmt->execute([$deviceId, $user['id']]);
                $device = $stmt->fetch();
                if (!$device) pc_json(404, ['ok' => false, 'error' => 'device_not_found']);
                $pdo = pc_db();
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('UPDATE user_devices SET revoked_at = UTC_TIMESTAMP() WHERE id = ? AND user_id = ?')->execute([$deviceId, $user['id']]);
                    $pdo->prepare('DELETE FROM auth_tokens WHERE user_id = ? AND device_key_hash = ?')->execute([$user['id'], $device['device_key_hash']]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
                pc_json(200, ['ok' => true, 'revoked' => true]);
            }
    return false;
}
