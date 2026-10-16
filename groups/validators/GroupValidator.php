<?php
declare(strict_types=1);

final class PCGroupValidator
{
    public static function validate(array $data): array
    {
        $title = is_string($data['title'] ?? null) ? trim($data['title']) : '';
        $description = is_string($data['description'] ?? null) ? trim($data['description']) : '';
        if ($title === '' || pc_len($title) > 120 || pc_len($description) > 1000) {
            return ['error' => 'invalid_group_fields'];
        }
        return [
            'title' => $title,
            'description' => $description,
            'is_private' => filter_var($data['is_private'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'topics_enabled' => filter_var($data['topics_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
        ];
    }
}
