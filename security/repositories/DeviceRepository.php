<?php
declare(strict_types=1);

final class PCDeviceRepository
{
    public static function listForUser(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare('SELECT id, device_name, platform, last_seen_at, created_at FROM user_devices WHERE user_id = ? AND revoked_at IS NULL ORDER BY last_seen_at DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public static function revokeOtherSessions(PDO $pdo, int $userId, string $currentTokenHash): int
    {
        $stmt = $pdo->prepare('DELETE FROM auth_tokens WHERE user_id = ? AND token_hash <> ?');
        $stmt->execute([$userId, $currentTokenHash]);
        return $stmt->rowCount();
    }

    public static function revokeDevice(PDO $pdo, int $userId, int $deviceId): bool
    {
        $stmt = $pdo->prepare('SELECT device_key_hash FROM user_devices WHERE id = ? AND user_id = ? AND revoked_at IS NULL LIMIT 1');
        $stmt->execute([$deviceId, $userId]);
        $device = $stmt->fetch();
        if (!$device) return false;

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE user_devices SET revoked_at = UTC_TIMESTAMP() WHERE id = ? AND user_id = ?')->execute([$deviceId, $userId]);
            if (!empty($device['device_key_hash'])) {
                $pdo->prepare('DELETE FROM auth_tokens WHERE user_id = ? AND device_key_hash = ?')->execute([$userId, $device['device_key_hash']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return true;
    }
}
