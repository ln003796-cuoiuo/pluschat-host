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

        $safeName = trim(preg_replace('/[\\r\\n]+/', '', $username) ?? '');
        if ($safeName === '') $safeName = 'друг';
        $safeNameHtml = htmlspecialchars($safeName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeCode = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $subject = 'Код подтверждения Pluschat';

        $plain = "Здравствуйте, {$safeName}!\n\nВаш код подтверждения Pluschat: {$code}\n\nКод действует 15 минут и может быть использован только один раз. Никому не сообщайте его. Если вы не регистрировались в Pluschat, просто проигнорируйте это письмо.";
        $html = '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;padding:0;background:#f3f5f8;font-family:Arial,Helvetica,sans-serif;color:#171923;">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">Подтверди адрес электронной почты для Pluschat.</div>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f5f8;padding:32px 12px;"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:520px;background:#ffffff;border:1px solid #e4e7ed;border-radius:20px;overflow:hidden;">'
            . '<tr><td style="padding:28px 30px;background:#111318;color:#ffffff;"><table role="presentation" cellspacing="0" cellpadding="0"><tr>'
            . '<td style="width:42px;height:42px;border-radius:13px;background:#ffffff;color:#111318;text-align:center;vertical-align:middle;font-size:27px;font-weight:bold;">+</td>'
            . '<td style="padding-left:12px;font-size:22px;font-weight:700;letter-spacing:-.5px;">Pluschat</td></tr></table></td></tr>'
            . '<tr><td style="padding:32px 30px 12px;"><p style="margin:0 0 12px;font-size:14px;color:#697080;">ПОДТВЕРЖДЕНИЕ ПОЧТЫ</p>'
            . '<h1 style="margin:0 0 16px;font-size:26px;line-height:1.25;letter-spacing:-.6px;">Привет, ' . $safeNameHtml . '!</h1>'
            . '<p style="margin:0;font-size:16px;line-height:1.65;color:#4d5361;">Введи этот код в приложении Pluschat, чтобы подтвердить адрес электронной почты.</p></td></tr>'
            . '<tr><td style="padding:20px 30px 24px;"><div style="padding:20px 12px;border:1px solid #e4e7ed;border-radius:14px;background:#f8f9fb;text-align:center;">'
            . '<div style="font-size:12px;color:#727989;letter-spacing:1.5px;margin-bottom:8px;">ТВОЙ КОД</div>'
            . '<div style="font-size:34px;line-height:1.3;font-weight:800;letter-spacing:8px;color:#151821;">' . $safeCode . '</div></div>'
            . '<p style="margin:16px 0 0;font-size:14px;line-height:1.6;color:#626978;">Код действует <strong>15 минут</strong> и используется один раз. Не передавай его другим людям — даже если они представляются службой поддержки.</p></td></tr>'
            . '<tr><td style="padding:0 30px 28px;"><div style="height:1px;background:#eceef2;"></div><p style="margin:18px 0 0;font-size:12px;line-height:1.6;color:#858b98;">Если ты не создавал аккаунт Pluschat, просто проигнорируй это письмо. Никто не сможет подтвердить адрес без кода.</p></td></tr>'
            . '</table><p style="margin:18px 0 0;font-size:11px;color:#969ba6;">Pluschat · Личное общение. Больше контроля.</p>'
            . '</td></tr></table></body></html>';

        $boundary = 'pc_' . bin2hex(random_bytes(16));
        $headers = [
            'From: Pluschat <' . $from . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: Pluschat PHP',
        ];
        $body = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $plain . "\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html)) . "\r\n"
            . '--' . $boundary . "--\r\n";

        return @mail($email, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
    }
}
