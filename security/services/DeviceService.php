<?php
declare(strict_types=1);

require_once __DIR__ . '/../validators/DeviceValidator.php';
require_once __DIR__ . '/../repositories/DeviceRepository.php';

final class PCDeviceService
{
    public static function list(int $userId): array
    {
        return PCDeviceRepository::listForUser(pc_db(), $userId);
    }

    public static function revokeOtherSessions(int $userId, string $authorization): array
    {
        if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $authorization, $matches)) {
            return ['status' => 401, 'body' => ['ok' => false, 'error' => 'invalid_token']];
        }
        $count = PCDeviceRepository::revokeOtherSessions(pc_db(), $userId, hash('sha256', $matches[1]));
        return ['status' => 200, 'body' => ['ok' => true, 'revoked_sessions' => $count]];
    }

    public static function revokeAllSessions(int $userId): array
    {
        $count = PCDeviceRepository::revokeAllSessions(pc_db(), $userId);
        return [
            'status' => 200,
            'body' => [
                'ok' => true,
                'revoked_sessions' => $count,
                'logged_out_everywhere' => true,
                'message' => 'Все сеансы завершены. Для продолжения войдите снова.',
            ],
        ];
    }

    public static function revokeDevice(int $userId, int $deviceId): array
    {
        if (!PCDeviceValidator::validId($deviceId)) {
            return ['status' => 422, 'body' => ['ok' => false, 'error' => 'invalid_device_id']];
        }
        if (!PCDeviceRepository::revokeDevice(pc_db(), $userId, $deviceId)) {
            return ['status' => 404, 'body' => ['ok' => false, 'error' => 'device_not_found']];
        }
        return ['status' => 200, 'body' => ['ok' => true, 'revoked' => true]];
    }
}
