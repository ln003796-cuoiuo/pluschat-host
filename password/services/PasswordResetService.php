<?php
declare(strict_types=1);
require_once __DIR__ . '/../repositories/PasswordResetRepository.php';
require_once __DIR__ . '/../mail/Mailer.php';
final class PCPasswordResetService {
 public static function request(mixed $raw):array{$email=is_string($raw)?strtolower(trim($raw)):'';if(!filter_var($email,FILTER_VALIDATE_EMAIL))return ['status'=>422,'body'=>['ok'=>false,'error'=>'invalid_email']];$r=PCPasswordResetRepository::create(pc_db(),$email);if($r){$base=rtrim((string)pc_config('app_base_url','https://xn--80avljg2a1c.xn--p1ai'),'/');$link=$base.'/password-reset.php?token='.rawurlencode($r['token']);PCPasswordResetMailer::send($r['email'],$r['username'],$link);}return ['status'=>200,'body'=>['ok'=>true,'message'=>'Если подтверждённый аккаунт существует, письмо для восстановления будет отправлено.']];}
 public static function reset(mixed $rawToken,mixed $rawPassword):array{$token=is_string($rawToken)?trim($rawToken):'';$password=is_string($rawPassword)?$rawPassword:'';if(!preg_match('/^[a-f0-9]{64}$/i',$token))return ['status'=>422,'body'=>['ok'=>false,'error'=>'invalid_or_expired_reset_token']];if(strlen($password)<10||strlen($password)>200)return ['status'=>422,'body'=>['ok'=>false,'error'=>'invalid_password_length']];$ok=PCPasswordResetRepository::reset(pc_db(),$token,$password);return $ok?['status'=>200,'body'=>['ok'=>true,'password_reset'=>true,'message'=>'Пароль изменён. Войдите заново на своих устройствах.']]:['status'=>400,'body'=>['ok'=>false,'error'=>'invalid_or_expired_reset_token']];}
}
