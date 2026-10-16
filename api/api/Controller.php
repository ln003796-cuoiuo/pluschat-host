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

    
    return false;
}
