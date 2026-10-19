<?php
declare(strict_types=1);
require_once __DIR__ . '/../repositories/FriendRepository.php';

final class PCFriendService
{
    public static function list(int $userId): array
    {
        return ['status' => 200, 'body' => ['ok' => true, 'friends' => PCFriendRepository::friends(pc_db(), $userId)]];
    }

    public static function requests(int $userId, mixed $direction): array
    {
        $direction = is_string($direction) ? $direction : 'incoming';
        if (!in_array($direction, ['incoming', 'outgoing'], true)) return ['status' => 422, 'body' => ['ok' => false, 'error' => 'invalid_direction']];
        return ['status' => 200, 'body' => ['ok' => true, 'direction' => $direction, 'requests' => PCFriendRepository::requests(pc_db(), $userId, $direction)]];
    }

    public static function request(int $userId, mixed $target): array
    {
        $targetId = filter_var($target, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$targetId || $targetId === $userId) return ['status' => 422, 'body' => ['ok' => false, 'error' => 'invalid_target_user']];
        $pdo = pc_db();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? AND email_verified_at IS NOT NULL AND phone_verified_at IS NOT NULL AND LEFT(username, 11) <> 'pc_pending_'');
        $stmt->execute([$targetId]);
        if (!$stmt->fetchColumn()) return ['status' => 404, 'body' => ['ok' => false, 'error' => 'user_not_found']];
        $stmt = $pdo->prepare('SELECT 1 FROM user_blocks WHERE (user_id = ? AND blocked_user_id = ?) OR (user_id = ? AND blocked_user_id = ?) LIMIT 1');
        $stmt->execute([$userId, $targetId, $targetId, $userId]);
        if ($stmt->fetchColumn()) return ['status' => 403, 'body' => ['ok' => false, 'error' => 'friend_request_not_allowed']];
        $stmt = $pdo->prepare('SELECT 1 FROM friendships WHERE user_id = ? AND friend_id = ? LIMIT 1');
        $stmt->execute([$userId, $targetId]);
        if ($stmt->fetchColumn()) return ['status' => 409, 'body' => ['ok' => false, 'error' => 'already_friends']];
        $stmt = $pdo->prepare('SELECT id, requester_id, status FROM friend_requests WHERE (requester_id = ? AND recipient_id = ?) OR (requester_id = ? AND recipient_id = ?) LIMIT 1');
        $stmt->execute([$userId, $targetId, $targetId, $userId]);
        $old = $stmt->fetch();
        if ($old && $old['status'] === 'pending') {
            return ['status' => 409, 'body' => ['ok' => false, 'error' => (int)$old['requester_id'] === $targetId ? 'incoming_request_exists' : 'request_already_sent']];
        }
        if ($old) {
            $pdo->prepare("UPDATE friend_requests SET requester_id = ?, recipient_id = ?, status = 'pending', created_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$userId, $targetId, $old['id']]);
            $id = (int)$old['id'];
        } else {
            $pdo->prepare("INSERT INTO friend_requests (requester_id, recipient_id, status) VALUES (?, ?, 'pending')")->execute([$userId, $targetId]);
            $id = (int)$pdo->lastInsertId();
        }
        return ['status' => 201, 'body' => ['ok' => true, 'request_id' => (string)$id, 'status' => 'pending']];
    }

    public static function respond(int $userId, int $requestId, string $action): array
    {
        if (!in_array($action, ['accept', 'decline'], true)) return ['status' => 404, 'body' => ['ok' => false, 'error' => 'endpoint_not_found']];
        $pdo = pc_db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT id, requester_id FROM friend_requests WHERE id = ? AND recipient_id = ? AND status = 'pending' FOR UPDATE");
            $stmt->execute([$requestId, $userId]);
            $request = $stmt->fetch();
            if (!$request) {
                $pdo->rollBack();
                return ['status' => 404, 'body' => ['ok' => false, 'error' => 'request_not_found']];
            }
            $peerId = (int)$request['requester_id'];
            if ($action === 'accept') {
                $blocked = $pdo->prepare('SELECT 1 FROM user_blocks WHERE (user_id = ? AND blocked_user_id = ?) OR (user_id = ? AND blocked_user_id = ?) LIMIT 1');
                $blocked->execute([$userId, $peerId, $peerId, $userId]);
                if ($blocked->fetchColumn()) {
                    $pdo->rollBack();
                    return ['status' => 403, 'body' => ['ok' => false, 'error' => 'friend_request_not_allowed']];
                }
                $pdo->prepare('INSERT IGNORE INTO friendships (user_id, friend_id) VALUES (?, ?), (?, ?)')->execute([$userId, $peerId, $peerId, $userId]);
                $pdo->prepare("UPDATE friend_requests SET status = 'accepted', updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$requestId]);
            } else {
                $pdo->prepare("UPDATE friend_requests SET status = 'declined', updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$requestId]);
            }
            $pdo->commit();
            return ['status' => 200, 'body' => ['ok' => true, 'request_id' => (string)$requestId, 'status' => $action === 'accept' ? 'accepted' : 'declined']];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function remove(int $userId, int $friendId): array
    {
        if ($friendId < 1 || $friendId === $userId) return ['status' => 422, 'body' => ['ok' => false, 'error' => 'invalid_friend_id']];
        $pdo = pc_db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM friendships WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)')->execute([$userId, $friendId, $friendId, $userId]);
            $pdo->prepare("UPDATE friend_requests SET status = 'cancelled', updated_at = UTC_TIMESTAMP() WHERE status = 'pending' AND ((requester_id = ? AND recipient_id = ?) OR (requester_id = ? AND recipient_id = ?))")->execute([$userId, $friendId, $friendId, $userId]);
            $pdo->commit();
            return ['status' => 200, 'body' => ['ok' => true, 'removed' => true]];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
