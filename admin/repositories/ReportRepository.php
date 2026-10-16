<?php
declare(strict_types=1);

final class PCReportRepository
{
    public static function isModerator(PDO $pdo, int $userId): bool
    {
        $stmt = $pdo->prepare('SELECT role FROM admin_users WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row && in_array($row['role'], ['moderator', 'admin', 'owner'], true);
    }

    public static function canViewMessage(PDO $pdo, int $userId, int $messageId): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM messages m JOIN conversation_members cm ON cm.conversation_id = m.conversation_id WHERE m.id = ? AND cm.user_id = ? LIMIT 1');
        $stmt->execute([$messageId, $userId]);
        return (bool)$stmt->fetchColumn();
    }

    public static function create(PDO $pdo, int $reporterId, array $report): void
    {
        $pdo->prepare('INSERT INTO reports (reporter_id, target_user_id, message_id, reason, details) VALUES (?, ?, ?, ?, ?)')
            ->execute([$reporterId, $report['target_user_id'], $report['message_id'], $report['reason'], $report['details']]);
    }

    public static function listOpen(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT id, reporter_id, target_user_id, message_id, reason, details, status, created_at FROM reports WHERE status IN ('open','reviewing') ORDER BY created_at ASC LIMIT 200");
        return $stmt->fetchAll();
    }
}
