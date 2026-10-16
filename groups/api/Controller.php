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
    return false;
}
