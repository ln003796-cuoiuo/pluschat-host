<?php
declare(strict_types=1);

final class PCTopicValidator
{
    public static function message(array $input): array
    {
        $body = is_string($input['body'] ?? null) ? trim($input['body']) : '';
        if ($body === '' || pc_len($body) > 4000) return ['error' => 'message_must_be_1_to_4000_characters'];
        return [
            'body' => $body,
            'encrypted' => filter_var($input['encrypted'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
        ];
    }

    public static function limit(mixed $value): int
    {
        $limit = filter_var($value, FILTER_VALIDATE_INT);
        return $limit === false ? 50 : max(1, min(100, (int)$limit));
    }

    public static function before(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : (int)$id;
    }
}
