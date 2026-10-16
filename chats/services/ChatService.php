<?php
declare(strict_types=1);

require_once __DIR__ . '/../validators/ChatValidator.php';
require_once __DIR__ . '/../repositories/ChatRepository.php';

final class PCChatService
{
    public static function createDirect(int $userId, array $data): array
    {
        $recipientId = PCChatValidator::recipientId($data, $userId);
        if ($recipientId === null) return ['status' => 422, 'body' => ['ok' => false, 'error' => 'invalid_recipient']];
        $pdo = pc_db();
        if (!PCChatRepository::recipientExists($pdo, $recipientId)) {
            return ['status' => 404, 'body' => ['ok' => false, 'error' => 'user_not_found']];
        }
        if (PCChatRepository::isBlocked($pdo, $userId, $recipientId)) {
            return ['status' => 403, 'body' => ['ok' => false, 'error' => 'messaging_blocked']];
        }
        $existing = PCChatRepository::findDirect($pdo, $userId, $recipientId);
        if ($existing !== null) {
            return ['status' => 200, 'body' => ['ok' => true, 'conversation_id' => (string)$existing, 'created' => false]];
        }
        $id = PCChatRepository::createDirect($pdo, $userId, $recipientId);
        return ['status' => 201, 'body' => ['ok' => true, 'conversation_id' => (string)$id, 'created' => true]];
    }
}
