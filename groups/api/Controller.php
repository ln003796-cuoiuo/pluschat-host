<?php
declare(strict_types=1);

/*
 * Pluschat groups API routes.
 * Business logic remains in this feature module; shared HTTP/database helpers
 * are loaded by public/index.php through bootstrap.php.
 */
function pc_groups_dispatch(string $path, string $method): bool
{
    if ($path === '/api/v1/groups/discover' && $method === 'GET') {
            $user = pc_require_verified_user();
            $query = trim((string)($_GET['q'] ?? ''));
            $like = '%' . addcslashes($query, '%_\\\\') . '%';
            $stmt = pc_db()->prepare("SELECT g.id, g.title, g.description, g.topics_enabled, (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = g.id) AS member_count FROM chat_groups g WHERE g.is_private = 0 AND g.group_kind = 'group' AND (g.title LIKE ? OR g.description LIKE ?) AND NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.user_id = ? AND b.blocked_user_id = g.owner_id) OR (b.user_id = g.owner_id AND b.blocked_user_id = ?)) ORDER BY g.id DESC LIMIT 50");
            $stmt->execute([$like, $like, $user['id'], $user['id']]);
            pc_json(200, ['ok' => true, 'groups' => $stmt->fetchAll()]);
        }

    if ($path === '/api/v1/groups' && ($method === 'GET' || $method === 'POST')) {
            $user = pc_require_verified_user();
            $pdo = pc_db();
            if ($method === 'POST') {
                $data = pc_input();
                $title = pc_string($data, 'title');
                $description = pc_string($data, 'description');
                $isPrivate = filter_var($data['is_private'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                $topicsEnabled = filter_var($data['topics_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                if ($title === '' || pc_len($title) > 120 || pc_len($description) > 1000) pc_json(422, ['ok' => false, 'error' => 'invalid_group_fields']);
                $invite = bin2hex(random_bytes(16));
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare("INSERT INTO chat_groups (owner_id, title, description, group_kind, is_private, invite_code, topics_enabled) VALUES (?, ?, ?, 'group', ?, ?, ?)");
                    $stmt->execute([$user['id'], $title, $description, $isPrivate, $invite, $topicsEnabled]);
                    $groupId = (int)$pdo->lastInsertId();
                    $pdo->prepare("INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, 'owner')")->execute([$groupId, $user['id']]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
                pc_json(201, ['ok' => true, 'group' => ['id' => (string)$groupId, 'title' => $title, 'description' => $description, 'invite_code' => $invite, 'is_private' => (bool)$isPrivate, 'topics_enabled' => (bool)$topicsEnabled]]);
            }
            $stmt = $pdo->prepare('SELECT g.id, g.title, g.description, g.group_kind, g.is_private, g.topics_enabled, g.created_at, gm.role, (SELECT COUNT(*) FROM group_members allm WHERE allm.group_id = g.id) AS member_count FROM group_members gm JOIN chat_groups g ON g.id = gm.group_id LEFT JOIN user_settings s ON s.user_id = gm.user_id WHERE gm.user_id = ? AND (g.group_kind <> ? OR COALESCE(s.show_local_group, 0) = 1) ORDER BY g.updated_at DESC LIMIT 100');
            $stmt->execute([$user['id'], 'local_area']);
            pc_json(200, ['ok' => true, 'groups' => $stmt->fetchAll()]);
        }
    return false;
}
