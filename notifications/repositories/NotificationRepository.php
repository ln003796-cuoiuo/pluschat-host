<?php
declare(strict_types=1);

final class PCNotificationRepository
{
    public static function listForUser(PDO $pdo, int $userId, int $limit, ?int $beforeId): array
    {
        $sql = 'SELECT id, kind, payload, read_at, created_at FROM notifications WHERE user_id = ?';
        $params = [$userId];
        if ($beforeId !== null) {
            $sql .= ' AND id < ?';
            $params[] = $beforeId;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return array_map(static function (array $row): array {
            $payload = null;
            if (is_string($row['payload'] ?? null) && $row['payload'] !== '') {
                $decoded = json_decode($row['payload'], true);
                $payload = is_array($decoded) ? $decoded : null;
            }
            return [
                'id' => (string)$row['id'],
                'kind' => (string)$row['kind'],
                'payload' => $payload,
                'read_at' => $row['read_at'],
                'created_at' => $row['created_at'],
            ];
        }, $stmt->fetchAll());
    }

    public static function markRead(PDO $pdo, int $userId, int $notificationId): bool
    {
        $stmt = $pdo->prepare('UPDATE notifications SET read_at = COALESCE(read_at, UTC_TIMESTAMP()) WHERE id = ? AND user_id = ?');
        $stmt->execute([$notificationId, $userId]);
        if ($stmt->rowCount() > 0) return true;
        $check = $pdo->prepare('SELECT 1 FROM notifications WHERE id = ? AND user_id = ? LIMIT 1');
        $check->execute([$notificationId, $userId]);
        return (bool)$check->fetchColumn();
    }

    public static function markAllRead(PDO $pdo, int $userId): int
    {
        $stmt = $pdo->prepare('UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE user_id = ? AND read_at IS NULL');
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }
}
