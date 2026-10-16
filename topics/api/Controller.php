<?php
declare(strict_types=1);

/* Topic message feeds and topic-to-channel conversion. */
require_once dirname(__DIR__) . '/services/TopicService.php';
function pc_topics_dispatch(string $path, string $method): bool
{
    if (preg_match('#^/api/v1/topics/([0-9]+)/messages$#', $path, $matches) && ($method === 'GET' || $method === 'POST')) {
        $user = pc_require_verified_user();
        $result = PCTopicService::messages((int)$user['id'], (int)$matches[1], $method, $_GET, $method === 'POST' ? pc_input() : []);
        pc_json($result['status'], $result['body']);
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
