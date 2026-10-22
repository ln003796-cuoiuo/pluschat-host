<?php
declare(strict_types=1);

/*
 * Pluschat security API routes.
 * Business logic remains in this feature module; shared HTTP/database helpers
 * are loaded by public/index.php through bootstrap.php.
 */
require_once dirname(__DIR__) . '/services/DeviceService.php';
require_once dirname(__DIR__) . '/services/TwoFactorService.php';

function pc_security_scope_member(PDO $pdo, string $scopeType, int $scopeId, int $userId): bool
{
    $tables = ['conversation' => ['conversation_members', 'conversation_id'], 'group' => ['group_members', 'group_id'], 'channel' => ['channel_members', 'channel_id']];
    if (!isset($tables[$scopeType]) || $scopeId < 1 || $userId < 1) return false;
    [$table, $scopeColumn] = $tables[$scopeType];
    $sql = 'SELECT 1 FROM ' . $table . ' WHERE ' . $scopeColumn . ' = ? AND user_id = ? LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$scopeId, $userId]);
    return (bool)$stmt->fetchColumn();
}

function pc_security_current_device_hash(PDO $pdo, int $userId): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\\s+([a-f0-9]{64})$/i', $header, $matches)) return '';
    $stmt = $pdo->prepare('SELECT device_key_hash FROM auth_tokens WHERE token_hash = ? AND user_id = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1');
    $stmt->execute([hash('sha256', $matches[1]), $userId]);
    $hash = $stmt->fetchColumn();
    return is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash) ? $hash : '';
}


