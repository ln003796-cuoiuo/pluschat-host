<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/RegistrationService.php';

function pc_register_dispatch(string $path, string $method): bool
{
    if ($path === '/api/v1/auth/register' && $method === 'POST') {
        pc_rate_limit('register', 5, 3600);
        $result = PCRegistrationService::register(pc_input());
        pc_json($result['status'], $result['body']);
    }

    if ($path === '/api/v1/auth/verify' && $method === 'POST') {
        pc_rate_limit('verify-email-code', 10, 900);
        $data = pc_input();
        $email = strtolower(pc_string($data, 'email'));
        $code = pc_string($data, 'code');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[0-9]{6}$/', $code)) {
            pc_json(422, ['ok' => false, 'error' => 'invalid_email_or_code', 'message' => 'Укажи email и шестизначный код из письма.']);
        }
        if (!PCRegistrationRepository::confirmCode(pc_db(), $email, $code)) {
            pc_json(400, ['ok' => false, 'error' => 'invalid_or_expired_verification_code', 'message' => 'Код неверный или истёк. Запроси новый код.']);
        }
        pc_json(200, ['ok' => true, 'verified' => true, 'message' => 'Почта подтверждена. Теперь можно войти в Pluschat.']);
    }

    // Keep verification links sent by older server versions working.
    if ($path === '/api/v1/auth/verify' && $method === 'GET') {
        $token = (string)($_GET['token'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/i', $token) || !PCRegistrationRepository::confirm(pc_db(), $token)) {
            pc_json(400, ['ok' => false, 'error' => 'invalid_or_expired_verification_token']);
        }
        pc_json(200, ['ok' => true, 'verified' => true, 'message' => 'Почта подтверждена. Теперь можно войти в Pluschat.']);
    }

    if ($path === '/api/v1/auth/resend-verification' && $method === 'POST') {
        pc_rate_limit('resend-verification', 3, 3600);
        $email = strtolower(pc_string(pc_input(), 'email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            pc_json(422, ['ok' => false, 'error' => 'invalid_email']);
        }
        $pdo = pc_db();
        $user = PCRegistrationRepository::unverifiedByEmail($pdo, $email);
        if ($user && empty($user['email_verified_at'])) {
            $token = PCRegistrationRepository::replaceVerificationToken($pdo, (int)$user['id']);
            PCVerificationMailer::send($email, (string)$user['username'], $token);
        }
        pc_json(200, ['ok' => true, 'message' => 'Если аккаунт существует и ещё не подтверждён, письмо будет отправлено.']);
    }

    return false;
}
