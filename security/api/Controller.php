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

    if ($path === '/api/v1/security/keys' && $method === 'POST') {
        $user = pc_require_verified_user();
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\\s+([a-f0-9]{64})$/i', $header, $matches)) {
            pc_json(401, ['ok' => false, 'error' => 'authentication_required']);
        }
        $tokenStmt = pc_db()->prepare('SELECT device_key_hash FROM auth_tokens WHERE token_hash = ? AND user_id = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1');
        $tokenStmt->execute([hash('sha256', $matches[1]), $user['id']]);
        $deviceHash = $tokenStmt->fetchColumn();
        if (!is_string($deviceHash) || !preg_match('/^[a-f0-9]{64}$/', $deviceHash)) {
            pc_json(409, ['ok' => false, 'error' => 'device_binding_required']);
        }
        $data = pc_input();
        $decodeKey = static function (mixed $value): string|false {
            if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) return false;
            $raw = strtr($value, '-_', '+/');
            $raw .= str_repeat('=', (4 - strlen($raw) % 4) % 4);
            return base64_decode($raw, true);
        };
        $identity = $decodeKey($data['identity_public_key'] ?? null);
        $prekey = $decodeKey($data['signed_prekey'] ?? null);
        $signature = $decodeKey($data['signed_prekey_signature'] ?? null);
        if ($identity === false || strlen($identity) !== 32 || $prekey === false || strlen($prekey) !== 32 || $signature === false || strlen($signature) !== 64) {
            pc_json(422, ['ok' => false, 'error' => 'invalid_public_key_material']);
        }
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            pc_json(503, ['ok' => false, 'error' => 'server_crypto_extension_unavailable']);
        }
        if (!sodium_crypto_sign_verify_detached($signature, $prekey, $identity)) {
            pc_json(422, ['ok' => false, 'error' => 'invalid_signed_prekey_signature']);
        }
        $stmt = pc_db()->prepare(
            'INSERT INTO user_crypto_keys (user_id, device_key_hash, identity_public_key, signed_prekey, signed_prekey_signature, key_version)
             VALUES (?, ?, ?, ?, ?, \'pc-e2ee-v1\')
             ON DUPLICATE KEY UPDATE identity_public_key = VALUES(identity_public_key), signed_prekey = VALUES(signed_prekey), signed_prekey_signature = VALUES(signed_prekey_signature), key_version = VALUES(key_version), updated_at = UTC_TIMESTAMP()'
        );
        $stmt->execute([$user['id'], $deviceHash, $data['identity_public_key'], $data['signed_prekey'], $data['signed_prekey_signature']]);
        pc_json(201, ['ok' => true, 'registered' => true, 'device_key_id' => $deviceHash, 'key_version' => 'pc-e2ee-v1']);
    }

    if (preg_match('#^/api/v1/security/keys/([0-9]+)$#', $path, $matches) && $method === 'GET') {
        $user = pc_require_verified_user();
        $peerId = (int)$matches[1];
        if ($peerId < 1 || $peerId === (int)$user['id']) {
            pc_json(422, ['ok' => false, 'error' => 'invalid_peer_id']);
        }
        $pdo = pc_db();
        $allowed = $pdo->prepare(
            'SELECT 1 FROM conversations c
             JOIN conversation_members mine ON mine.conversation_id = c.id AND mine.user_id = ?
             JOIN conversation_members peer ON peer.conversation_id = c.id AND peer.user_id = ?
             WHERE (SELECT COUNT(*) FROM conversation_members all_members WHERE all_members.conversation_id = c.id) = 2 LIMIT 1'
        );
        $allowed->execute([$user['id'], $peerId]);
        if (!$allowed->fetchColumn()) pc_json(404, ['ok' => false, 'error' => 'user_not_found']);
        $blocked = $pdo->prepare('SELECT 1 FROM user_blocks WHERE (user_id = ? AND blocked_user_id = ?) OR (user_id = ? AND blocked_user_id = ?) LIMIT 1');
        $blocked->execute([$user['id'], $peerId, $peerId, $user['id']]);
        if ($blocked->fetchColumn()) pc_json(403, ['ok' => false, 'error' => 'key_exchange_not_allowed']);
        $stmt = $pdo->prepare(
            'SELECT device_key_hash AS key_id, identity_public_key, signed_prekey, signed_prekey_signature, key_version, updated_at
             FROM user_crypto_keys WHERE user_id = ? ORDER BY updated_at DESC LIMIT 20'
        );
        $stmt->execute([$peerId]);
        pc_json(200, ['ok' => true, 'keys' => $stmt->fetchAll()]);
    }

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
