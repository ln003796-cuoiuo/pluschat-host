<?php
declare(strict_types=1);

require_once __DIR__ . '/../validators/RegistrationValidator.php';
require_once __DIR__ . '/../repositories/RegistrationRepository.php';
require_once __DIR__ . '/../mail/mailconfirm/Mailer.php';

final class PCRegistrationService
{
    public static function register(array $data): array
    {
        $user = PCRegistrationValidator::validate($data);
        if (isset($user['error'])) {
            return ['status' => 422, 'body' => ['ok' => false] + $user];
        }

        $pdo = pc_db();
        if ($user['username'] === '') {
            // The DB requires a unique username, but the user chooses their public nickname
            // only after confirming email. This reserved internal value is never exposed.
            $user['username'] = 'pc_pending_' . bin2hex(random_bytes(8));
        }
        if (PCRegistrationRepository::isTaken($pdo, $user['email'], $user['username'])) {
            return ['status' => 409, 'body' => ['ok' => false, 'error' => 'email_username_or_phone_taken', 'message' => 'Email, имя пользователя или номер уже используется.']];
        }

        $codes = PCRegistrationRepository::create($pdo, $user);
        $emailSent = PCVerificationMailer::send($user['email'], $user['first_name'], $codes['email_code']);
        return [
            'status' => 201,
            'body' => [
                'ok' => true,
                'registered' => true,
                'profile_completion_required' => str_starts_with($user['username'], 'pc_pending_'),
                'email_verification_sent' => $emailSent,
                'message' => $emailSent
                    ? 'Аккаунт создан. Введи код подтверждения из электронной почты.'
                    : 'Аккаунт создан, но письмо не отправлено. Проверь настройки почты и запроси код повторно.',
            ],
        ];
    }
}
