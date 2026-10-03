<?php
declare(strict_types=1);
namespace PlusChat;
use PlusChat\Auth\Auth;use PlusChat\Http\Request;use PlusChat\Http\Response;
final class AdminController{
 private function a(Request $r):array{$u=Auth::user($r);if(!$u)throw new \RuntimeException('unauthorized');if(!in_array($u['admin_role']??'user',['super_admin','admin','moderator','support','developer','analyst'],true))throw new \RuntimeException('forbidden');return $u;}
 public function dashboard(Request $r):Response{$this->a($r);$p=Database::pdo();$counts=[];foreach(['users','chats','messages','files','reports','security_events','app_errors'] as $t)$counts[$t]=(int)$p->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();return Response::json(['counts'=>$counts,'time'=>gmdate('c')]);}
 public function users(Request $r):Response{$this->a($r);$q=trim((string)($r->query['q']??''));$p=Database::pdo();if($q==='')$s=$p->query('SELECT id,email,username,display_name,email_verified,is_blocked,admin_role,created_at FROM users ORDER BY id DESC LIMIT 100');else{$s=$p->prepare("SELECT id,email,username,display_name,email_verified,is_blocked,admin_role,created_at FROM users WHERE email ILIKE :q OR username ILIKE :q OR display_name ILIKE :q ORDER BY id DESC LIMIT 100");$s->execute(['q'=>'%'.$q.'%']);}return Response::json(['users'=>$s->fetchAll()]);}
 public function errors(Request $r):Response{$this->a($r);$s=Database::pdo()->query('SELECT * FROM app_errors ORDER BY created_at DESC LIMIT 200');return Response::json(['errors'=>$s->fetchAll()]);}
 public function security(Request $r):Response{$this->a($r);$s=Database::pdo()->query('SELECT * FROM security_events ORDER BY created_at DESC LIMIT 200');return Response::json(['events'=>$s->fetchAll()]);}
 public function reports(Request $r):Response{$this->a($r);$s=Database::pdo()->query('SELECT * FROM reports WHERE resolved_at IS NULL ORDER BY created_at DESC LIMIT 200');return Response::json(['reports'=>$s->fetchAll()]);}
 public function blockUser(Request $r):Response{$this->a($r);$b=$r->json();Database::pdo()->prepare('UPDATE users SET is_blocked=:b,updated_at=NOW() WHERE id=:u')->execute(['b'=>(bool)$b['blocked'],'u'=>(int)$b['user_id']]);return Response::json(['ok'=>true]);}
}
