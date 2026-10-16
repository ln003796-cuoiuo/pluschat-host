<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/repositories/CallRepository.php';
require_once dirname(__DIR__) . '/validators/CallValidator.php';

final class PCCallService
{
    public static function create(int $userId, array $data): array
    {
        $peerId = filter_var($data['peer_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $type = PCCallValidator::callType($data['type'] ?? null);
        if (!$peerId || $peerId === $userId || !$type) {
            return ['status' => 422, 'body' => ['ok' => false, 'error' => 'invalid_peer_or_call_type']];
        }

        $pdo = pc_db();
        if (PCCallRepository::blocked($pdo, $userId, (int)$peerId)) {
            return ['status' => 403, 'body' => ['ok' => false, 'error' => 'calls_not_allowed']];
        }
        $conversationId = PCCallRepository::findDirectConversation($pdo, $userId, (int)$peerId);
        if (!$conversationId) {
            return ['status' => 404, 'body' => ['ok' => false, 'error' => 'direct_conversation_not_found']];
        }

        $active = $pdo->prepare(
            "SELECT id FROM calls WHERE status IN ('ringing','accepted') AND expires_at > UTC_TIMESTAMP()
             AND ((caller_id = ? AND callee_id = ?) OR (caller_id = ? AND callee_id = ?)) LIMIT 1"
        );
        $active->execute([$userId, $peerId, $peerId, $userId]);
        if ($active->fetchColumn()) {
            return ['status' => 409, 'body' => ['ok' => false, 'error' => 'call_already_active']];
        }

        $callId = PCCallRepository::create($pdo, $conversationId, $userId, (int)$peerId, $type);
        return ['status' => 201, 'body' => ['ok' => true, 'call' => [
            'id' => (string)$callId, 'conversation_id' => (string)$conversationId,
            'caller_id' => (string)$userId, 'callee_id' => (string)$peerId,
            'type' => $type, 'status' => 'ringing', 'expires_in' => 60,
        ]]];
    }

    public static function signal(int $userId, int $callId, array $data): array
    {
        $signal = PCCallValidator::signal($data['signal'] ?? null);
        if (!$signal) return ['status' => 422, 'body' => ['ok' => false, 'error' => 'invalid_signal']];
        $pdo = pc_db();
        $call = PCCallRepository::visibleCall($pdo, $callId, $userId);
        if (!$call) return ['status' => 404, 'body' => ['ok' => false, 'error' => 'call_not_found']];
        if (in_array($call['status'], ['declined','missed','ended','failed'], true) || ($call['status'] === 'ringing' && strtotime((string)$call['expires_at'] . ' UTC') <= time())) {
            return ['status' => 410, 'body' => ['ok' => false, 'error' => 'call_expired_or_ended']];
        }

        $caller = (int)$call['caller_id'];
        $callee = (int)$call['callee_id'];
        $peerId = $userId === $caller ? $callee : $caller;
        $type = $signal['type'];
        if ($type === 'offer' && $userId !== $caller) {
            return ['status' => 403, 'body' => ['ok' => false, 'error' => 'only_caller_can_offer']];
        }
        if ($type === 'answer' && $userId !== $callee) {
            return ['status' => 403, 'body' => ['ok' => false, 'error' => 'only_callee_can_answer']];
        }

        $payload = in_array($type, ['offer','answer','ice-candidate'], true)
            ? json_encode($signal, JSON_UNESCAPED_SLASHES)
            : null;
        $pdo->beginTransaction();
        try {
            if ($type === 'answer') {
                $stmt = $pdo->prepare("UPDATE calls SET status = 'accepted', answered_at = UTC_TIMESTAMP(), expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 12 HOUR) WHERE id = ? AND status = 'ringing'");
                $stmt->execute([$callId]);
            } elseif ($type === 'decline') {
                $stmt = $pdo->prepare("UPDATE calls SET status = 'declined', ended_at = UTC_TIMESTAMP() WHERE id = ? AND status IN ('ringing','accepted')");
                $stmt->execute([$callId]);
            } elseif ($type === 'hangup') {
                $stmt = $pdo->prepare("UPDATE calls SET status = 'ended', ended_at = UTC_TIMESTAMP() WHERE id = ? AND status IN ('ringing','accepted')");
                $stmt->execute([$callId]);
            }
            PCCallRepository::addSignal($pdo, $callId, $userId, $peerId, $type, $payload);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['status' => 201, 'body' => ['ok' => true, 'signal_queued' => true]];
    }

    public static function poll(int $userId, int $callId, mixed $after): array
    {
        $pdo = pc_db();
        $call = PCCallRepository::visibleCall($pdo, $callId, $userId);
        if (!$call) return ['status' => 404, 'body' => ['ok' => false, 'error' => 'call_not_found']];
        $afterId = filter_var($after ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($afterId === false) $afterId = 0;
        return ['status' => 200, 'body' => [
            'ok' => true,
            'call' => [
                'id' => (string)$call['id'], 'caller_id' => (string)$call['caller_id'],
                'callee_id' => (string)$call['callee_id'], 'type' => $call['call_type'],
                'status' => $call['status'], 'expires_at' => $call['expires_at'],
            ],
            'signals' => PCCallRepository::signalsAfter($pdo, $callId, $userId, (int)$afterId),
        ]];
    }
}
