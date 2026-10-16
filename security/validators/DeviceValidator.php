<?php
declare(strict_types=1);

final class PCDeviceValidator
{
    public static function validId(int $id): bool
    {
        return $id > 0;
    }
}
