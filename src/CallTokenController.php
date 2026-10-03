<?php
declare(strict_types=1);
namespace PlusChat;
use PlusChat\Auth\Auth;use PlusChat\Http\Request;use PlusChat\Http\Response;
final class CallTokenController{
 public function issue(Request $r):Response{
  $u=Auth::require($r);$b=$r->json();$call=(string)($b['call_id']??'');if(!preg_match('/^[A-Za-z0-9_-]{8,128}$/',$call))return Response::json(['error'=>'invalid_call_id'],422);
  $secret=(string)(getenv('CALL_SIGNING_SECRET')??'');if(strlen($secret)<32)return Response::json(['error'=>'calls_not_configured'],503);
  $head=rtrim(strtr(base64_encode('{"alg":"HS256","typ":"PCT"}'),'+/','-_'),'=');$payload=rtrim(strtr(base64_encode(json_encode(['sub'=>(int)$u['id'],'call_id'=>$call,'exp'=>time()+300],JSON_UNESCAPED_SLASHES)),'+/','-_'),'=');$sig=rtrim(strtr(base64_encode(hash_hmac('sha256',$head.'.'.$payload,$secret,true)),'+/','-_'),'=');return Response::json(['token'=>$head.'.'.$payload.'.'.$sig,'expires_in'=>300]);
 }
}
