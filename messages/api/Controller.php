<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/ReactionService.php';

/* Conversation message history and read receipts. */
function pc_messages_dispatch(string $path, string $method): bool
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
                        $stmt = pc_db()->prepare('SELECT m.id, m.sender_id, m.body, m.body_encrypted, m.encryption_version, m.created_at, m.edited_at FROM messages m WHERE m.conversation_id = ? AND m.body_encrypted = 1 AND m.id < ? AND NOT EXISTS (SELECT 1 FROM message_hidden h WHERE h.message_id = m.id AND h.user_id = ?) ORDER BY m.id DESC LIMIT ' . (int)$limit);
                        $stmt->execute([$conversationId, $before, $user['id']]);
                    } else {
                        $stmt = pc_db()->prepare('SELECT m.id, m.sender_id, m.body, m.body_encrypted, m.encryption_version, m.created_at, m.edited_at FROM messages m WHERE m.conversation_id = ? AND m.body_encrypted = 1 AND NOT EXISTS (SELECT 1 FROM message_hidden h WHERE h.message_id = m.id AND h.user_id = ?) ORDER BY m.id DESC LIMIT ' . (int)$limit);
                        $stmt->execute([$conversationId, $user['id']]);
                    }
                    $messages = array_reverse($stmt->fetchAll());
                    pc_json(200, ['ok' => true, 'messages' => array_map(static fn(array $m): array => [
                        'id' => (string)$m['id'], 'sender_id' => (string)$m['sender_id'], 'body' => $m['body'],
                        'encrypted' => true, 'encryption_version' => $m['encryption_version'],
                        'created_at' => $m['created_at'], 'edited_at' => $m['edited_at'],
                    ], $messages)]);
                }
        
                if ($method === 'POST') {
                    pc_rate_limit('message-send', 120, 60);
                    $data = pc_input();
                    $envelope = pc_encrypted_envelope($data['body'] ?? null);
                    if (!$envelope) pc_json(422, ['ok' => false, 'error' => 'encrypted_message_envelope_required', 'format' => 'pc-e2ee-v1']);
                    $direct = pc_db()->prepare('SELECT COUNT(*) FROM conversation_members WHERE conversation_id = ?');
                    $direct->execute([$conversationId]);
                    if ((int)$direct->fetchColumn() === 2) {
                        $blocked = pc_db()->prepare(
                            'SELECT 1 FROM conversation_members peer
                             JOIN user_blocks b ON (b.user_id = ? AND b.blocked_user_id = peer.user_id)
                                  OR (b.user_id = peer.user_id AND b.blocked_user_id = ?)
                             WHERE peer.conversation_id = ? AND peer.user_id <> ? LIMIT 1'
                        );
                        $blocked->execute([$user['id'], $user['id'], $conversationId, $user['id']]);
                        if ($blocked->fetchColumn()) pc_json(403, ['ok' => false, 'error' => 'messaging_blocked']);
                    }
                    $stmt = pc_db()->prepare('INSERT INTO messages (conversation_id, sender_id, body, body_encrypted, encryption_version) VALUES (?, ?, ?, 1, ?)');
                    $stmt->execute([$conversationId, $user['id'], $envelope['body'], $envelope['version']]);
                    pc_json(201, ['ok' => true, 'message' => [
                        'id' => (string)pc_db()->lastInsertId(), 'conversation_id' => (string)$conversationId,
                        'sender_id' => (string)$user['id'], 'body' => $envelope['body'], 'encrypted' => true,
                        'encryption_version' => $envelope['version'], 'created_at' => gmdate('Y-m-d H:i:s'),
                    ]]);
                }
                header('Allow: GET, POST');
                pc_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
            }


    if (preg_match('#^/api/v1/messages/([0-9]+)/edit$#', $path, $matches) && $method === 'POST') {
        $user = pc_require_verified_user();
        $data = pc_input();
        $envelope = pc_encrypted_envelope($data['body'] ?? null);
        if (!$envelope) pc_json(422, ['ok' => false, 'error' => 'encrypted_message_envelope_required', 'format' => 'pc-e2ee-v1']);
        $messageId = (int)$matches[1];
        $stmt = pc_db()->prepare('SELECT sender_id, created_at FROM messages WHERE id = ? AND body_encrypted = 1 LIMIT 1');
        $stmt->execute([$messageId]);
        $message = $stmt->fetch();
        if (!$message) pc_json(404, ['ok' => false, 'error' => 'message_not_found']);
        if ((int)$message['sender_id'] !== (int)$user['id']) pc_json(403, ['ok' => false, 'error' => 'message_edit_forbidden']);
        if (strtotime((string)$message['created_at'] . ' UTC') < time() - 172800) pc_json(409, ['ok' => false, 'error' => 'message_edit_window_expired']);
        pc_db()->prepare('UPDATE messages SET body = ?, edited_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$envelope['body'], $messageId]);
        pc_json(200, ['ok' => true, 'message_id' => (string)$messageId, 'edited' => true]);
    }

    if (preg_match('#^/api/v1/messages/([0-9]+)/delete$#', $path, $matches) && $method === 'POST') {
        $user = pc_require_verified_user();
        $data = pc_input();
        $forEveryone = $data['for_everyone'] ?? false;
        if (!is_bool($forEveryone)) pc_json(422, ['ok' => false, 'error' => 'invalid_delete_scope']);
        $messageId = (int)$matches[1];
        $stmt = pc_db()->prepare('SELECT m.sender_id, m.conversation_id FROM messages m JOIN conversation_members cm ON cm.conversation_id = m.conversation_id AND cm.user_id = ? WHERE m.id = ? LIMIT 1');
        $stmt->execute([$user['id'], $messageId]);
        $message = $stmt->fetch();
        if (!$message) pc_json(404, ['ok' => false, 'error' => 'message_not_found']);
        if ($forEveryone) {
            if ((int)$message['sender_id'] !== (int)$user['id']) pc_json(403, ['ok' => false, 'error' => 'message_delete_forbidden']);
            pc_db()->prepare('DELETE FROM messages WHERE id = ?')->execute([$messageId]);
        } else {
            pc_db()->prepare('INSERT IGNORE INTO message_hidden (user_id, message_id) VALUES (?, ?)')->execute([$user['id'], $messageId]);
        }
        pc_json(200, ['ok' => true, 'message_id' => (string)$messageId, 'deleted' => true, 'for_everyone' => $forEveryone]);
    }

    if (preg_match('#^/api/v1/messages/([0-9]+)/reactions$#', $path, $matches) && $method === 'GET') {
        $user = pc_require_verified_user();
        $result = PCReactionService::list((int)$user['id'], (int)$matches[1]);
        pc_json($result['status'], $result['body']);
    }

    if (preg_match('#^/api/v1/messages/([0-9]+)/reactions$#', $path, $matches) && $method === 'POST') {
        $user = pc_require_verified_user();
        $data = pc_input();
        $reaction = is_string($data['reaction'] ?? null) ? trim($data['reaction']) : '';
        $result = PCReactionService::add((int)$user['id'], (int)$matches[1], $reaction);
        pc_json($result['status'], $result['body']);
    }

    if (preg_match('#^/api/v1/messages/([0-9]+)/reactions/remove$#', $path, $matches) && $method === 'POST') {
        $user = pc_require_verified_user();
        $data = pc_input();
        $reaction = is_string($data['reaction'] ?? null) ? trim($data['reaction']) : '';
        $result = PCReactionService::remove((int)$user['id'], (int)$matches[1], $reaction);
        pc_json($result['status'], $result['body']);
    }

    return false;
}
