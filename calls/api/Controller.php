<?php
declare(strict_types=1);

/*
 * Same-host WebRTC signaling API. Media remains peer-to-peer; PHP only
 * authenticates participants and relays short-lived SDP/ICE signaling records.
 */
function pc_call_participant(int $callId, int $userId): array
{
    $stmt = pc_db()->prepare(
        'SELECT c.id, c.conversation_id, c.created_by, c.call_type, c.status, c.expires_at,
                (c.expires_at > UTC_TIMESTAMP()) AS not_expired, p.status AS participant_status
         FROM calls c JOIN call_participants p ON p.call_id = c.id
         WHERE c.id = ? AND p.user_id = ? LIMIT 1'
    );
    $stmt->execute([$callId, $userId]);
    $row = $stmt->fetch();
    if (!$row) pc_json(404, ['ok' => false, 'error' => 'call_not_found']);
    return $row;
}

function pc_calls_dispatch(string $path, string $method): bool
{
    if ($path === '/api/v1/calls' && $method === 'POST') {
        $user = pc_require_verified_user();
        pc_rate_limit('call-start', 10, 900);
        $data = pc_input();
        $conversationId = filter_var($data['conversation_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $callType = $data['type'] ?? 'audio';
        if (!$conversationId || !in_array($callType, ['audio', 'video'], true)) {
            pc_json(422, ['ok' => false, 'error' => 'invalid_call_request']);
        }

        $pdo = pc_db();
        $pdo->beginTransaction();
        try {
            // Serialize call creation per conversation so concurrent requests cannot
            // create two active calls for the same conversation.
            $conversation = $pdo->prepare('SELECT id FROM conversations WHERE id = ? FOR UPDATE');
            $conversation->execute([$conversationId]);
            if (!$conversation->fetchColumn()) {
                $pdo->rollBack();
                pc_json(404, ['ok' => false, 'error' => 'conversation_not_found']);
            }

            $member = $pdo->prepare('SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ?');
            $member->execute([$conversationId, $user['id']]);
            if (!$member->fetchColumn()) {
                $pdo->rollBack();
                pc_json(403, ['ok' => false, 'error' => 'conversation_access_denied']);
            }

            $members = $pdo->prepare(
                'SELECT user_id FROM conversation_members WHERE conversation_id = ? ORDER BY user_id LIMIT 9 FOR UPDATE'
            );
            $members->execute([$conversationId]);
            $memberIds = array_map('intval', array_column($members->fetchAll(), 'user_id'));
            if (count($memberIds) < 2 || count($memberIds) > 8) {
                $pdo->rollBack();
                pc_json(422, ['ok' => false, 'error' => 'call_participant_count_unsupported', 'max_participants' => 8]);
            }

            $active = $pdo->prepare(
                "SELECT id FROM calls WHERE conversation_id = ? AND status IN ('ringing','active')
                 AND expires_at > UTC_TIMESTAMP() ORDER BY id DESC LIMIT 1"
            );
            $active->execute([$conversationId]);
            if ($active->fetchColumn()) {
                $pdo->rollBack();
                pc_json(409, ['ok' => false, 'error' => 'call_already_active']);
            }

            $pdo->prepare(
                "INSERT INTO calls (conversation_id, created_by, call_type, status, expires_at)
                 VALUES (?, ?, ?, 'ringing', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 90 SECOND))"
            )->execute([$conversationId, $user['id'], $callType]);
            $callId = (int)$pdo->lastInsertId();
            $insert = $pdo->prepare(
                "INSERT INTO call_participants (call_id, user_id, status, joined_at)
                 VALUES (?, ?, ?, IF(? = 'joined', UTC_TIMESTAMP(), NULL))"
            );
            foreach ($memberIds as $memberId) {
                $status = $memberId === (int)$user['id'] ? 'joined' : 'invited';
                $insert->execute([$callId, $memberId, $status, $status]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        pc_json(201, ['ok' => true, 'call' => [
            'id' => (string)$callId, 'conversation_id' => (string)$conversationId,
            'type' => $callType, 'status' => 'ringing', 'expires_in' => 90,
            'participants' => array_map('strval', $memberIds),
        ]]);
    }

    if ($path === '/api/v1/calls/active' && $method === 'GET') {
        $user = pc_require_verified_user();
        $conversationId = filter_var($_GET['conversation_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$conversationId) pc_json(422, ['ok' => false, 'error' => 'invalid_conversation_id']);
        $stmt = pc_db()->prepare(
            "SELECT c.id, c.conversation_id, c.created_by, c.call_type, c.status, c.expires_at
             FROM calls c JOIN call_participants p ON p.call_id = c.id
             WHERE c.conversation_id = ? AND p.user_id = ? AND c.status IN ('ringing','active')
               AND c.expires_at > UTC_TIMESTAMP() ORDER BY c.id DESC LIMIT 1"
        );
        $stmt->execute([$conversationId, $user['id']]);
        $call = $stmt->fetch();
        pc_json(200, ['ok' => true, 'call' => $call ?: null]);
    }

    if (!preg_match('#^/api/v1/calls/([1-9][0-9]*)(?:/(join|decline|signal|signals|end))?$#', $path, $m)) {
        return false;
    }

    $user = pc_require_verified_user();
    $callId = (int)$m[1];
    $action = $m[2] ?? '';
    $pdo = pc_db();
    $call = pc_call_participant($callId, (int)$user['id']);
    if (in_array($call['participant_status'], ['declined', 'left'], true)
        && !($action === '' && $method === 'GET')) {
        pc_json(403, ['ok' => false, 'error' => 'call_participant_inactive']);
    }

    if ($action === '' && $method === 'GET') {
        $stmt = $pdo->prepare(
            'SELECT p.user_id, p.status, p.joined_at, u.username, u.first_name, u.last_name
             FROM call_participants p JOIN users u ON u.id = p.user_id
             WHERE p.call_id = ? ORDER BY p.user_id'
        );
        $stmt->execute([$callId]);
        pc_json(200, ['ok' => true, 'call' => $call, 'participants' => $stmt->fetchAll()]);
    }

    if ($action === 'join' && $method === 'POST') {
        pc_rate_limit('call-join', 30, 900);
        if ($call['status'] === 'ended' || empty($call['not_expired'])) {
            pc_json(410, ['ok' => false, 'error' => 'call_expired']);
        }
        $pdo->prepare(
            "UPDATE call_participants SET status = 'joined', joined_at = COALESCE(joined_at, UTC_TIMESTAMP()), left_at = NULL
             WHERE call_id = ? AND user_id = ?"
        )->execute([$callId, $user['id']]);
        $pdo->prepare("UPDATE calls SET status = 'active', expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 4 HOUR) WHERE id = ? AND status = 'ringing'")->execute([$callId]);
        pc_json(200, ['ok' => true, 'joined' => true]);
    }

    if ($action === 'decline' && $method === 'POST') {
        $pdo->prepare(
            "UPDATE call_participants SET status = 'declined', left_at = UTC_TIMESTAMP()
             WHERE call_id = ? AND user_id = ?"
        )->execute([$callId, $user['id']]);
        $joined = $pdo->prepare("SELECT COUNT(*) FROM call_participants WHERE call_id = ? AND status = 'joined'");
        $joined->execute([$callId]);
        $joinedCount = (int)$joined->fetchColumn();
        $invited = $pdo->prepare("SELECT COUNT(*) FROM call_participants WHERE call_id = ? AND status = 'invited'");
        $invited->execute([$callId]);
        $invitedCount = (int)$invited->fetchColumn();
        if ($joinedCount === 0 || ((int)$call['created_by'] === (int)$user['id']) || ($invitedCount === 0 && $joinedCount <= 1)) {
            $pdo->prepare("UPDATE calls SET status = 'ended', ended_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$callId]);
            $pdo->prepare("UPDATE call_participants SET status = 'left', left_at = UTC_TIMESTAMP() WHERE call_id = ? AND status = 'invited'")->execute([$callId]);
        }
        pc_json(200, ['ok' => true, 'declined' => true]);
    }

    if ($action === 'signal' && $method === 'POST') {
        pc_rate_limit('call-signal', 180, 900);
        if (!in_array($call['status'], ['ringing', 'active'], true)
            || empty($call['not_expired'])) {
            pc_json(410, ['ok' => false, 'error' => 'call_expired']);
        }
        $data = pc_input();
        $toUserId = filter_var($data['to_user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $type = $data['type'] ?? '';
        $payload = $data['payload'] ?? null;
        if (!$toUserId || (int)$toUserId === (int)$user['id']
            || !in_array($type, ['offer', 'answer', 'ice'], true)
            || !is_array($payload)) {
            pc_json(422, ['ok' => false, 'error' => 'invalid_call_signal']);
        }
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || strlen($encoded) > 32768) {
            pc_json(413, ['ok' => false, 'error' => 'call_signal_too_large']);
        }
        $recipient = $pdo->prepare(
            "SELECT 1 FROM call_participants WHERE call_id = ? AND user_id = ? AND status NOT IN ('declined','left')"
        );
        $recipient->execute([$callId, $toUserId]);
        if (!$recipient->fetchColumn()) pc_json(403, ['ok' => false, 'error' => 'invalid_call_recipient']);
        $pdo->prepare(
            'INSERT INTO call_signals (call_id, from_user_id, to_user_id, signal_type, payload_json, expires_at)
             VALUES (?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE))'
        )->execute([$callId, $user['id'], $toUserId, $type, $encoded]);
        pc_json(201, ['ok' => true, 'signal_id' => (string)$pdo->lastInsertId()]);
    }

    if ($action === 'signals' && $method === 'GET') {
        pc_rate_limit('call-poll', 120, 900);
        $after = filter_var($_GET['after'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($after === false) pc_json(422, ['ok' => false, 'error' => 'invalid_signal_cursor']);
        $stmt = $pdo->prepare(
            'SELECT id, from_user_id, signal_type, payload_json, created_at
             FROM call_signals WHERE call_id = ? AND to_user_id = ? AND id > ?
               AND expires_at > UTC_TIMESTAMP() ORDER BY id ASC LIMIT 100'
        );
        $stmt->execute([$callId, $user['id'], $after]);
        $signals = [];
        foreach ($stmt->fetchAll() as $row) {
            $payload = json_decode((string)$row['payload_json'], true);
            $signals[] = [
                'id' => (string)$row['id'], 'from_user_id' => (string)$row['from_user_id'],
                'type' => $row['signal_type'], 'payload' => is_array($payload) ? $payload : new stdClass(),
                'created_at' => $row['created_at'],
            ];
        }
        pc_json(200, ['ok' => true, 'signals' => $signals]);
    }

    if ($action === 'end' && $method === 'POST') {
        $pdo->prepare(
            "UPDATE calls SET status = 'ended', ended_at = UTC_TIMESTAMP()
             WHERE id = ? AND status <> 'ended'"
        )->execute([$callId]);
        $pdo->prepare(
            "UPDATE call_participants SET status = 'left', left_at = UTC_TIMESTAMP()
             WHERE call_id = ? AND status IN ('invited','joined')"
        )->execute([$callId]);
        pc_json(200, ['ok' => true, 'ended' => true]);
    }

    return false;
}
