<?php
declare(strict_types=1);

/*
 * Pluschat channels API routes.
 * Business logic remains in this feature module; shared HTTP/database helpers
 * are loaded by public/index.php through bootstrap.php.
 */
require_once dirname(__DIR__) . '/services/ChannelService.php';

function pc_channels_dispatch(string $path, string $method): bool
{
    if ($path === '/api/v1/channels' && ($method === 'GET' || $method === 'POST')) {
            $user = pc_require_verified_user();
            $pdo = pc_db();
            if ($method === 'POST') {
                $result = PCChannelService::create((int)$user['id'], pc_input());
                pc_json($result['status'], $result['body']);
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

    if (preg_match('#^/api/v1/channels/([0-9]+)/topics$#', $path, $matches) && ($method === 'GET' || $method === 'POST')) {
                $user = pc_require_verified_user();
                $channelId = (int)$matches[1];
                $member = pc_db()->prepare('SELECT c.topics_enabled, cm.role FROM channels c JOIN channel_members cm ON cm.channel_id = c.id WHERE c.id = ? AND cm.user_id = ?');
                $member->execute([$channelId, $user['id']]);
                $channel = $member->fetch();
                if (!$channel) pc_json(404, ['ok' => false, 'error' => 'channel_not_found']);
                if (!(int)$channel['topics_enabled']) pc_json(409, ['ok' => false, 'error' => 'topics_disabled']);
                if ($method === 'POST') {
                    if (!in_array($channel['role'], ['owner', 'admin'], true)) pc_json(403, ['ok' => false, 'error' => 'insufficient_role']);
                    $data = pc_input();
                    $title = pc_string($data, 'title');
                    $description = pc_string($data, 'description');
                    if ($title === '' || pc_len($title) > 120 || pc_len($description) > 500) pc_json(422, ['ok' => false, 'error' => 'invalid_topic_fields']);
                    pc_db()->prepare('INSERT INTO topics (channel_id, creator_id, title, description) VALUES (?, ?, ?, ?)')->execute([$channelId, $user['id'], $title, $description]);
                    pc_json(201, ['ok' => true, 'topic' => ['id' => (string)pc_db()->lastInsertId(), 'title' => $title, 'description' => $description]]);
                }
                $stmt = pc_db()->prepare('SELECT id, title, description, creator_id, created_at FROM topics WHERE channel_id = ? ORDER BY id DESC LIMIT 200');
                $stmt->execute([$channelId]);
                pc_json(200, ['ok' => true, 'topics' => $stmt->fetchAll()]);
            }

    if (preg_match('#^/api/v1/channels/([0-9]+)/subscribe$#', $path, $matches) && $method === 'POST') {
                $user = pc_require_verified_user();
                $channelId = (int)$matches[1];
                $data = pc_input();
                $invite = pc_string($data, 'invite_code');
                $stmt = pc_db()->prepare('SELECT id, is_public FROM channels WHERE id = ?');
                $stmt->execute([$channelId]);
                $channel = $stmt->fetch();
                if (!$channel) pc_json(404, ['ok' => false, 'error' => 'channel_not_found']);
                if (!(int)$channel['is_public']) {
                    $check = pc_db()->prepare('SELECT 1 FROM channels WHERE id = ? AND invite_code = ?');
                    $check->execute([$channelId, $invite]);
                    if (!$check->fetchColumn()) pc_json(403, ['ok' => false, 'error' => 'valid_invite_required']);
                }
                pc_db()->prepare("INSERT IGNORE INTO channel_members (channel_id, user_id, role) VALUES (?, ?, 'subscriber')")->execute([$channelId, $user['id']]);
                pc_json(200, ['ok' => true, 'subscribed' => true, 'channel_id' => (string)$channelId]);
            }

    if (preg_match('#^/api/v1/channels/([0-9]+)/posts$#', $path, $matches) && ($method === 'GET' || $method === 'POST')) {
                $user = pc_require_verified_user();
                $channelId = (int)$matches[1];
                $member = pc_db()->prepare('SELECT role FROM channel_members WHERE channel_id = ? AND user_id = ?');
                $member->execute([$channelId, $user['id']]);
                $role = $member->fetchColumn();
                if ($role === false) pc_json(404, ['ok' => false, 'error' => 'channel_not_found']);
                if ($method === 'GET') {
                    $limit = filter_var($_GET['limit'] ?? 50, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]) ?: 50;
                    $before = filter_var($_GET['before'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    $sql = 'SELECT id, sender_id, body, body_encrypted, encryption_version, created_at, edited_at FROM channel_posts WHERE channel_id = ?';
                    $params = [$channelId];
                    if ($before) { $sql .= ' AND id < ?'; $params[] = $before; }
                    $sql .= ' ORDER BY id DESC LIMIT ' . (int)$limit;
                    $stmt = pc_db()->prepare($sql);
                    $stmt->execute($params);
                    pc_json(200, ['ok' => true, 'posts' => array_reverse($stmt->fetchAll())]);
                }
                if (!in_array($role, ['owner', 'admin'], true)) pc_json(403, ['ok' => false, 'error' => 'channel_posting_restricted']);
                $data = pc_input();
                $body = pc_string($data, 'body');
                $encrypted = filter_var($data['encrypted'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                $version = $encrypted ? (pc_string($data, 'encryption_version') ?: 'client-v1') : null;
                if ($body === '' || pc_len($body) > 4000 || ($encrypted && strlen($body) > 12000)) pc_json(422, ['ok' => false, 'error' => 'invalid_post_body']);
                pc_db()->prepare('INSERT INTO channel_posts (channel_id, sender_id, body, body_encrypted, encryption_version) VALUES (?, ?, ?, ?, ?)')->execute([$channelId, $user['id'], $body, $encrypted, $version]);
                pc_json(201, ['ok' => true, 'post' => ['id' => (string)pc_db()->lastInsertId(), 'channel_id' => (string)$channelId, 'sender_id' => (string)$user['id'], 'body' => $body, 'encrypted' => (bool)$encrypted, 'encryption_version' => $version, 'created_at' => gmdate('Y-m-d H:i:s')]]);
            }
    return false;
}
