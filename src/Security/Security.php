<?php
declare(strict_types=1);
namespace PlusChat\Security;
final class Security {
 public static function headers():void{header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('Referrer-Policy: no-referrer');header('Permissions-Policy: camera=(self), microphone=(self), geolocation=(self)');header('Content-Security-Policy: default-src \'none\'; frame-ancestors \'none\'; base-uri \'none\'; form-action \'none\'');header('Cache-Control: no-store');}
 public static function hashPassword(string $p):string{return password_hash($p,PASSWORD_ARGON2ID,['memory_cost'=>65536,'time_cost'=>4,'threads'=>2]);}
 public static function password(string $p):string{return self::hashPassword($p);}
 public static function token(int $bytes=48):string{return rtrim(strtr(base64_encode(random_bytes($bytes)),'+/','-_'),'=');}
}
