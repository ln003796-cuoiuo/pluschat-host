<?php
declare(strict_types=1);

final class PCNotificationValidator
{
    public static function limit(mixed $value): int
    {
        $limit = filter_var($value, FILTER_VALIDATE_INT);
        if ($limit === false) return 30;
        return max(1, min(100, (int)$limit));
    }

    public static function beforeId(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : (int)$id;
    }
}
