<?php
declare(strict_types=1);

final class PCChatValidator
{
    public static function recipientId(array $data, int $currentUserId): ?int
    {
        $id = filter_var($data['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || $id === null || (int)$id === $currentUserId) return null;
        return (int)$id;
    }
}
