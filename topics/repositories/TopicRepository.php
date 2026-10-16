<?php
declare(strict_types=1);

final class PCTopicRepository
{
    public static function canAccess(PDO $pdo, int $userId, int $topicId): bool
    {
        $stmt = $pdo->prepare('SELECT t.id FROM topics t WHERE t.id = ? AND ((t.group_id IS NOT NULL AND EXISTS (SELECT 1 FROM group_members gm WHERE gm.group_id = t.group_id AND gm.user_id = ?)) OR (t.channel_id IS NOT NULL AND EXISTS (SELECT 1 FROM channel_members cm WHERE cm.channel_id = t.channel_id AND cm.user_id = ?)))');
        $stmt->execute([$topicId, $userId, $userId]);
        return (bool)$stmt->fetch();
    }

    public static function messages(PDO $pdo, int $topicId, int $limit, ?int $before): array
    {
        $sql = 'SELECT id, sender_id, body, body_encrypted, encryption_version, created_at, edited_at FROM topic_messages WHERE topic_id = ? AND body_encrypted = 1';
        $params = [$topicId];
        if ($before !== null) { $sql .= ' AND id < ?'; $params[] = $before; }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return array_reverse($stmt->fetchAll());
    }

    public static function createMessage(PDO $pdo, int $topicId, int $senderId, array $message): array
    {
        $stmt = $pdo->prepare('INSERT INTO topic_messages (topic_id, sender_id, body, body_encrypted, encryption_version) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$topicId, $senderId, $message['body'], $message['encrypted'], $message['encryption_version']]);
        return [
            'id' => (string)$pdo->lastInsertId(),
            'topic_id' => (string)$topicId,
            'sender_id' => (string)$senderId,
            'body' => $message['body'],
            'encrypted' => true,
            'encryption_version' => $message['encryption_version'],
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];
    }
}
