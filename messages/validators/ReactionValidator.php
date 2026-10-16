<?php
declare(strict_types=1);

final class PCReactionValidator
{
    public static function validate(string $reaction): bool
    {
        if ($reaction === '' || preg_match('/[\x00-\x1F\x7F]/u', $reaction)) return false;
        return pc_len($reaction) <= 32;
    }
}
