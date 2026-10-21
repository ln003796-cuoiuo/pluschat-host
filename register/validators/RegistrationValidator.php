<?php
declare(strict_types=1);

final class PCRegistrationValidator
{
    public static function validate(array $data): array
    {
        $email = strtolower(is_string($data['email'] ?? null) ? trim($data['email']) : '');
        // Public nickname is assigned only after email verification in profile completion.
        $username = '';
        $password = is_string($data['password'] ?? null) ? $data['password'] : '';
        $firstName = is_string($data['first_name'] ?? null) ? trim($data['first_name']) : '';
        $lastName = is_string($data['last_name'] ?? null) ? trim($data['last_name']) : '';
        $birthDate = is_string($data['birth_date'] ?? null) ? trim($data['birth_date']) : '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || pc_len($email) > 254) {
            return ['error' => 'invalid_email'];
        }
        if (strlen($password) < 10 || strlen($password) > 200) {
            return ['error' => 'invalid_password', 'message' => 'Пароль должен содержать от 10 до 200 символов.'];
        }
        if ($firstName === '') {
            return ['error' => 'first_name_required', 'message' => 'Имя обязательно для регистрации.'];
        }
        if (pc_len($firstName) > 80 || pc_len($lastName) > 80) {
            return ['error' => 'profile_field_too_long'];
        }
        if ($birthDate !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
            if (!$date || $date->format('Y-m-d') !== $birthDate || $date > new DateTimeImmutable('today')) {
                return ['error' => 'invalid_birth_date'];
            }
        }

        return [
            'email' => $email,
            'username' => $username,
            'password' => $password,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate !== '' ? $birthDate : null,
        ];
    }
}
