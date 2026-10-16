<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/NotificationService.php';

function pc_notifications_dispatch(string $path, string $method): bool
{
    if ($path === '/api/v1/notifications' && $method === 'GET') {
        $user = pc_require_verified_user();
        pc_json(200, ['ok' => true, 'notifications' => PCNotificationService::list((int)$user['id'], $_GET)]);
    }

    if ($path === '/api/v1/notifications/read-all' && $method === 'POST') {
        $user = pc_require_verified_user();
        $count = PCNotificationRepository::markAllRead(pc_db(), (int)$user['id']);
        pc_json(200, ['ok' => true, 'marked_read' => $count]);
    }

    if (preg_match('#^/api/v1/notifications/([0-9]+)/read$#', $path, $matches) && $method === 'POST') {
        $user = pc_require_verified_user();
        $notificationId = (int)$matches[1];
        if ($notificationId < 1 || !PCNotificationRepository::markRead(pc_db(), (int)$user['id'], $notificationId)) {
            pc_json(404, ['ok' => false, 'error' => 'notification_not_found']);
        }
        pc_json(200, ['ok' => true, 'read' => true]);
    }

    return false;
}
