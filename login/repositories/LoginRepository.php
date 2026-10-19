<?php
declare(strict_types=1);

final class PCLoginRepository
{
    public static function findByLogin(PDO $pdo, string $login): ?array
    {
        $stmt = $pdo->prepare('SELECT id, email, username, password_hash, first_name, last_name, birth_date, email_verified_at, phone, phone_verified_at, created_at FROM users WHERE email = ? OR username = ? LIMIT 1');
        $stmt->execute([$login, $login]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function issueToken(PDO $pdo, array $user, array $input, int $ttlSeconds = 2592000): array
    {
        $token = bin2hex(random_bytes(32));
        $ttlSeconds = max(60, min(2592000, $ttlSeconds));
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . $ttlSeconds . ' seconds')->format('Y-m-d H:i:s');
        $deviceHash = null;
        if ($input['device_key'] !== '') {
            $deviceHash = hash('sha256', $input['device_key']);
            $pdo->prepare('INSERT INTO user_devices (user_id, device_key_hash, device_name, platform, last_seen_at, revoked_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), NULL) ON DUPLICATE KEY UPDATE device_name = VALUES(device_name), platform = VALUES(platform), last_seen_at = UTC_TIMESTAMP(), revoked_at = NULL')
                ->execute([$user['id'], $deviceHash, $input['device_name'], $input['platform']]);
        }
        $pdo->prepare('INSERT INTO auth_tokens (user_id, token_hash, device_key_hash, expires_at) VALUES (?, ?, ?, ?)')
            ->execute([$user['id'], hash('sha256', $token), $deviceHash, $expiresAt]);
        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    public static function revokeBearer(PDO $pdo, string $header): void
    {
        if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $matches)) {
            pc_json(401, ['ok' => false, 'error' => 'invalid_token']);
        }
        $pdo->prepare('DELETE FROM auth_tokens WHERE token_hash = ?')->execute([hash('sha256', $matches[1])]);
    }
}
