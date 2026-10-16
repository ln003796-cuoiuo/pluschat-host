<?php
declare(strict_types=1);

require_once __DIR__ . '/../validators/TopicValidator.php';
require_once __DIR__ . '/../repositories/TopicRepository.php';

final class PCTopicService
{
    public static function messages(int $userId, int $topicId, string $method, array $query, array $input): array
    {
        $pdo = pc_db();
        if ($topicId < 1 || !PCTopicRepository::canAccess($pdo, $userId, $topicId)) {
            return ['status' => 404, 'body' => ['ok' => false, 'error' => 'topic_not_found']];
        }
        if ($method === 'GET') {
            return ['status' => 200, 'body' => [
                'ok' => true,
                'messages' => PCTopicRepository::messages(
                    $pdo, $topicId,
                    PCTopicValidator::limit($query['limit'] ?? 50),
                    PCTopicValidator::before($query['before'] ?? null)
                ),
            ]];
        }
        $message = PCTopicValidator::message($input);
        if (isset($message['error'])) {
            return ['status' => 422, 'body' => ['ok' => false, 'error' => $message['error']]];
        }
        return ['status' => 201, 'body' => [
            'ok' => true,
            'message' => PCTopicRepository::createMessage($pdo, $topicId, $userId, $message),
        ]];
    }
}
