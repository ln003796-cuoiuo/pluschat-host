<?php
declare(strict_types=1);
final class PCPasswordResetMailer {
 public static function send(string $email,string $username,string $link):bool {
  $from=(string)pc_config('mail_from','register@плюсчат.рф');
  $subject='Pluschat account recovery';
  $body="Hello, {$username}. Use this one-time link to continue account recovery: {$link}\nThis link expires in one hour. Ignore this message if you did not request it.";
  $headers='From: Pluschat <'.$from.">\r\nContent-Type: text/plain; charset=UTF-8";
  return mail($email,'=?UTF-8?B?'.base64_encode($subject).'?=',$body,$headers);
 }
}
