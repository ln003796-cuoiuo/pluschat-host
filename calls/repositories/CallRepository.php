<?php
declare(strict_types=1);

final class PCCallRepository
{
    public static function findDirectConversation(PDO $pdo, int $userId, int $peerId): ?int
    {
        $stmt = $pdo->prepare(
            'SELECT c.id
             FROM conversations c
             JOIN conversation_members mine ON mine.conversation_id = c.id AND mine.user_id = ?
             JOIN conversation_members peer ON peer.conversation_id = c.id AND peer.user_id = ?
             WHERE (SELECT COUNT(*) FROM conversation_members all_members WHERE all_members.conversation_id = c.id) = 2
             LIMIT 1'
        );
        $stmt->execute([$userId, $peerId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public static function blocked(PDO $pdo, int $userId, int $peerId): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM user_blocks
             WHERE (user_id = ? AND blocked_user_id = ?)
                OR (user_id = ? AND blocked_user_id = ?) LIMIT 1'
        );
        $stmt->execute([$userId, $peerId, $peerId, $userId]);
        return (bool)$stmt->fetchColumn();
    }

    public static function create(PDO $pdo, int $conversationId, int $callerId, int $calleeId, string $type): int
    {
        $stmt = $pdo->prepare(
            "INSERT INTO calls (conversation_id, caller_id, callee_id, call_type, status, expires_at)
             VALUES (?, ?, ?, ?, 'ringing', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 60 SECOND))"
        );
        $stmt->execute([$conversationId, $callerId, $calleeId, $type]);
        return (int)$pdo->lastInsertId();
    }

    public static function visibleCall(PDO $pdo, int $callId, int $userId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, conversation_id, caller_id, callee_id, call_type, status, created_at, answered_at, ended_at, expires_at
             FROM calls WHERE id = ? AND (caller_id = ? OR callee_id = ?) LIMIT 1'
        );
        $stmt->execute([$callId, $userId, $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function addSignal(PDO $pdo, int $callId, int $senderId, int $recipientId, string $type, ?string $payload): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO call_signals (call_id, sender_id, recipient_id, signal_type, payload, expires_at)
             VALUES (?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 2 MINUTE))'
        );
        $stmt->execute([$callId, $senderId, $recipientId, $type, $payload]);
        return (int)$pdo->lastInsertId();
    }

    public static function signalsAfter(PDO $pdo, int $callId, int $recipientId, int $after): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, sender_id, signal_type, payload, created_at
             FROM call_signals
             WHERE call_id = ? AND recipient_id = ? AND id > ? AND expires_at > UTC_TIMESTAMP()
             ORDER BY id ASC LIMIT 100'
        );
        $stmt->execute([$callId, $recipientId, $after]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['payload'] = $row['payload'] === null ? null : json_decode((string)$row['payload'], true);
            $row['id'] = (string)$row['id'];
            $row['sender_id'] = (string)$row['sender_id'];
        }
        unset($row);
        return $rows;
    }

    public static function history(PDO $pdo, int $userId, int $limit = 50): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, conversation_id, caller_id, callee_id, call_type, status, created_at, answered_at, ended_at
             FROM calls WHERE caller_id = ? OR callee_id = ? ORDER BY id DESC LIMIT ' . max(1, min(100, $limit))
        );
        $stmt->execute([$userId, $userId]);
        return $stmt->fetchAll();
    }
}
