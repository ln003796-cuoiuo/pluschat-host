<?php
declare(strict_types=1);

final class PCChatRepository
{
    public static function recipientExists(PDO $pdo, int $recipientId): bool
    {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? AND email_verified_at IS NOT NULL');
        $stmt->execute([$recipientId]);
        return (bool)$stmt->fetch();
    }

    public static function isBlocked(PDO $pdo, int $userId, int $recipientId): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM user_blocks WHERE (user_id = ? AND blocked_user_id = ?) OR (user_id = ? AND blocked_user_id = ?) LIMIT 1');
        $stmt->execute([$userId, $recipientId, $recipientId, $userId]);
        return (bool)$stmt->fetchColumn();
    }

    public static function findDirect(PDO $pdo, int $userId, int $recipientId): ?int
    {
        $stmt = $pdo->prepare(
            'SELECT c.id FROM conversations c
             JOIN conversation_members m1 ON m1.conversation_id = c.id AND m1.user_id = ?
             JOIN conversation_members m2 ON m2.conversation_id = c.id AND m2.user_id = ?
             WHERE (SELECT COUNT(*) FROM conversation_members mc WHERE mc.conversation_id = c.id) = 2
             ORDER BY c.id LIMIT 1'
        );
        $stmt->execute([$userId, $recipientId]);
        $row = $stmt->fetch();
        return $row ? (int)$row['id'] : null;
    }

    public static function createDirect(PDO $pdo, int $userId, int $recipientId): int
    {
        $pdo->beginTransaction();
        try {
            $pdo->exec('INSERT INTO conversations () VALUES ()');
            $conversationId = (int)$pdo->lastInsertId();
            $member = $pdo->prepare('INSERT INTO conversation_members (conversation_id, user_id) VALUES (?, ?)');
            $member->execute([$userId === $recipientId ? $conversationId : $conversationId, $userId]);
            $member->execute([$conversationId, $recipientId]);
            $pdo->commit();
            return $conversationId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
