<?php
declare(strict_types=1);

require_once __DIR__ . '/../validators/SearchValidator.php';
require_once __DIR__ . '/../repositories/SearchRepository.php';

final class PCSearchService
{
    public static function search(int $viewerId, mixed $query): array
    {
        $validated = PCSearchValidator::query($query);
        if ($validated === null) {
            return ['status' => 422, 'body' => ['ok' => false, 'error' => 'query_must_be_2_to_64_characters']];
        }
        $pdo = pc_db();
        return [
            'status' => 200,
            'body' => [
                'ok' => true,
                'query' => $validated,
                'users' => PCSearchRepository::users($pdo, $viewerId, $validated),
                'groups' => PCSearchRepository::groups($pdo, $validated),
                'channels' => PCSearchRepository::channels($pdo, $validated),
            ],
        ];
    }
}
