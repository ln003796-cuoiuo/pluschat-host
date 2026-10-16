<?php
declare(strict_types=1);

final class PCAttachmentRepository
{
    public static function canAttach(PDO $pdo, int $userId, int $messageId): bool
    {
        $stmt = $pdo->prepare('SELECT m.id FROM messages m JOIN conversation_members cm ON cm.conversation_id = m.conversation_id WHERE m.id = ? AND cm.user_id = ? AND m.sender_id = ? LIMIT 1');
        $stmt->execute([$messageId, $userId, $userId]);
        return (bool)$stmt->fetch();
    }

    public static function create(PDO $pdo, int $messageId, string $storageKey, string $name, string $mime, int $size, string $sha256): string
    {
        $stmt = $pdo->prepare("INSERT INTO message_attachments (message_id, storage_key, original_name, mime_type, byte_size, sha256, encryption_version) VALUES (?, ?, ?, ?, ?, ?, 'pc-e2ee-v1')");
        $stmt->execute([$messageId, $storageKey, $name, $mime, $size, $sha256]);
        return (string)$pdo->lastInsertId();
    }

    public static function findForUser(PDO $pdo, int $userId, int $attachmentId): ?array
    {
        $stmt = $pdo->prepare('SELECT a.storage_key, a.original_name, a.byte_size FROM message_attachments a JOIN messages m ON m.id = a.message_id JOIN conversation_members cm ON cm.conversation_id = m.conversation_id WHERE a.id = ? AND cm.user_id = ? AND NOT EXISTS (SELECT 1 FROM message_hidden h WHERE h.message_id = m.id AND h.user_id = cm.user_id) LIMIT 1');
        $stmt->execute([$attachmentId, $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
