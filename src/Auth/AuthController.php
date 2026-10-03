<?php
declare(strict_types=1);
namespace PlusChat\Auth;
use PlusChat\Database;
use PlusChat\Http\Request;
use PlusChat\Http\Response;
use PlusChat\Security\Security;
final class AuthController {
  public function register(Request $r):Response{
    $d=$r->json();$email=filter_var($d['email']??'',FILTER_VALIDATE_EMAIL);$password=(string)($d['password']??'');
    if(!$email||strlen($password)<10)return Response::json(['error'=>['code'=>'INVALID_INPUT']],422);
    $db=Database::pdo();$q=$db->prepare('SELECT id FROM users WHERE email=?');$q->execute([$email]);if($q->fetch())return Response::json(['error'=>['code'=>'EMAIL_EXISTS']],409);
    $db->beginTransaction();try{
      $name=trim((string)($d['display_name']??''))?:'Новый пользователь';
      $q=$db->prepare('INSERT INTO users(email,display_name,password_hash,email_verified) VALUES(?,?,?,false) RETURNING id');$q->execute([$email,$name,Security::password($password)]);$id=(int)$q->fetchColumn();
      $code=(string)random_int(100000,999999);$db->prepare('INSERT INTO email_verifications(user_id,code_hash,expires_at) VALUES(?,?,NOW()+INTERVAL \'15 minutes\')')->execute([$id,password_hash($code,PASSWORD_DEFAULT)]);$db->commit();
      (new Mailer())->verification($email,$name,$code);return Response::json(['ok'=>true,'next'=>'verify_email'],201);
    }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  }
  public function verifyEmail(Request $r):Response{
    $d=$r->json();$email=filter_var($d['email']??'',FILTER_VALIDATE_EMAIL);$code=(string)($d['code']??'');if(!$email||!preg_match('/^\d{6}$/',$code))return Response::json(['error'=>['code'=>'INVALID_CODE']],422);
    $db=Database::pdo();$q=$db->prepare('SELECT u.id,v.id verification_id,v.code_hash FROM users u JOIN email_verifications v ON v.user_id=u.id WHERE u.email=? AND v.used_at IS NULL AND v.expires_at>NOW() ORDER BY v.id DESC LIMIT 1');$q->execute([$email]);$row=$q->fetch();
    if(!$row||!password_verify($code,$row['code_hash']))return Response::json(['error'=>['code'=>'INVALID_CODE']],422);
    $db->beginTransaction();$db->prepare('UPDATE users SET email_verified=true,updated_at=NOW() WHERE id=?')->execute([$row['id']]);$db->prepare('UPDATE email_verifications SET used_at=NOW() WHERE id=?')->execute([$row['verification_id']]);$db->commit();return Response::json(['ok'=>true,'next'=>'profile']);
  }
  public function login(Request $r):Response{
    $d=$r->json();$email=filter_var($d['email']??'',FILTER_VALIDATE_EMAIL);$password=(string)($d['password']??'');$q=Database::pdo()->prepare('SELECT id,password_hash,email_verified,is_blocked FROM users WHERE email=?');$q->execute([$email]);$u=$q->fetch();
    if(!$u||!password_verify($password,$u['password_hash'])||$u['is_blocked'])return Response::json(['error'=>['code'=>'INVALID_CREDENTIALS']],401);
    if(!$u['email_verified'])return Response::json(['error'=>['code'=>'EMAIL_NOT_VERIFIED']],403);
    $raw=Security::token();Database::pdo()->prepare('INSERT INTO sessions(token_hash,user_id,expires_at) VALUES(?,?,NOW()+INTERVAL \'30 days\')')->execute([hash('sha256',$raw,true),$u['id']]);return Response::json(['ok'=>true,'token'=>$raw,'user_id'=>(int)$u['id']]);
  }
}