function pc_security_dispatch(string $path, string $method): bool
{
    if ($path === '/api/v1/auth/2fa/setup' && $method === 'POST') {
        pc_rate_limit('two-factor-setup', 5, 3600);
        $user = pc_token_user(true);
        $pdo = pc_db();
        if (PCTwoFactorService::isEnabled($pdo, (int)$user['id'])) {
            pc_json(409, ['ok' => false, 'error' => 'two_factor_already_enabled']);
        }
        $setup = PCTwoFactorService::beginSetup($pdo, (int)$user['id'], (string)($user['email'] ?? $user['username'] ?? ''));
        pc_json(200, ['ok' => true] + $setup);
    }

    if ($path === '/api/v1/auth/2fa/confirm' && $method === 'POST') {
        pc_rate_limit('two-factor-confirm', 10, 900);
        $user = pc_token_user(true);
        $data = pc_input();
        $setupId = pc_string($data, 'setup_id');
        $code = pc_string($data, 'code');
        if (!PCTwoFactorService::confirmSetup(pc_db(), (int)$user['id'], $setupId, $code)) {
            pc_json(400, ['ok' => false, 'error' => 'invalid_or_expired_two_factor_code', 'message' => 'Код неверный или истёк. Синхронизируй время на телефоне и попробуй снова.']);
        }
        $rotated = PCTwoFactorService::upgradeSetupToken(pc_db(), (int)$user['id'], $_SERVER['HTTP_AUTHORIZATION'] ?? '');
        pc_json(200, ['ok' => true, 'enabled' => true, 'message' => 'Двухэтапная аутентификация включена.'] + $rotated);
    }

    if ($path === '/api/v1/auth/2fa/verify' && $method === 'POST') {
        pc_rate_limit('two-factor-verify', 10, 900);
        $data = pc_input();
        $result = PCTwoFactorService::verifyChallenge(pc_db(), pc_string($data, 'challenge_id'), pc_string($data, 'code'));
        if ($result === null) {
            pc_json(401, ['ok' => false, 'error' => 'invalid_or_expired_two_factor_challenge', 'message' => 'Код неверный или запрос входа истёк. Войди заново.']);
        }
        pc_json(200, ['ok' => true] + $result);
    }

    if ($path === '/api/v1/auth/2fa/disable' && $method === 'POST') {
        $user = pc_require_verified_user();
        pc_rate_limit('two-factor-disable', 5, 900);
        $data = pc_input();
        $password = pc_string($data, 'password');
        $code = pc_string($data, 'code');
        if ($password === '' || !preg_match('/^[0-9]{6}$/', $code)) {
            pc_json(422, ['ok' => false, 'error' => 'password_and_current_code_required']);
        }
        $pdo = pc_db();
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$user['id']]);
        $passwordHash = $stmt->fetchColumn();
        if (!is_string($passwordHash) || !password_verify($password, $passwordHash)) {
            pc_json(403, ['ok' => false, 'error' => 'invalid_password']);
        }
        if (!PCTwoFactorService::isEnabled($pdo, (int)$user['id'])
            || !PCTwoFactorService::verifyCurrentCode($pdo, (int)$user['id'], $code)) {
            pc_json(403, ['ok' => false, 'error' => 'invalid_two_factor_code']);
        }
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\\s+([a-f0-9]{64})$/i', $header, $matches)) {
            pc_json(401, ['ok' => false, 'error' => 'authentication_required']);
        }
        $currentTokenHash = hash('sha256', $matches[1]);
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM user_two_factor WHERE user_id = ?')->execute([(int)$user['id']]);
            $pdo->prepare('DELETE FROM two_factor_setups WHERE user_id = ?')->execute([(int)$user['id']]);
            $pdo->prepare('DELETE FROM two_factor_challenges WHERE user_id = ?')->execute([(int)$user['id']]);
            $pdo->prepare('DELETE FROM auth_tokens WHERE user_id = ? AND token_hash <> ?')->execute([(int)$user['id'], $currentTokenHash]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        pc_json(200, ['ok' => true, 'enabled' => false, 'message' => 'Двухэтапная аутентификация отключена. Остальные сеансы завершены.']);
    }

    if ($path === '/api/v1/security/keys' && $method === 'POST') {
        $user = pc_require_verified_user();
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $matches)) {
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
        $pdo = pc_db();
        $pdo->beginTransaction();
        try {
            $existingStmt = $pdo->prepare('SELECT identity_public_key FROM user_crypto_keys WHERE user_id = ? AND device_key_hash = ? FOR UPDATE');
            $existingStmt->execute([$user['id'], $deviceHash]);
            $existingIdentity = $existingStmt->fetchColumn();
            if (is_string($existingIdentity) && !hash_equals($existingIdentity, $data['identity_public_key'])) {
                $pdo->rollBack();
                pc_json(409, ['ok' => false, 'error' => 'identity_key_change_requires_recovery']);
            }
            if (is_string($existingIdentity)) {
                $stmt = $pdo->prepare('UPDATE user_crypto_keys SET signed_prekey = ?, signed_prekey_signature = ?, key_version = \'pc-e2ee-v1\', updated_at = UTC_TIMESTAMP() WHERE user_id = ? AND device_key_hash = ?');
                $stmt->execute([$data['signed_prekey'], $data['signed_prekey_signature'], $user['id'], $deviceHash]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO user_crypto_keys (user_id, device_key_hash, identity_public_key, signed_prekey, signed_prekey_signature, key_version) VALUES (?, ?, ?, ?, ?, \'pc-e2ee-v1\')');
                $stmt->execute([$user['id'], $deviceHash, $data['identity_public_key'], $data['signed_prekey'], $data['signed_prekey_signature']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
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


    if ($path === '/api/v1/security/one-time-prekeys' && $method === 'POST') {
        $user = pc_require_verified_user();
        pc_rate_limit('one-time-prekey-publish', 20, 3600);
        if (!function_exists('sodium_crypto_sign_verify_detached')) pc_json(503, ['ok' => false, 'error' => 'server_crypto_extension_unavailable']);
        $pdo = pc_db();
        $deviceHash = pc_security_current_device_hash($pdo, (int)$user['id']);
        if ($deviceHash === '') pc_json(409, ['ok' => false, 'error' => 'device_binding_required']);
        $data = pc_input();
        $prekeys = $data['prekeys'] ?? null;
        if (!is_array($prekeys) || count($prekeys) < 1 || count($prekeys) > 100) pc_json(422, ['ok' => false, 'error' => 'invalid_prekey_batch']);
        $identityStmt = $pdo->prepare('SELECT identity_public_key FROM user_crypto_keys WHERE user_id = ? AND device_key_hash = ? LIMIT 1');
        $identityStmt->execute([$user['id'], $deviceHash]);
        $identityEncoded = $identityStmt->fetchColumn();
        $identity = is_string($identityEncoded) ? base64_decode(strtr($identityEncoded, '-_', '+/') . str_repeat('=', (4 - strlen($identityEncoded) % 4) % 4), true) : false;
        if ($identity === false || strlen($identity) !== 32) pc_json(409, ['ok' => false, 'error' => 'identity_key_not_registered']);
        $insert = $pdo->prepare('INSERT IGNORE INTO user_one_time_prekeys (user_id, device_key_hash, prekey_id, public_key, signature) VALUES (?, ?, ?, ?, ?)');
        $stored = 0;
        foreach ($prekeys as $item) {
            if (!is_array($item)) pc_json(422, ['ok' => false, 'error' => 'invalid_prekey']);
            $id = $item['id'] ?? null; $publicEncoded = $item['public_key'] ?? null; $signatureEncoded = $item['signature'] ?? null;
            if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,80}$/', $id) || !is_string($publicEncoded) || !preg_match('/^[A-Za-z0-9_-]+$/', $publicEncoded) || !is_string($signatureEncoded) || !preg_match('/^[A-Za-z0-9_-]+$/', $signatureEncoded)) pc_json(422, ['ok' => false, 'error' => 'invalid_prekey']);
            $public = base64_decode(strtr($publicEncoded, '-_', '+/') . str_repeat('=', (4 - strlen($publicEncoded) % 4) % 4), true);
            $signature = base64_decode(strtr($signatureEncoded, '-_', '+/') . str_repeat('=', (4 - strlen($signatureEncoded) % 4) % 4), true);
            if ($public === false || strlen($public) !== 32 || $signature === false || strlen($signature) !== 64 || !sodium_crypto_sign_verify_detached($signature, $public, $identity)) pc_json(422, ['ok' => false, 'error' => 'invalid_prekey_signature']);
            $insert->execute([$user['id'], $deviceHash, $id, $publicEncoded, $signatureEncoded]);
            $stored += $insert->rowCount();
        }
        pc_json(201, ['ok' => true, 'stored' => $stored]);
    }

    if (preg_match('#^/api/v1/security/one-time-prekey/([1-9][0-9]*)$#', $path, $matches) && $method === 'GET') {
        $user = pc_require_verified_user();
        $peerId = (int)$matches[1];
        if ($peerId === (int)$user['id']) pc_json(422, ['ok' => false, 'error' => 'invalid_peer_id']);
        $pdo = pc_db();
        $direct = $pdo->prepare('SELECT 1 FROM conversation_members a JOIN conversation_members b ON b.conversation_id = a.conversation_id WHERE a.user_id = ? AND b.user_id = ? AND (SELECT COUNT(*) FROM conversation_members all_members WHERE all_members.conversation_id = a.conversation_id) = 2 LIMIT 1');
        $direct->execute([$user['id'], $peerId]);
        if (!$direct->fetchColumn()) pc_json(404, ['ok' => false, 'error' => 'user_not_found']);
        $blocked = $pdo->prepare('SELECT 1 FROM user_blocks WHERE (user_id = ? AND blocked_user_id = ?) OR (user_id = ? AND blocked_user_id = ?) LIMIT 1');
        $blocked->execute([$user['id'], $peerId, $peerId, $user['id']]);
        if ($blocked->fetchColumn()) pc_json(403, ['ok' => false, 'error' => 'key_exchange_not_allowed']);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id, device_key_hash, prekey_id, public_key, signature FROM user_one_time_prekeys WHERE user_id = ? AND consumed_at IS NULL ORDER BY id LIMIT 1 FOR UPDATE');
            $stmt->execute([$peerId]);
            $oneTime = $stmt->fetch();
            if (!$oneTime) { $pdo->commit(); pc_json(200, ['ok' => true, 'one_time_prekey' => null]); }
            $pdo->prepare('UPDATE user_one_time_prekeys SET consumed_at = UTC_TIMESTAMP() WHERE id = ? AND consumed_at IS NULL')->execute([$oneTime['id']]);
            $keyStmt = $pdo->prepare('SELECT identity_public_key, signed_prekey, signed_prekey_signature, key_version FROM user_crypto_keys WHERE user_id = ? AND device_key_hash = ? LIMIT 1');
            $keyStmt->execute([$peerId, $oneTime['device_key_hash']]);
            $bundle = $keyStmt->fetch();
            $pdo->commit();
            pc_json(200, ['ok' => true, 'key_bundle' => $bundle ?: null, 'one_time_prekey' => $bundle ? ['id' => $oneTime['prekey_id'], 'public_key' => $oneTime['public_key'], 'signature' => $oneTime['signature'], 'device_key_id' => $oneTime['device_key_hash']] : null]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    if ($path === '/api/v1/security/key-envelopes' && $method === 'POST') {
        $user = pc_require_verified_user();
        pc_rate_limit('key-envelope-write', 120, 60);
        $pdo = pc_db();
        $data = pc_input();
        $scopeType = is_string($data['scope_type'] ?? null) ? $data['scope_type'] : '';
        $scopeId = filter_var($data['scope_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $recipientId = filter_var($data['recipient_user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $deviceHash = is_string($data['recipient_device_hash'] ?? null) ? strtolower($data['recipient_device_hash']) : '';
        $keyId = is_string($data['key_id'] ?? null) ? $data['key_id'] : '';
        $ciphertext = is_string($data['envelope_ciphertext'] ?? null) ? $data['envelope_ciphertext'] : '';
        if (!in_array($scopeType, ['conversation', 'group', 'channel'], true) || !$scopeId || !$recipientId || !preg_match('/^[a-f0-9]{64}$/', $deviceHash) || !preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $keyId) || strlen($ciphertext) < 32 || strlen($ciphertext) > 200000 || !preg_match('/^[A-Za-z0-9_=-]+$/', $ciphertext)) pc_json(422, ['ok' => false, 'error' => 'invalid_key_envelope']);
        if (!pc_security_scope_member($pdo, $scopeType, (int)$scopeId, (int)$user['id']) || !pc_security_scope_member($pdo, $scopeType, (int)$scopeId, (int)$recipientId)) pc_json(403, ['ok' => false, 'error' => 'key_envelope_scope_access_denied']);
        $device = $pdo->prepare('SELECT 1 FROM user_crypto_keys WHERE user_id = ? AND device_key_hash = ? LIMIT 1');
        $device->execute([$recipientId, $deviceHash]);
        if (!$device->fetchColumn()) pc_json(404, ['ok' => false, 'error' => 'recipient_device_key_not_found']);
        $stmt = $pdo->prepare('INSERT INTO encrypted_key_envelopes (scope_type, scope_id, recipient_user_id, recipient_device_hash, sender_user_id, key_id, envelope_ciphertext) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE sender_user_id = VALUES(sender_user_id), envelope_ciphertext = VALUES(envelope_ciphertext), created_at = UTC_TIMESTAMP()');
        $stmt->execute([$scopeType, $scopeId, $recipientId, $deviceHash, $user['id'], $keyId, $ciphertext]);
        pc_json(201, ['ok' => true, 'stored' => true]);
    }

    if ($path === '/api/v1/security/key-envelopes' && $method === 'GET') {
        $user = pc_require_verified_user();
        $pdo = pc_db();
        $deviceHash = pc_security_current_device_hash($pdo, (int)$user['id']);
        if ($deviceHash === '') pc_json(409, ['ok' => false, 'error' => 'device_binding_required']);
        $scopeType = is_string($_GET['scope_type'] ?? null) ? $_GET['scope_type'] : '';
        $scopeId = filter_var($_GET['scope_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!in_array($scopeType, ['conversation', 'group', 'channel'], true) || !$scopeId || !pc_security_scope_member($pdo, $scopeType, (int)$scopeId, (int)$user['id'])) pc_json(422, ['ok' => false, 'error' => 'invalid_key_envelope_scope']);
        $stmt = $pdo->prepare('SELECT key_id, algorithm, envelope_ciphertext, created_at FROM encrypted_key_envelopes WHERE scope_type = ? AND scope_id = ? AND recipient_user_id = ? AND recipient_device_hash = ? ORDER BY created_at DESC LIMIT 100');
        $stmt->execute([$scopeType, $scopeId, $user['id'], $deviceHash]);
        pc_json(200, ['ok' => true, 'envelopes' => $stmt->fetchAll()]);
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

    if ($path === '/api/v1/auth/sessions/revoke-all' && $method === 'POST') {
        // Authenticate before revoking: this intentionally invalidates the current token too.
        $user = pc_require_verified_user();
        $result = PCDeviceService::revokeAllSessions((int)$user['id']);
        pc_json($result['status'], $result['body']);
    }

    if (preg_match('#^/api/v1/devices/([0-9]+)/revoke$#', $path, $matches) && $method === 'POST') {
        $user = pc_require_verified_user();
        $result = PCDeviceService::revokeDevice((int)$user['id'], (int)$matches[1]);
        pc_json($result['status'], $result['body']);
    }

    return false;
}
