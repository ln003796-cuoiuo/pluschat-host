<?php
declare(strict_types=1);

final class Config
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        return $value === false || $value === '' ? $default : $value;
    }

    public static function required(string $key): string
    {
        $value = self::get($key);
        if ($value === null) {
            throw new RuntimeException('Missing required server configuration: ' . $key);
        }
        return $value;
    }
}
