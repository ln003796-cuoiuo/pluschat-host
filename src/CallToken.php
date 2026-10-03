<?php
declare(strict_types=1);
namespace PlusChat;
final class CallToken { public static function issue(array $u):string{$secret=(string)(getenv('CALL_SHARED_SECRET')?:'');$h=self::b64(json_encode(['alg'=>'HS256','typ'=>'PCT']));$p=self::b64(json_encode(['uid'=>(int)$u['id'],'sid'=>$u['session_id'],'exp'=>time()+300,'jti'=>bin2hex(random_bytes(16))]));$s=self::b64(hash_hmac('sha256',$h.'.'.$p,$secret,true));return $h.'.'.$p.'.'.$s;} private static function b64(string $s):string{return rtrim(strtr(base64_encode($s),'+/','-_'),'=');}}