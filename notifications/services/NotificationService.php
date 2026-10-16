<?php
declare(strict_types=1);

require_once __DIR__ . '/../validators/NotificationValidator.php';
require_once __DIR__ . '/../repositories/NotificationRepository.php';

final class PCNotificationService
{
    public static function list(int $userId, array $query): array
    {
        $limit = PCNotificationValidator::limit($query['limit'] ?? 30);
        $beforeId = PCNotificationValidator::beforeId($query['before_id'] ?? null);
        return PCNotificationRepository::listForUser(pc_db(), $userId, $limit, $beforeId);
    }
}
