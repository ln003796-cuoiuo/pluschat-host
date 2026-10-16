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
                        $stmt = pc_db()->prepare('SELECT id, sender_id, body, body_encrypted, encryption_version, created_at, edited_at FROM messages WHERE conversation_id = ? AND body_encrypted = 1 AND id < ? ORDER BY id DESC LIMIT ' . (int)$limit);
                        $stmt->execute([$conversationId, $before]);
                    } else {
                        $stmt = pc_db()->prepare('SELECT id, sender_id, body, body_encrypted, encryption_version, created_at, edited_at FROM messages WHERE conversation_id = ? AND body_encrypted = 1 ORDER BY id DESC LIMIT ' . (int)$limit);
                        $stmt->execute([$conversationId]);
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
