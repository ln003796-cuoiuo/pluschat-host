<?php
declare(strict_types=1);

final class PCVerificationMailer
{
    public static function send(string $email, string $username, string $code): bool
    {
        $from = (string)pc_config('mail_from', 'register@плюсчат.рф');
        $subject = 'Код подтверждения Pluschat';
        $safeName = preg_replace('/[\\r\\n]+/', '', $username);
        $body = "Здравствуйте, {$safeName}!\n\nВаш код подтверждения Pluschat: {$code}\n\nКод действует 15 минут и может быть использован только один раз. Никому не сообщайте его. Если вы не регистрировались, проигнорируйте это письмо.";
        $headers = [
            'From: Pluschat <' . $from . '>',
            'Content-Type: text/plain; charset=UTF-8',
            'X-Mailer: Pluschat PHP',
        ];
        return @mail($email, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
    }
}
