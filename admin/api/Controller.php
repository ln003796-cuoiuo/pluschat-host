<?php
declare(strict_types=1);

/*
 * Pluschat admin API routes.
 * Business logic remains in this feature module; shared HTTP/database helpers
 * are loaded by public/index.php through bootstrap.php.
 */
function pc_admin_dispatch(string $path, string $method): bool
{
    if ($path === '/api/v1/admin/reports' && $method === 'GET') {
            $user = pc_require_verified_user();
            $check = pc_db()->prepare('SELECT role FROM admin_users WHERE user_id = ? LIMIT 1');
            $check->execute([$user['id']]);
            $admin = $check->fetch();
            if (!$admin || !in_array($admin['role'], ['moderator', 'admin', 'owner'], true)) pc_json(403, ['ok' => false, 'error' => 'admin_required']);
            $stmt = pc_db()->query("SELECT id, reporter_id, target_user_id, message_id, reason, details, status, created_at FROM reports WHERE status IN ('open','reviewing') ORDER BY created_at ASC LIMIT 200");
            pc_json(200, ['ok' => true, 'reports' => $stmt->fetchAll()]);
        }

    if ($path === '/api/v1/reports' && $method === 'POST') {
            $user = pc_require_verified_user();
            $data = pc_input();
            $targetUserId = filter_var($data['target_user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $messageId = filter_var($data['message_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $reason = pc_string($data, 'reason');
            $details = pc_string($data, 'details');
            if (!in_array($reason, ['spam', 'harassment', 'scam', 'violence', 'other'], true) || pc_len($details) > 2000 || (!$targetUserId && !$messageId)) {
                pc_json(422, ['ok' => false, 'error' => 'invalid_report']);
            }
            if ($messageId) {
                $check = pc_db()->prepare('SELECT 1 FROM messages m JOIN conversation_members cm ON cm.conversation_id = m.conversation_id WHERE m.id = ? AND cm.user_id = ?');
                $check->execute([$messageId, $user['id']]);
                if (!$check->fetchColumn()) pc_json(404, ['ok' => false, 'error' => 'message_not_found']);
            }
            pc_db()->prepare('INSERT INTO reports (reporter_id, target_user_id, message_id, reason, details) VALUES (?, ?, ?, ?, ?)')
                ->execute([$user['id'], $targetUserId ?: null, $messageId ?: null, $reason, $details]);
            pc_json(201, ['ok' => true, 'report_submitted' => true]);
        }
    return false;
}
