<?php
declare(strict_types=1);

final class PCUserValidator
{
    public static function searchQuery(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $query = trim($value);
        if (pc_len($query) < 2 || pc_len($query) > 64) return null;
        return $query;
    }

    public static function targetId(int $userId, int $targetId): bool
    {
        return $targetId > 0 && $targetId !== $userId;
    }
}
