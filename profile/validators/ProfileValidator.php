<?php
declare(strict_types=1);

final class PCProfileValidator
{
    public static function validate(array $data): array
    {
        $updates = [];
        if (array_key_exists('username', $data)) {
            $username = is_string($data['username']) ? trim($data['username']) : '';
            if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $username) || str_starts_with(strtolower($username), 'pc_pending_')) {
                return ['error' => 'invalid_username'];
            }
            $updates['username'] = $username;
        }

        foreach (['first_name' => 80, 'last_name' => 80] as $field => $limit) {
            if (!array_key_exists($field, $data)) continue;
            if (!is_string($data[$field]) || pc_len(trim($data[$field])) > $limit) {
                return ['error' => 'invalid_' . $field];
            }
            $updates[$field] = trim($data[$field]);
        }

        if (array_key_exists('birth_date', $data)) {
            $value = is_string($data['birth_date']) ? trim($data['birth_date']) : '';
            if ($value === '') {
                $updates['birth_date'] = null;
            } else {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                if (!$date || $date->format('Y-m-d') !== $value || $date > new DateTimeImmutable('today')) {
                    return ['error' => 'invalid_birth_date'];
                }
                $updates['birth_date'] = $value;
            }
        }
        if (!$updates) return ['error' => 'no_profile_fields'];
        return ['updates' => $updates];
    }
}
