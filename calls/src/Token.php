<?php
declare(strict_types=1);
namespace PlusChat\Calls;
final class Token{
 public static function verify(string $token):?array{
  $parts=explode('.',$token);if(count($parts)!==3)return null;$secret=(string)(getenv('CALL_SHARED_SECRET')?:$_ENV['CALL_SHARED_SECRET']??'');if(strlen($secret)<32)return null;
  $calc=rtrim(strtr(base64_encode(hash_hmac('sha256',$parts[0].'.'.$parts[1],$secret,true)),'+/','-_'),'=');if(!hash_equals($calc,$parts[2]))return null;
  $json=base64_decode(strtr($parts[1],'-_','+/'));$p=json_decode($json?:'',true);if(!is_array($p)||!isset($p['uid'],$p['exp'])||time()>(int)$p['exp'])return null;return $p;
 }
}
