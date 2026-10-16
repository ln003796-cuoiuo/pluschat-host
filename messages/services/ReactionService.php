<?php
declare(strict_types=1);

require_once __DIR__ . '/../validators/ReactionValidator.php';
require_once __DIR__ . '/../repositories/ReactionRepository.php';

final class PCReactionService
{
    public static function add(int $userId, int $messageId, string $reaction): array
    {
        if ($messageId < 1 || !PCReactionValidator::validate($reaction)) {
            return ['status' => 422, 'body' => ['ok' => false, 'error' => 'invalid_reaction']];
        }
        $pdo = pc_db();
        if (!PCReactionRepository::canReact($pdo, $userId, $messageId)) {
            return ['status' => 404, 'body' => ['ok' => false, 'error' => 'message_not_found']];
        }
        PCReactionRepository::add($pdo, $userId, $messageId, $reaction);
        return ['status' => 200, 'body' => ['ok' => true, 'reaction' => $reaction, 'active' => true]];
    }

    public static function remove(int $userId, int $messageId, string $reaction): array
    {
        if ($messageId < 1 || !PCReactionValidator::validate($reaction)) {
            return ['status' => 422, 'body' => ['ok' => false, 'error' => 'invalid_reaction']];
        }
        $pdo = pc_db();
        if (!PCReactionRepository::canReact($pdo, $userId, $messageId)) {
            return ['status' => 404, 'body' => ['ok' => false, 'error' => 'message_not_found']];
        }
        PCReactionRepository::remove($pdo, $userId, $messageId, $reaction);
        return ['status' => 200, 'body' => ['ok' => true, 'reaction' => $reaction, 'active' => false]];
    }
}
