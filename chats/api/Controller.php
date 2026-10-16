<?php
declare(strict_types=1);

/*
 * Pluschat chats API routes.
 * Business logic remains in this feature module; shared HTTP/database helpers
 * are loaded by public/index.php through bootstrap.php.
 */
function pc_chats_dispatch(string $path, string $method): bool
{
    if ($path === '/api/v1/conversations' && $method === 'POST') {
            $user = pc_require_verified_user();
            $data = pc_input();
            $otherId = filter_var($data['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$otherId || (int)$otherId === (int)$user['id']) pc_json(422, ['ok' => false, 'error' => 'invalid_recipient']);
            $pdo = pc_db();
            $exists = $pdo->prepare('SELECT id FROM users WHERE id = ? AND email_verified_at IS NOT NULL');
            $exists->execute([$otherId]);
            if (!$exists->fetch()) pc_json(404, ['ok' => false, 'error' => 'user_not_found']);
            $blocked = $pdo->prepare('SELECT 1 FROM user_blocks WHERE (user_id = ? AND blocked_user_id = ?) OR (user_id = ? AND blocked_user_id = ?) LIMIT 1');
            $blocked->execute([$user['id'], $otherId, $otherId, $user['id']]);
            if ($blocked->fetchColumn()) pc_json(403, ['ok' => false, 'error' => 'messaging_blocked']);
    
            $find = $pdo->prepare(
                'SELECT c.id FROM conversations c
                 JOIN conversation_members m1 ON m1.conversation_id = c.id AND m1.user_id = ?
                 JOIN conversation_members m2 ON m2.conversation_id = c.id AND m2.user_id = ?
                 WHERE (SELECT COUNT(*) FROM conversation_members mc WHERE mc.conversation_id = c.id) = 2
                 ORDER BY c.id LIMIT 1'
            );
            $find->execute([$user['id'], $otherId]);
            $conversation = $find->fetch();
            if ($conversation) pc_json(200, ['ok' => true, 'conversation_id' => (string)$conversation['id'], 'created' => false]);
    
            $pdo->beginTransaction();
            try {
                $pdo->exec('INSERT INTO conversations () VALUES ()');
                $conversationId = (int)$pdo->lastInsertId();
                $member = $pdo->prepare('INSERT INTO conversation_members (conversation_id, user_id) VALUES (?, ?)');
                $member->execute([$conversationId, $user['id']]);
                $member->execute([$conversationId, $otherId]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            pc_json(201, ['ok' => true, 'conversation_id' => (string)$conversationId, 'created' => true]);
        }

    if ($path === '/api/v1/conversations' && $method === 'GET') {
            $user = pc_require_verified_user();
            $stmt = pc_db()->prepare(
                'SELECT c.id, c.created_at,
                        other.id AS other_user_id, other.username AS other_username,
                        other.first_name AS other_first_name, other.last_name AS other_last_name,
                        (SELECT m.body FROM messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message,
                        (SELECT m.created_at FROM messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message_at
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
