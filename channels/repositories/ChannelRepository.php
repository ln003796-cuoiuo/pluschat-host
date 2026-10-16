<?php
declare(strict_types=1);

final class PCChannelRepository
{
    public static function create(PDO $pdo, int $ownerId, array $data): array
    {
        $inviteCode = bin2hex(random_bytes(16));
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO channels (owner_id, title, description, is_public, invite_code, topics_enabled) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$ownerId, $data['title'], $data['description'], $data['is_public'], $inviteCode, $data['topics_enabled']]);
            $channelId = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO channel_members (channel_id, user_id, role) VALUES (?, ?, 'owner')")->execute([$channelId, $ownerId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return [
            'id' => (string)$channelId,
            'title' => $data['title'],
            'description' => $data['description'],
            'invite_code' => $inviteCode,
            'is_public' => (bool)$data['is_public'],
            'topics_enabled' => (bool)$data['topics_enabled'],
        ];
    }
}
