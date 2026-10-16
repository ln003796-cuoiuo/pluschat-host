<?php
declare(strict_types=1);

final class PCVerificationMailer
{
    public static function send(string $email, string $username, string $code): bool
    {
        $from = (string)pc_config('mail_from', 'register@плюсчат.рф');
        $at = strrpos($from, '@');
        if ($at !== false) {
            $localPart = substr($from, 0, $at);
            $domain = substr($from, $at + 1);
            if (function_exists('idn_to_ascii')) {
                $asciiDomain = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
                if (is_string($asciiDomain) && $asciiDomain !== '') $domain = $asciiDomain;
            } elseif (preg_match('/[^\\x20-\\x7E]/', $domain)) {
                $domain = 'xn--80avljg2a1c.xn--p1ai';
            }
            $from = $localPart . '@' . $domain;
        }
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
