<?php
declare(strict_types=1);

/*
 * Pluschat chats API routes.
 * Business logic remains in this feature module; shared HTTP/database helpers
 * are loaded by public/index.php through bootstrap.php.
 */
require_once dirname(__DIR__) . '/services/ChatService.php';

function pc_chats_dispatch(string $path, string $method): bool
{
    if ($path === '/api/v1/conversations' && $method === 'POST') {
        $user = pc_require_verified_user();
        $result = PCChatService::createDirect((int)$user['id'], pc_input());
        pc_json($result['status'], $result['body']);
    }

    if ($path === '/api/v1/conversations' && $method === 'GET') {
            $user = pc_require_verified_user();
            $stmt = pc_db()->prepare(
                'SELECT c.id, c.created_at,
                        other.id AS other_user_id, other.username AS other_username,
                        other.first_name AS other_first_name, other.last_name AS other_last_name,
                        (SELECT m.body FROM messages m WHERE m.conversation_id = c.id AND NOT EXISTS (SELECT 1 FROM message_hidden h WHERE h.message_id = m.id AND h.user_id = mine.user_id) ORDER BY m.id DESC LIMIT 1) AS last_message,
                        (SELECT m.created_at FROM messages m WHERE m.conversation_id = c.id AND NOT EXISTS (SELECT 1 FROM message_hidden h WHERE h.message_id = m.id AND h.user_id = mine.user_id) ORDER BY m.id DESC LIMIT 1) AS last_message_at
                 FROM conversation_members mine
                 JOIN conversations c ON c.id = mine.conversation_id
                 JOIN conversation_members theirs ON theirs.conversation_id = c.id AND theirs.user_id <> mine.user_id
                 JOIN users other ON other.id = theirs.user_id
                 WHERE mine.user_id = ?
                 ORDER BY COALESCE(last_message_at, c.created_at) DESC LIMIT 100'
            );
            $stmt->execute([$user['id']]);
            pc_json(200, ['ok' => true, 'conversations' => array_map(static fn(array $c): array => [
                'id' => (string)$c['id'], 'created_at' => $c['created_at'],
                'other_user' => ['id' => (string)$c['other_user_id'], 'username' => $c['other_username'], 'first_name' => $c['other_first_name'], 'last_name' => $c['other_last_name']],
                'last_message' => $c['last_message'], 'last_message_at' => $c['last_message_at'],
            ], $stmt->fetchAll())]);
        }
    return false;
}
