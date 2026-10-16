<?php
declare(strict_types=1);

final class PCLoginValidator
{
    public static function validate(array $data): array
    {
        $login = strtolower(is_string($data['login'] ?? null) ? trim($data['login']) : '');
        if ($login === '') {
            $login = strtolower(is_string($data['email'] ?? null) ? trim($data['email']) : '');
        }
        $password = is_string($data['password'] ?? null) ? $data['password'] : '';
        $deviceKey = is_string($data['device_key'] ?? null) ? trim($data['device_key']) : '';
        $deviceName = is_string($data['device_name'] ?? null) ? trim($data['device_name']) : '';
        $platform = is_string($data['platform'] ?? null) ? trim($data['platform']) : '';

        if ($deviceKey === '') {
            return ['error' => 'device_binding_required'];
        }
        if ($deviceKey !== '' && (
            !preg_match('/^[a-f0-9]{32,128}$/i', $deviceKey)
            || pc_len($deviceName ?: 'Unknown device') > 120
            || pc_len($platform ?: 'unknown') > 40
        )) {
            return ['error' => 'invalid_device_details'];
        }
        return [
            'login' => $login,
            'password' => $password,
            'device_key' => $deviceKey,
            'device_name' => $deviceName !== '' ? $deviceName : 'Unknown device',
            'platform' => $platform !== '' ? $platform : 'unknown',
        ];
    }
}
