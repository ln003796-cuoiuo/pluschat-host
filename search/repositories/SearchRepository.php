<?php
declare(strict_types=1);

final class PCSearchRepository
{
    private static function like(string $query): string
    {
        return '%' . addcslashes($query, '%_\\\\') . '%';
    }

    public static function users(PDO $pdo, int $viewerId, string $query): array
    {
        $like = self::like($query);
        $stmt = $pdo->prepare("SELECT u.id, u.username, u.first_name, u.last_name
            FROM users u LEFT JOIN user_settings s ON s.user_id = u.id
            WHERE u.id <> ? AND u.email_verified_at IS NOT NULL
              AND (u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)
              AND COALESCE(s.profile_visibility, 'everyone') <> 'nobody'
              AND (COALESCE(s.profile_visibility, 'everyone') <> 'contacts' OR EXISTS (
                  SELECT 1 FROM conversation_members mine
                  JOIN conversation_members theirs ON theirs.conversation_id = mine.conversation_id
                  WHERE mine.user_id = ? AND theirs.user_id = u.id
              ))
              AND NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.user_id = ? AND b.blocked_user_id = u.id) OR (b.user_id = u.id AND b.blocked_user_id = ?))
            ORDER BY u.username LIMIT 10");
        $stmt->execute([$viewerId, $like, $like, $like, $viewerId, $viewerId, $viewerId]);
        return array_map(static fn(array $u): array => [
            'id' => (string)$u['id'], 'username' => $u['username'],
            'first_name' => $u['first_name'], 'last_name' => $u['last_name'],
        ], $stmt->fetchAll());
    }

    public static function groups(PDO $pdo, string $query): array
    {
        $like = self::like($query);
        $stmt = $pdo->prepare("SELECT id, title, description FROM chat_groups
            WHERE group_kind = 'group' AND is_private = 0 AND (title LIKE ? OR description LIKE ?)
            ORDER BY id DESC LIMIT 10");
        $stmt->execute([$like, $like]);
        return array_map(static fn(array $row): array => [
            'id' => (string)$row['id'], 'title' => $row['title'], 'description' => $row['description'],
        ], $stmt->fetchAll());
    }

    public static function channels(PDO $pdo, string $query): array
    {
        $like = self::like($query);
        $stmt = $pdo->prepare('SELECT id, title, description FROM channels WHERE is_public = 1 AND (title LIKE ? OR description LIKE ?) ORDER BY id DESC LIMIT 10');
        $stmt->execute([$like, $like]);
        return array_map(static fn(array $row): array => [
            'id' => (string)$row['id'], 'title' => $row['title'], 'description' => $row['description'],
        ], $stmt->fetchAll());
    }
}
