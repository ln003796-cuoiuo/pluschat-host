<?php
declare(strict_types=1);

require_once __DIR__ . '/../validators/LoginValidator.php';
require_once __DIR__ . '/../repositories/LoginRepository.php';
require_once dirname(__DIR__, 2) . '/security/services/TwoFactorService.php';

final class PCLoginService
{
    public static function login(array $data): array
    {
        $input = PCLoginValidator::validate($data);
        if (isset($input['error'])) {
            return ['status' => 422, 'body' => ['ok' => false] + $input];
        }
        pc_rate_limit('login-ip', 30, 900);
        pc_rate_limit('login-account-' . hash('sha256', $input['login']), 8, 900);

        $pdo = pc_db();
        $user = PCLoginRepository::findByLogin($pdo, $input['login']);
        if (!$user || !password_verify($input['password'], (string)$user['password_hash'])) {
            return ['status' => 401, 'body' => ['ok' => false, 'error' => 'invalid_credentials']];
        }
        if (empty($user['email_verified_at'])) {
            return ['status' => 403, 'body' => ['ok' => false, 'error' => 'email_not_verified', 'message' => 'Сначала подтвердите электронную почту.']];
        }
        if (PCTwoFactorService::isEnabled($pdo, (int)$user['id'])) {
            $challenge = PCTwoFactorService::createChallenge($pdo, $user, $input);
            return [
                'status' => 200,
                'body' => [
                    'ok' => true,
                    'requires_2fa' => true,
                    'challenge_id' => $challenge,
                    'message' => 'Введите одноразовый код из приложения-аутентификатора.',
                ],
            ];
        }

        // 2FA is optional. If enabled, the branch above requires a TOTP challenge;
        // otherwise issue a regular session after email verification.
        $issued = PCLoginRepository::issueToken($pdo, $user, $input);
        return [
            'status' => 200,
            'body' => [
                'ok' => true,
                'requires_2fa' => false,
                'token' => $issued['token'],
                'token_type' => 'Bearer',
                'expires_at' => $issued['expires_at'],
                'user' => pc_public_user($user),
            ],
        ];
    }
}
