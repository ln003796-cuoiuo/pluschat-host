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
        if (PCRegistrationRepository::isTaken($pdo, $user['email'], $user['username'])) {
            return ['status' => 409, 'body' => ['ok' => false, 'error' => 'email_or_username_taken']];
        }

        $code = PCRegistrationRepository::create($pdo, $user);
        $sent = PCVerificationMailer::send($user['email'], $user['username'], $code);
        return [
            'status' => 201,
            'body' => [
                'ok' => true,
                'registered' => true,
                'email_verification_sent' => $sent,
                'message' => $sent
                    ? 'Регистрация создана. Введите шестизначный код из письма. Код действует 15 минут.'
                    : 'Аккаунт создан, но письмо не отправлено. Проверьте настройки почты на хостинге и запросите письмо повторно.',
            ],
        ];
    }
}
