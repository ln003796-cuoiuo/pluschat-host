<?php
declare(strict_types=1);
namespace PlusChat\Security;
final class Security {
  public static function headers():void{
    header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(self), microphone=(self), geolocation=(self)');
    header('Content-Security-Policy: default-src \'none\'; frame-ancestors \'none\'; base-uri \'none\'');header('Cache-Control: no-store');
  }
  public static function password(string $p):string{return password_hash($p,PASSWORD_ARGON2ID);}
  public static function token():string{return rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');}
}
