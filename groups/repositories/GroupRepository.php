<?php
declare(strict_types=1);

final class PCGroupRepository
{
    public static function create(PDO $pdo, int $ownerId, array $data): array
    {
        $inviteCode = bin2hex(random_bytes(16));
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO chat_groups (owner_id, title, description, group_kind, is_private, invite_code, topics_enabled) VALUES (?, ?, ?, 'group', ?, ?, ?)");
            $stmt->execute([$ownerId, $data['title'], $data['description'], $data['is_private'], $inviteCode, $data['topics_enabled']]);
            $groupId = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, 'owner')")->execute([$groupId, $ownerId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return [
            'id' => (string)$groupId,
            'title' => $data['title'],
            'description' => $data['description'],
            'invite_code' => $inviteCode,
            'is_private' => (bool)$data['is_private'],
            'topics_enabled' => (bool)$data['topics_enabled'],
        ];
    }
}
