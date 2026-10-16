<?php
declare(strict_types=1);

final class PCTopicValidator
{
    public static function message(array $input): array
    {
        $envelope = pc_encrypted_envelope($input['body'] ?? null);
        if (!$envelope) return ['error' => 'encrypted_message_envelope_required'];
        return ['body' => $envelope['body'], 'encrypted' => 1, 'encryption_version' => $envelope['version']];
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
