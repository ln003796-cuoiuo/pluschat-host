<?php
declare(strict_types=1);

final class PCReactionRepository
{
    public static function canReact(PDO $pdo, int $userId, int $messageId): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM messages m JOIN conversation_members cm ON cm.conversation_id = m.conversation_id WHERE m.id = ? AND cm.user_id = ? LIMIT 1');
        $stmt->execute([$messageId, $userId]);
        return (bool)$stmt->fetchColumn();
    }

    public static function listForMessage(PDO $pdo, int $userId, int $messageId): array
    {
        $stmt = $pdo->prepare(
            'SELECT reaction, COUNT(*) AS reaction_count,
                    MAX(CASE WHEN user_id = ? THEN 1 ELSE 0 END) AS reacted_by_me
             FROM message_reactions WHERE message_id = ?
             GROUP BY reaction ORDER BY reaction_count DESC, reaction ASC'
        );
        $stmt->execute([$userId, $messageId]);
        return array_map(static fn(array $row): array => [
            'reaction' => (string)$row['reaction'],
            'count' => (int)$row['reaction_count'],
            'reacted_by_me' => (bool)$row['reacted_by_me'],
        ], $stmt->fetchAll());
    }

    public static function add(PDO $pdo, int $userId, int $messageId, string $reaction): void
    {
        $stmt = $pdo->prepare('INSERT IGNORE INTO message_reactions (message_id, user_id, reaction) VALUES (?, ?, ?)');
        $stmt->execute([$messageId, $userId, $reaction]);
    }

    public static function remove(PDO $pdo, int $userId, int $messageId, string $reaction): int
    {
        $stmt = $pdo->prepare('DELETE FROM message_reactions WHERE message_id = ? AND user_id = ? AND reaction = ?');
        $stmt->execute([$messageId, $userId, $reaction]);
        return $stmt->rowCount();
    }
}
