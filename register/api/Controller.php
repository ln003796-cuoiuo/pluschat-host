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
            PCVerificationMailer::send($email, (string)$user['first_name'], $token);
        }
        pc_json(200, ['ok' => true, 'message' => 'Если аккаунт существует и ещё не подтверждён, письмо будет отправлено.']);
    }


    if ($path === '/api/v1/auth/phone/verify' && $method === 'POST') {
        pc_rate_limit('verify-phone-code', 10, 900);
        $data = pc_input();
        $phone = is_string($data['phone'] ?? null) ? preg_replace('/[\\s().-]+/', '', trim($data['phone'])) : '';
        $code = pc_string($data, 'code');
        if (!preg_match('/^\\+[1-9][0-9]{7,14}$/', $phone) || !preg_match('/^[0-9]{6}$/', $code)) {
            pc_json(422, ['ok' => false, 'error' => 'invalid_phone_or_code', 'message' => 'Укажи номер в международном формате и шестизначный код из SMS.']);
        }
        if (!PCRegistrationRepository::confirmPhoneCode(pc_db(), $phone, $code)) {
            pc_json(400, ['ok' => false, 'error' => 'invalid_or_expired_phone_code', 'message' => 'Код неверный, истёк или превышено число попыток. Запроси новый код.']);
        }
        pc_json(200, ['ok' => true, 'verified' => true, 'message' => 'Номер телефона подтверждён. Теперь подтверди email.']);
    }

    if ($path === '/api/v1/auth/phone/resend' && $method === 'POST') {
        pc_rate_limit('resend-phone-code', 3, 900);
        $data = pc_input();
        $phone = is_string($data['phone'] ?? null) ? preg_replace('/[\\s().-]+/', '', trim($data['phone'])) : '';
        if (!preg_match('/^\\+[1-9][0-9]{7,14}$/', $phone)) {
            pc_json(422, ['ok' => false, 'error' => 'invalid_phone']);
        }
        $result = PCRegistrationRepository::replacePhoneCode(pc_db(), $phone);
        if (is_array($result) && !empty($result['cooldown'])) {
            pc_json(429, ['ok' => false, 'error' => 'sms_resend_cooldown', 'message' => 'Подожди 60 секунд перед повторной отправкой.']);
        }
        if (is_array($result) && isset($result['code'])) {
            $sent = PCTextBeeService::send($phone, 'Код подтверждения Pluschat: ' . $result['code'] . '. Действует 10 минут. Никому не сообщайте этот код.');
            if (!$sent) {
                pc_json(502, ['ok' => false, 'error' => 'sms_delivery_failed', 'message' => 'TextBee не принял SMS. Проверь подключённый телефон, разрешение SMS и лимит.']);
            }
        }
        pc_json(200, ['ok' => true, 'message' => 'Если номер зарегистрирован и не подтверждён, код отправлен.']);
    }

    return false;
}
