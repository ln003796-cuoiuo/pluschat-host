<?php
declare(strict_types=1);

final class PCChannelValidator
{
    public static function validate(array $input): array
    {
        $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';
        $description = is_string($input['description'] ?? null) ? trim($input['description']) : '';
        if ($title === '' || pc_len($title) > 120 || pc_len($description) > 1000) {
            return ['error' => 'invalid_channel_fields'];
        }
        return [
            'title' => $title,
            'description' => $description,
            'is_public' => filter_var($input['is_public'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'topics_enabled' => filter_var($input['topics_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
        ];
    }
}
