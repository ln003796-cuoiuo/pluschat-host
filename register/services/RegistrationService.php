<?php
declare(strict_types=1);

require_once __DIR__ . '/../validators/RegistrationValidator.php';
require_once __DIR__ . '/../repositories/RegistrationRepository.php';
require_once __DIR__ . '/../mail/mailconfirm/Mailer.php';
require_once __DIR__ . '/TextBeeService.php';

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
        if (PCRegistrationRepository::isTaken($pdo, $user['email'], $user['username'], $user['phone'])) {
            return ['status' => 409, 'body' => ['ok' => false, 'error' => 'email_username_or_phone_taken', 'message' => 'Email, имя пользователя или номер уже используется.']];
        }

        $codes = PCRegistrationRepository::create($pdo, $user);
        $emailSent = PCVerificationMailer::send($user['email'], $user['first_name'], $codes['email_code']);
        $smsSent = PCTextBeeService::send($user['phone'], 'Код подтверждения Pluschat: ' . $codes['phone_code'] . '. Действует 10 минут. Никому не сообщайте этот код.');
        return [
            'status' => 201,
            'body' => [
                'ok' => true,
                'registered' => true,
                'profile_completion_required' => str_starts_with($user['username'], 'pc_pending_'),
                'email_verification_sent' => $emailSent,
                'phone_verification_sent' => $smsSent,
                'message' => $smsSent
                    ? 'Аккаунт создан. Введи код из SMS, затем подтверди электронную почту.'
                    : 'Аккаунт создан, но SMS не отправлено. Проверь ключ TextBee, подключённый телефон и лимит, затем запроси код повторно.',
            ],
        ];
    }
}
