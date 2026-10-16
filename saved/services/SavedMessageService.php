<?php
declare(strict_types=1);
require_once __DIR__ . '/../repositories/SavedMessageRepository.php';
final class PCSavedMessageService {
 public static function save(int $uid,int $mid):array { if($mid<1)return ['status'=>422,'body'=>['ok'=>false,'error'=>'invalid_message_id']]; return PCSavedMessageRepository::save(pc_db(),$uid,$mid); }
 public static function remove(int $uid,int $mid):array { if($mid<1)return ['status'=>422,'body'=>['ok'=>false,'error'=>'invalid_message_id']]; return PCSavedMessageRepository::remove(pc_db(),$uid,$mid); }
 public static function all(int $uid):array { return ['status'=>200,'body'=>['ok'=>true,'messages'=>PCSavedMessageRepository::list(pc_db(),$uid)]]; }
}
