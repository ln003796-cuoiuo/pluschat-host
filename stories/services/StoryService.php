<?php
declare(strict_types=1);
require_once __DIR__ . '/../repositories/StoryRepository.php';
final class PCStoryService {
 public static function feed(int $uid):array{return ['status'=>200,'body'=>['ok'=>true,'stories'=>PCStoryRepository::feed(pc_db(),$uid)]];}
 public static function create(int $uid,array $d):array{$e=pc_encrypted_envelope($d['payload']??null);if(!$e)return ['status'=>422,'body'=>['ok'=>false,'error'=>'encrypted_story_payload_required','format'=>'pc-e2ee-v1']];$v=$d['visibility']??'friends';if(!is_string($v)||!in_array($v,['friends','everyone'],true))return ['status'=>422,'body'=>['ok'=>false,'error'=>'invalid_story_visibility']];$hours=$d['expires_in_hours']??24;if(!is_int($hours)||$hours<1||$hours>48)return ['status'=>422,'body'=>['ok'=>false,'error'=>'invalid_story_expiry']];$id=PCStoryRepository::create(pc_db(),$uid,$e['body'],$v,$hours);return ['status'=>201,'body'=>['ok'=>true,'story_id'=>(string)$id,'expires_in_hours'=>$hours]];}
 public static function view(int $uid,int $sid):array{if($sid<1||!PCStoryRepository::canView(pc_db(),$uid,$sid))return ['status'=>404,'body'=>['ok'=>false,'error'=>'story_not_found']];$p=pc_db();$s=$p->prepare('SELECT author_id FROM stories WHERE id=?');$s->execute([$sid]);$author=(int)$s->fetchColumn();if($author!==$uid)PCStoryRepository::markViewed($p,$uid,$sid);return ['status'=>200,'body'=>['ok'=>true,'viewed'=>true]];}
 public static function delete(int $uid,int $sid):array{if($sid<1)return ['status'=>422,'body'=>['ok'=>false,'error'=>'invalid_story_id']];$ok=PCStoryRepository::delete(pc_db(),$uid,$sid);return $ok?['status'=>200,'body'=>['ok'=>true,'deleted'=>true]]:['status'=>404,'body'=>['ok'=>false,'error'=>'story_not_found']];}
}
