<?php
declare(strict_types=1);

/*
 * Pluschat api API routes.
 * Business logic remains in this feature module; shared HTTP/database helpers
 * are loaded by public/index.php through bootstrap.php.
 */
function pc_misc_api_dispatch(string $path, string $method): bool
{
    if (preg_match('#^/api/v1/conversations/([0-9]+)/read$#', $path, $matches) && $method === 'POST') {
            $user = pc_require_verified_user();
            $conversationId = (int)$matches[1];
            $member = pc_db()->prepare('SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ?');
            $member->execute([$conversationId, $user['id']]);
            if (!$member->fetchColumn()) pc_json(404, ['ok' => false, 'error' => 'conversation_not_found']);
            $stmt = pc_db()->prepare('INSERT INTO message_receipts (message_id, user_id, delivered_at, read_at) SELECT id, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM messages WHERE conversation_id = ? AND sender_id <> ? ON DUPLICATE KEY UPDATE delivered_at = COALESCE(delivered_at, VALUES(delivered_at)), read_at = VALUES(read_at)');
            $stmt->execute([$user['id'], $conversationId, $user['id']]);
            pc_json(200, ['ok' => true, 'marked_read' => $stmt->rowCount()]);
        }

    if (preg_match('#^/api/v1/conversations/(\d+)/messages$#', $path, $matches)) {
            $user = pc_require_verified_user();
            $conversationId = (int)$matches[1];
            $member = pc_db()->prepare('SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ?');
            $member->execute([$conversationId, $user['id']]);
            if (!$member->fetchColumn()) pc_json(404, ['ok' => false, 'error' => 'conversation_not_found']);
    
            if ($method === 'GET') {
                $before = filter_var($_GET['before'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $limit = filter_var($_GET['limit'] ?? 50, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]) ?: 50;
                if ($before) {
                    $stmt = pc_db()->prepare('SELECT id, sender_id, body, created_at, edited_at FROM messages WHERE conversation_id = ? AND id < ? ORDER BY id DESC LIMIT ' . (int)$limit);
                    $stmt->execute([$conversationId, $before]);
                } else {
                    $stmt = pc_db()->prepare('SELECT id, sender_id, body, created_at, edited_at FROM messages WHERE conversation_id = ? ORDER BY id DESC LIMIT ' . (int)$limit);
                    $stmt->execute([$conversationId]);
                }
                $messages = array_reverse($stmt->fetchAll());
                pc_json(200, ['ok' => true, 'messages' => array_map(static fn(array $m): array => [
                    'id' => (string)$m['id'], 'sender_id' => (string)$m['sender_id'], 'body' => $m['body'],
                    'created_at' => $m['created_at'], 'edited_at' => $m['edited_at'],
                ], $messages)]);
            }
    
            if ($method === 'POST') {
                $body = pc_string(pc_input(), 'body');
                if ($body === '' || pc_len($body) > 4000) pc_json(422, ['ok' => false, 'error' => 'message_must_be_1_to_4000_characters']);
                $stmt = pc_db()->prepare('INSERT INTO messages (conversation_id, sender_id, body) VALUES (?, ?, ?)');
                $stmt->execute([$conversationId, $user['id'], $body]);
                pc_json(201, ['ok' => true, 'message' => [
                    'id' => (string)pc_db()->lastInsertId(), 'conversation_id' => (string)$conversationId,
                    'sender_id' => (string)$user['id'], 'body' => $body, 'created_at' => gmdate('Y-m-d H:i:s'),
                ]]);
            }
            header('Allow: GET, POST');
            pc_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
        }

    if (preg_match('#^/api/v1/devices/([0-9]+)/revoke$#', $path, $matches) && $method === 'POST') {
            $user = pc_require_verified_user();
            $deviceId = (int)$matches[1];
            $stmt = pc_db()->prepare('SELECT device_key_hash FROM user_devices WHERE id = ? AND user_id = ? AND revoked_at IS NULL LIMIT 1');
            $stmt->execute([$deviceId, $user['id']]);
            $device = $stmt->fetch();
            if (!$device) pc_json(404, ['ok' => false, 'error' => 'device_not_found']);
            $pdo = pc_db();
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE user_devices SET revoked_at = UTC_TIMESTAMP() WHERE id = ? AND user_id = ?')->execute([$deviceId, $user['id']]);
                $pdo->prepare('DELETE FROM auth_tokens WHERE user_id = ? AND device_key_hash = ?')->execute([$user['id'], $device['device_key_hash']]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            pc_json(200, ['ok' => true, 'revoked' => true]);
        }

    if (preg_match('#^/api/v1/groups/([0-9]+)/messages$#', $path, $matches) && ($method === 'GET' || $method === 'POST')) {
            $user = pc_require_verified_user();
            $groupId = (int)$matches[1];
            $member = pc_db()->prepare('SELECT role FROM group_members WHERE group_id = ? AND user_id = ?');
            $member->execute([$groupId, $user['id']]);
            if (!$member->fetch()) pc_json(404, ['ok' => false, 'error' => 'group_not_found']);
            if ($method === 'GET') {
                $limit = filter_var($_GET['limit'] ?? 50, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]) ?: 50;
                $before = filter_var($_GET['before'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $sql = 'SELECT id, sender_id, body, body_encrypted, encryption_version, created_at, edited_at FROM group_messages WHERE group_id = ?';
                $params = [$groupId];
                if ($before) { $sql .= ' AND id < ?'; $params[] = $before; }
                $sql .= ' ORDER BY id DESC LIMIT ' . (int)$limit;
                $stmt = pc_db()->prepare($sql);
                $stmt->execute($params);
                pc_json(200, ['ok' => true, 'messages' => array_reverse($stmt->fetchAll())]);
            }
            $data = pc_input();
            $body = pc_string($data, 'body');
            $encrypted = filter_var($data['encrypted'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            $version = $encrypted ? (pc_string($data, 'encryption_version') ?: 'client-v1') : null;
            if ($body === '' || pc_len($body) > 4000 || ($encrypted && strlen($body) > 12000)) pc_json(422, ['ok' => false, 'error' => 'invalid_message_body']);
            pc_db()->prepare('INSERT INTO group_messages (group_id, sender_id, body, body_encrypted, encryption_version) VALUES (?, ?, ?, ?, ?)')->execute([$groupId, $user['id'], $body, $encrypted, $version]);
            pc_json(201, ['ok' => true, 'message' => ['id' => (string)pc_db()->lastInsertId(), 'group_id' => (string)$groupId, 'sender_id' => (string)$user['id'], 'body' => $body, 'encrypted' => (bool)$encrypted, 'encryption_version' => $version, 'created_at' => gmdate('Y-m-d H:i:s')]]);
        }

    if (preg_match('#^/api/v1/groups/([0-9]+)/leave$#', $path, $matches) && $method === 'POST') {
            $user = pc_require_verified_user();
            $groupId = (int)$matches[1];
            $stmt = pc_db()->prepare('SELECT role FROM group_members WHERE group_id = ? AND user_id = ?');
            $stmt->execute([$groupId, $user['id']]);
            $role = $stmt->fetchColumn();
            if ($role === false) pc_json(404, ['ok' => false, 'error' => 'group_not_found']);
            if ($role === 'owner') pc_json(409, ['ok' => false, 'error' => 'owner_must_transfer_or_delete_group']);
            pc_db()->prepare('DELETE FROM group_members WHERE group_id = ? AND user_id = ?')->execute([$groupId, $user['id']]);
            pc_json(200, ['ok' => true, 'left' => true]);
        }

    if (preg_match('#^/api/v1/groups/([0-9]+)/join$#', $path, $matches) && $method === 'POST') {
            $user = pc_require_verified_user();
            $groupId = (int)$matches[1];
            $data = pc_input();
            $invite = pc_string($data, 'invite_code');
            $stmt = pc_db()->prepare('SELECT id, is_private FROM chat_groups WHERE id = ? LIMIT 1');
            $stmt->execute([$groupId]);
            $group = $stmt->fetch();
            if (!$group) pc_json(404, ['ok' => false, 'error' => 'group_not_found']);
            if ((int)$group['is_private'] === 1) {
                $check = pc_db()->prepare('SELECT 1 FROM chat_groups WHERE id = ? AND invite_code = ?');
                $check->execute([$groupId, $invite]);
                if (!$check->fetchColumn()) pc_json(403, ['ok' => false, 'error' => 'valid_invite_required']);
            }
            pc_db()->prepare("INSERT IGNORE INTO group_members (group_id, user_id, role) VALUES (?, ?, 'member')")->execute([$groupId, $user['id']]);
            pc_json(200, ['ok' => true, 'joined' => true, 'group_id' => (string)$groupId]);
        }

    if (preg_match('#^/api/v1/groups/([0-9]+)/topics$#', $path, $matches) && ($method === 'GET' || $method === 'POST')) {
            $user = pc_require_verified_user();
            $groupId = (int)$matches[1];
            $member = pc_db()->prepare('SELECT g.topics_enabled, gm.role FROM chat_groups g JOIN group_members gm ON gm.group_id = g.id WHERE g.id = ? AND gm.user_id = ?');
            $member->execute([$groupId, $user['id']]);
            $group = $member->fetch();
            if (!$group) pc_json(404, ['ok' => false, 'error' => 'group_not_found']);
            if (!(int)$group['topics_enabled']) pc_json(409, ['ok' => false, 'error' => 'topics_disabled']);
            if ($method === 'POST') {
                if (!in_array($group['role'], ['owner', 'admin', 'moderator'], true)) pc_json(403, ['ok' => false, 'error' => 'insufficient_role']);
                $data = pc_input();
                $title = pc_string($data, 'title');
                $description = pc_string($data, 'description');
                if ($title === '' || pc_len($title) > 120 || pc_len($description) > 500) pc_json(422, ['ok' => false, 'error' => 'invalid_topic_fields']);
                pc_db()->prepare('INSERT INTO topics (group_id, creator_id, title, description) VALUES (?, ?, ?, ?)')->execute([$groupId, $user['id'], $title, $description]);
                pc_json(201, ['ok' => true, 'topic' => ['id' => (string)pc_db()->lastInsertId(), 'title' => $title, 'description' => $description]]);
            }
            $stmt = pc_db()->prepare('SELECT id, title, description, creator_id, created_at FROM topics WHERE group_id = ? ORDER BY id DESC LIMIT 200');
            $stmt->execute([$groupId]);
            pc_json(200, ['ok' => true, 'topics' => $stmt->fetchAll()]);
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

    if (preg_match('#^/api/v1/topics/([0-9]+)/messages$#', $path, $matches) && ($method === 'GET' || $method === 'POST')) {
            $user = pc_require_verified_user();
            $topicId = (int)$matches[1];
            $access = pc_db()->prepare('SELECT t.id, t.group_id, t.channel_id FROM topics t WHERE t.id = ? AND ((t.group_id IS NOT NULL AND EXISTS (SELECT 1 FROM group_members gm WHERE gm.group_id = t.group_id AND gm.user_id = ?)) OR (t.channel_id IS NOT NULL AND EXISTS (SELECT 1 FROM channel_members cm WHERE cm.channel_id = t.channel_id AND cm.user_id = ?)))');
            $access->execute([$topicId, $user['id'], $user['id']]);
            if (!$access->fetch()) pc_json(404, ['ok' => false, 'error' => 'topic_not_found']);
            if ($method === 'GET') {
                $limit = filter_var($_GET['limit'] ?? 50, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]) ?: 50;
                $before = filter_var($_GET['before'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $sql = 'SELECT id, sender_id, body, body_encrypted, created_at, edited_at FROM topic_messages WHERE topic_id = ?';
                $params = [$topicId];
                if ($before) { $sql .= ' AND id < ?'; $params[] = $before; }
                $sql .= ' ORDER BY id DESC LIMIT ' . (int)$limit;
                $stmt = pc_db()->prepare($sql);
                $stmt->execute($params);
                pc_json(200, ['ok' => true, 'messages' => array_reverse($stmt->fetchAll())]);
            }
            $data = pc_input();
            $body = pc_string($data, 'body');
            $encrypted = filter_var($data['encrypted'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            if ($body === '' || pc_len($body) > 4000) pc_json(422, ['ok' => false, 'error' => 'message_must_be_1_to_4000_characters']);
            pc_db()->prepare('INSERT INTO topic_messages (topic_id, sender_id, body, body_encrypted) VALUES (?, ?, ?, ?)')->execute([$topicId, $user['id'], $body, $encrypted]);
            pc_json(201, ['ok' => true, 'message' => ['id' => (string)pc_db()->lastInsertId(), 'topic_id' => (string)$topicId, 'sender_id' => (string)$user['id'], 'body' => $body, 'encrypted' => (bool)$encrypted, 'created_at' => gmdate('Y-m-d H:i:s')]]);
        }

    if (preg_match('#^/api/v1/users/([0-9]+)/block$#', $path, $matches) && $method === 'POST') {
            $user = pc_require_verified_user();
            $targetId = (int)$matches[1];
            if ($targetId < 1 || $targetId === (int)$user['id']) pc_json(422, ['ok' => false, 'error' => 'invalid_block_target']);
            $target = pc_db()->prepare('SELECT id FROM users WHERE id = ?');
            $target->execute([$targetId]);
            if (!$target->fetch()) pc_json(404, ['ok' => false, 'error' => 'user_not_found']);
            pc_db()->prepare('INSERT IGNORE INTO user_blocks (user_id, blocked_user_id) VALUES (?, ?)')->execute([$user['id'], $targetId]);
            pc_json(200, ['ok' => true, 'blocked' => true]);
        }

    if (preg_match('#^/api/v1/users/([0-9]+)/unblock$#', $path, $matches) && $method === 'POST') {
            $user = pc_require_verified_user();
            $targetId = (int)$matches[1];
            pc_db()->prepare('DELETE FROM user_blocks WHERE user_id = ? AND blocked_user_id = ?')->execute([$user['id'], $targetId]);
            pc_json(200, ['ok' => true, 'blocked' => false]);
        }

    if (preg_match('#^/api/v1/topics/([0-9]+)/convert-to-channel$#', $path, $matches) && $method === 'POST') {
            $user = pc_require_verified_user();
            $topicId = (int)$matches[1];
            $pdo = pc_db();
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('SELECT * FROM topics WHERE id = ? FOR UPDATE');
                $stmt->execute([$topicId]);
                $topic = $stmt->fetch();
                if (!$topic) {
                    $pdo->rollBack();
                    pc_json(404, ['ok' => false, 'error' => 'topic_not_found']);
                }
                $members = [];
                if ($topic['group_id'] !== null) {
                    $role = $pdo->prepare('SELECT role FROM group_members WHERE group_id = ? AND user_id = ?');
                    $role->execute([$topic['group_id'], $user['id']]);
                    $currentRole = $role->fetchColumn();
                    if (!in_array($currentRole, ['owner', 'admin'], true)) {
                        $pdo->rollBack();
                        pc_json(403, ['ok' => false, 'error' => 'insufficient_role']);
                    }
                    $m = $pdo->prepare('SELECT user_id FROM group_members WHERE group_id = ?');
                    $m->execute([$topic['group_id']]);
                    $members = array_map('intval', array_column($m->fetchAll(), 'user_id'));
                } elseif ($topic['channel_id'] !== null) {
                    $role = $pdo->prepare('SELECT role FROM channel_members WHERE channel_id = ? AND user_id = ?');
                    $role->execute([$topic['channel_id'], $user['id']]);
                    if (!in_array($role->fetchColumn(), ['owner', 'admin'], true)) {
                        $pdo->rollBack();
                        pc_json(403, ['ok' => false, 'error' => 'insufficient_role']);
                    }
                    $m = $pdo->prepare('SELECT user_id FROM channel_members WHERE channel_id = ?');
                    $m->execute([$topic['channel_id']]);
                    $members = array_map('intval', array_column($m->fetchAll(), 'user_id'));
                } else {
                    $pdo->rollBack();
                    pc_json(409, ['ok' => false, 'error' => 'topic_has_no_parent']);
                }
    
                $invite = bin2hex(random_bytes(16));
                $pdo->prepare('INSERT INTO channels (owner_id, title, description, is_public, invite_code, topics_enabled) VALUES (?, ?, ?, 0, ?, 1)')
                    ->execute([$user['id'], $topic['title'], $topic['description'], $invite]);
                $channelId = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO channel_members (channel_id, user_id, role) VALUES (?, ?, 'owner')")->execute([$channelId, $user['id']]);
                $addMember = $pdo->prepare("INSERT IGNORE INTO channel_members (channel_id, user_id, role) VALUES (?, ?, 'subscriber')");
                foreach ($members as $memberId) {
                    if ($memberId !== (int)$user['id']) $addMember->execute([$channelId, $memberId]);
                }
                $pdo->prepare('UPDATE topics SET group_id = NULL, channel_id = ? WHERE id = ?')->execute([$channelId, $topicId]);
                $pdo->commit();
                pc_json(201, ['ok' => true, 'converted' => true, 'channel' => ['id' => (string)$channelId, 'title' => $topic['title'], 'invite_code' => $invite]]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }

    if (preg_match('#^/api/v1/attachments/([0-9]+)$#', $path, $matches) && $method === 'GET') {
            $user = pc_require_verified_user();
            $attachmentId = (int)$matches[1];
            $stmt = pc_db()->prepare('SELECT a.storage_key, a.original_name, a.byte_size FROM message_attachments a JOIN messages m ON m.id = a.message_id JOIN conversation_members cm ON cm.conversation_id = m.conversation_id WHERE a.id = ? AND cm.user_id = ? LIMIT 1');
            $stmt->execute([$attachmentId, $user['id']]);
            $attachment = $stmt->fetch();
            if (!$attachment) pc_json(404, ['ok' => false, 'error' => 'attachment_not_found']);
            $pathOnDisk = dirname(__DIR__) . '/storage/uploads/' . basename((string)$attachment['storage_key']);
            if (!is_file($pathOnDisk)) pc_json(404, ['ok' => false, 'error' => 'attachment_file_missing']);
            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . (string)filesize($pathOnDisk));
            header('Content-Disposition: attachment; filename="encrypted.bin"');
            header('X-Content-Type-Options: nosniff');
            readfile($pathOnDisk);
            exit;
        }
    return false;
}
