<?php
declare(strict_types=1);

/*
 * Pluschat users API routes.
 * Business logic remains in this feature module; shared HTTP/database helpers
 * are loaded by public/index.php through bootstrap.php.
 */
function pc_users_dispatch(string $path, string $method): bool
{
    if ($path === '/api/v1/users/search' && $method === 'GET') {
            $user = pc_require_verified_user();
            $query = trim((string)($_GET['q'] ?? ''));
            if (pc_len($query) < 2 || pc_len($query) > 64) {
                pc_json(422, ['ok' => false, 'error' => 'query_must_be_2_to_64_characters']);
            }
            $like = '%' . addcslashes($query, '%_\\') . '%';
            $stmt = pc_db()->prepare("SELECT u.id, u.username, u.first_name, u.last_name
                FROM users u LEFT JOIN user_settings s ON s.user_id = u.id
                WHERE u.id <> ? AND (u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)
                  AND COALESCE(s.profile_visibility, 'everyone') <> 'nobody'
                  AND (COALESCE(s.profile_visibility, 'everyone') <> 'contacts' OR EXISTS (
                      SELECT 1 FROM conversation_members mine
                      JOIN conversation_members theirs ON theirs.conversation_id = mine.conversation_id
                      WHERE mine.user_id = ? AND theirs.user_id = u.id
                  ))
                  AND NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.user_id = ? AND b.blocked_user_id = u.id) OR (b.user_id = u.id AND b.blocked_user_id = ?))
                ORDER BY u.username LIMIT 30");
            $stmt->execute([$user['id'], $like, $like, $like, $user['id'], $user['id'], $user['id']]);
            pc_json(200, ['ok' => true, 'users' => array_map(static fn(array $u): array => [
                'id' => (string)$u['id'], 'username' => $u['username'],
                'first_name' => $u['first_name'], 'last_name' => $u['last_name'],
            ], $stmt->fetchAll())]);
        }
    return false;
}
