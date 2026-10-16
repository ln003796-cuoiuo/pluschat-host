<?php
declare(strict_types=1);

/*
 * Pluschat channels API routes.
 * Business logic remains in this feature module; shared HTTP/database helpers
 * are loaded by public/index.php through bootstrap.php.
 */
function pc_channels_dispatch(string $path, string $method): bool
{
    if ($path === '/api/v1/channels' && ($method === 'GET' || $method === 'POST')) {
            $user = pc_require_verified_user();
            $pdo = pc_db();
            if ($method === 'POST') {
                $data = pc_input();
                $title = pc_string($data, 'title');
                $description = pc_string($data, 'description');
                $isPublic = filter_var($data['is_public'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                $topicsEnabled = filter_var($data['topics_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                if ($title === '' || pc_len($title) > 120 || pc_len($description) > 1000) pc_json(422, ['ok' => false, 'error' => 'invalid_channel_fields']);
                $invite = bin2hex(random_bytes(16));
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('INSERT INTO channels (owner_id, title, description, is_public, invite_code, topics_enabled) VALUES (?, ?, ?, ?, ?, ?)')->execute([$user['id'], $title, $description, $isPublic, $invite, $topicsEnabled]);
                    $channelId = (int)$pdo->lastInsertId();
                    $pdo->prepare("INSERT INTO channel_members (channel_id, user_id, role) VALUES (?, ?, 'owner')")->execute([$channelId, $user['id']]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
                pc_json(201, ['ok' => true, 'channel' => ['id' => (string)$channelId, 'title' => $title, 'description' => $description, 'invite_code' => $invite, 'is_public' => (bool)$isPublic, 'topics_enabled' => (bool)$topicsEnabled]]);
            }
            $stmt = $pdo->prepare('SELECT c.id, c.title, c.description, c.is_public, c.topics_enabled, c.created_at, cm.role FROM channel_members cm JOIN channels c ON c.id = cm.channel_id WHERE cm.user_id = ? ORDER BY c.updated_at DESC LIMIT 100');
            $stmt->execute([$user['id']]);
            pc_json(200, ['ok' => true, 'channels' => $stmt->fetchAll()]);
        }

    if ($path === '/api/v1/channels/discover' && $method === 'GET') {
            $user = pc_require_verified_user();
            $query = trim((string)($_GET['q'] ?? ''));
            $like = '%' . addcslashes($query, '%_\\\\') . '%';
            $stmt = pc_db()->prepare('SELECT c.id, c.title, c.description, c.topics_enabled, (SELECT COUNT(*) FROM channel_members cm WHERE cm.channel_id = c.id) AS subscriber_count FROM channels c WHERE c.is_public = 1 AND (c.title LIKE ? OR c.description LIKE ?) AND NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.user_id = ? AND b.blocked_user_id = c.owner_id) OR (b.user_id = c.owner_id AND b.blocked_user_id = ?)) ORDER BY c.id DESC LIMIT 50');
            $stmt->execute([$like, $like, $user['id'], $user['id']]);
            pc_json(200, ['ok' => true, 'channels' => $stmt->fetchAll()]);
        }
    return false;
}
