<?php
declare(strict_types=1);

final class PCUserRepository
{
    public static function search(PDO $pdo, int $viewerId, string $query): array
    {
        $like = '%' . addcslashes($query, '%_\\\\') . '%';
        $stmt = $pdo->prepare("SELECT u.id, u.username, u.first_name, u.last_name
            FROM users u LEFT JOIN user_settings s ON s.user_id = u.id
            WHERE u.id <> ? AND (u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)
              AND COALESCE(s.profile_visibility, 'everyone') <> 'nobody'
              AND (COALESCE(s.profile_visibility, 'everyone') <> 'contacts' OR EXISTS (
                  SELECT 1 FROM conversation_members mine
                  JOIN conversation_members theirs ON theirs.conversation_id = mine.conversation_id
                  WHERE mine.user_id = ? AND theirs.user_id = u.id
              ))
              AND NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.user_id = ? AND b.blocked_user_id = u.id) OR (b.user_id = u.id AND b.blocked_user_id = ?))
            ORDER BY u.username LIMIT 30");
        $stmt->execute([$viewerId, $like, $like, $like, $viewerId, $viewerId, $viewerId]);
        return array_map(static fn(array $u): array => [
            'id' => (string)$u['id'],
            'username' => $u['username'],
            'first_name' => $u['first_name'],
            'last_name' => $u['last_name'],
        ], $stmt->fetchAll());
    }

    public static function exists(PDO $pdo, int $userId): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        return (bool)$stmt->fetchColumn();
    }

    public static function block(PDO $pdo, int $userId, int $targetId): void
    {
        $pdo->prepare('INSERT IGNORE INTO user_blocks (user_id, blocked_user_id) VALUES (?, ?)')->execute([$userId, $targetId]);
    }

    public static function unblock(PDO $pdo, int $userId, int $targetId): void
    {
        $pdo->prepare('DELETE FROM user_blocks WHERE user_id = ? AND blocked_user_id = ?')->execute([$userId, $targetId]);
    }
}
