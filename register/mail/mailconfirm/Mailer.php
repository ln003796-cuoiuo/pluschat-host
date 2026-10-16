<?php
declare(strict_types=1);

final class PCVerificationMailer
{
    public static function send(string $email, string $username, string $token): bool
    {
        $baseUrl = rtrim((string)pc_config('app_base_url', 'https://xn--80avljg2a1c.xn--p1ai'), '/');
        $from = (string)pc_config('mail_from', 'register@плюсчат.рф');
        $link = $baseUrl . '/api/v1/auth/verify?token=' . rawurlencode($token);
        $subject = 'Подтверждение почты Pluschat';
        $body = "Здравствуйте, {$username}!\n\nПодтвердите адрес электронной почты для Pluschat по ссылке:\n{$link}\n\nСсылка действует 24 часа. Если вы не регистрировались, просто проигнорируйте это письмо.";
        $headers = [
            'From: Pluschat <' . $from . '>',
            'Content-Type: text/plain; charset=UTF-8',
            'X-Mailer: Pluschat PHP',
        ];
        return @mail($email, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
    }
}
