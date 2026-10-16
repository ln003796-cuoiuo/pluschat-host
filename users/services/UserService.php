<?php
declare(strict_types=1);

require_once __DIR__ . '/../validators/UserValidator.php';
require_once __DIR__ . '/../repositories/UserRepository.php';

final class PCUserService
{
    public static function search(int $viewerId, mixed $query): array
    {
        $validated = PCUserValidator::searchQuery($query);
        if ($validated === null) return ['status' => 422, 'body' => ['ok' => false, 'error' => 'query_must_be_2_to_64_characters']];
        return ['status' => 200, 'body' => ['ok' => true, 'users' => PCUserRepository::search(pc_db(), $viewerId, $validated)]];
    }

    public static function block(int $userId, int $targetId): array
    {
        if (!PCUserValidator::targetId($userId, $targetId)) return ['status' => 422, 'body' => ['ok' => false, 'error' => 'invalid_block_target']];
        $pdo = pc_db();
        if (!PCUserRepository::exists($pdo, $targetId)) return ['status' => 404, 'body' => ['ok' => false, 'error' => 'user_not_found']];
        PCUserRepository::block($pdo, $userId, $targetId);
        return ['status' => 200, 'body' => ['ok' => true, 'blocked' => true]];
    }

    public static function unblock(int $userId, int $targetId): array
    {
        if ($targetId < 1) return ['status' => 422, 'body' => ['ok' => false, 'error' => 'invalid_block_target']];
        PCUserRepository::unblock(pc_db(), $userId, $targetId);
        return ['status' => 200, 'body' => ['ok' => true, 'blocked' => false]];
    }
}
