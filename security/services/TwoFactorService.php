<?php
declare(strict_types=1);

final class PCTwoFactorService
{
    public static function isEnabled(PDO $pdo, int $userId): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM user_two_factor WHERE user_id = ? AND enabled_at IS NOT NULL LIMIT 1');
        $stmt->execute([$userId]);
        return (bool)$stmt->fetchColumn();
    }

    public static function beginSetup(PDO $pdo, int $userId, string $label): array
    {
        $secret = self::base32Encode(random_bytes(20));
        $setupId = bin2hex(random_bytes(32));
        $pdo->prepare('DELETE FROM two_factor_setups WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('INSERT INTO two_factor_setups (user_id, setup_id_hash, secret_ciphertext, expires_at) VALUES (?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE))')
            ->execute([$userId, hash('sha256', $setupId), self::encryptSecret($secret)]);
        $account = $label !== '' ? $label : 'Pluschat user ' . $userId;
        $url = 'otpauth://totp/' . rawurlencode('Pluschat:' . $account)
            . '?secret=' . rawurlencode($secret) . '&issuer=Pluschat&algorithm=SHA1&digits=6&period=30';
        return ['setup_id' => $setupId, 'secret' => $secret, 'otpauth_url' => $url, 'expires_in' => 900];
    }

    public static function confirmSetup(PDO $pdo, int $userId, string $setupId, string $code): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/i', $setupId) || !preg_match('/^[0-9]{6}$/', $code)) return false;
        $stmt = $pdo->prepare('SELECT id, secret_ciphertext FROM two_factor_setups WHERE user_id = ? AND setup_id_hash = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1');
        $stmt->execute([$userId, hash('sha256', $setupId)]);
        $setup = $stmt->fetch();
        if (!$setup) return false;
        $secret = self::decryptSecret((string)$setup['secret_ciphertext']);
        if (!self::verifyCode($secret, $code, time())) return false;
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO user_two_factor (user_id, secret_ciphertext, enabled_at) VALUES (?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE secret_ciphertext = VALUES(secret_ciphertext), enabled_at = UTC_TIMESTAMP()')
                ->execute([$userId, $setup['secret_ciphertext']]);
            $pdo->prepare('DELETE FROM two_factor_setups WHERE user_id = ?')->execute([$userId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return true;
    }

    public static function createChallenge(PDO $pdo, array $user, array $input): string
    {
        $challenge = bin2hex(random_bytes(32));
        $deviceHash = hash('sha256', (string)$input['device_key']);
        $pdo->prepare('DELETE FROM two_factor_challenges WHERE user_id = ?')->execute([(int)$user['id']]);
        $pdo->prepare('INSERT INTO two_factor_challenges (user_id, challenge_hash, device_key_hash, device_name, platform, expires_at) VALUES (?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE))')
            ->execute([(int)$user['id'], hash('sha256', $challenge), $deviceHash, (string)$input['device_name'], (string)$input['platform']]);
        return $challenge;
    }

    public static function verifyChallenge(PDO $pdo, string $challenge, string $code): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/i', $challenge) || !preg_match('/^[0-9]{6}$/', $code)) return null;
        $stmt = $pdo->prepare(
            'SELECT c.id AS challenge_row_id, c.user_id, c.device_key_hash, c.device_name, c.platform,
                    u.email, u.username, u.password_hash, u.first_name, u.last_name, u.birth_date,
                    u.email_verified_at, u.created_at, f.secret_ciphertext
             FROM two_factor_challenges c
             JOIN users u ON u.id = c.user_id
             JOIN user_two_factor f ON f.user_id = c.user_id AND f.enabled_at IS NOT NULL
             WHERE c.challenge_hash = ? AND c.expires_at > UTC_TIMESTAMP() LIMIT 1'
        );
        $stmt->execute([hash('sha256', $challenge)]);
        $record = $stmt->fetch();
        if (!$record || !self::verifyCode(self::decryptSecret((string)$record['secret_ciphertext']), $code, time())) return null;

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM two_factor_challenges WHERE id = ?')->execute([$record['challenge_row_id']]);
            $pdo->prepare('INSERT INTO user_devices (user_id, device_key_hash, device_name, platform, last_seen_at, revoked_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), NULL) ON DUPLICATE KEY UPDATE device_name = VALUES(device_name), platform = VALUES(platform), last_seen_at = UTC_TIMESTAMP(), revoked_at = NULL')
                ->execute([$record['user_id'], $record['device_key_hash'], $record['device_name'], $record['platform']]);
            $token = bin2hex(random_bytes(32));
            $expiresAt = gmdate('Y-m-d H:i:s', time() + 30 * 86400);
            $pdo->prepare('INSERT INTO auth_tokens (user_id, token_hash, device_key_hash, expires_at) VALUES (?, ?, ?, ?)')
                ->execute([$record['user_id'], hash('sha256', $token), $record['device_key_hash'], $expiresAt]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return [
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt,
            'user' => pc_public_user($record),
        ];
    }

    private static function verifyCode(string $secret, string $code, int $timestamp): bool
    {
        if (!preg_match('/^[0-9]{6}$/', $code)) return false;
        $key = self::base32Decode($secret);
        $counter = intdiv($timestamp, 30);
        for ($offset = -1; $offset <= 1; $offset++) {
            $step = $counter + $offset;
            $message = pack('N2', intdiv($step, 4294967296), $step % 4294967296);
            $hash = hash_hmac('sha1', $message, $key, true);
            $index = ord($hash[19]) & 0x0f;
            $binary = ((ord($hash[$index]) & 0x7f) << 24)
                | ((ord($hash[$index + 1]) & 0xff) << 16)
                | ((ord($hash[$index + 2]) & 0xff) << 8)
                | (ord($hash[$index + 3]) & 0xff);
            $expected = str_pad((string)($binary % 1000000), 6, '0', STR_PAD_LEFT);
            if (hash_equals($expected, $code)) return true;
        }
        return false;
    }

    private static function encryptSecret(string $secret): string
    {
        if (!function_exists('sodium_crypto_secretbox')) throw new RuntimeException('Sodium extension is required');
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = self::masterKey();
        return base64_encode($nonce . sodium_crypto_secretbox($secret, $nonce, $key));
    }

    private static function decryptSecret(string $encoded): string
    {
        if (!function_exists('sodium_crypto_secretbox_open')) throw new RuntimeException('Sodium extension is required');
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) throw new RuntimeException('Invalid encrypted 2FA secret');
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::masterKey());
        if ($plain === false) throw new RuntimeException('Cannot decrypt 2FA secret; server secret configuration changed');
        return $plain;
    }

    private static function masterKey(): string
    {
        $secret = (string)pc_config('app_secret', pc_config('db_password', ''));
        if ($secret === '') throw new RuntimeException('Pluschat application secret is not configured');
        return hash('sha256', 'Pluschat mandatory 2FA|' . $secret, true);
    }

    private static function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($data) as $char) $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        $out = '';
        foreach (str_split($bits, 5) as $chunk) $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
        return $out;
    }

    private static function base32Decode(string $value): string
    {
        $value = strtoupper(rtrim($value, '='));
        if ($value === '' || !preg_match('/^[A-Z2-7]+$/', $value)) throw new RuntimeException('Invalid TOTP secret');
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($value) as $char) $bits .= str_pad(decbin(strpos($alphabet, $char)), 5, '0', STR_PAD_LEFT);
        $out = '';
        foreach (str_split($bits, 8) as $chunk) if (strlen($chunk) === 8) $out .= chr(bindec($chunk));
        return $out;
    }
}
