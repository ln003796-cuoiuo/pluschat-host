<?php
declare(strict_types=1);
require_once __DIR__ . '/../repositories/ChatPreferenceRepository.php';
final class PCChatPreferenceService {
 public static function all(int $uid):array{return ['status'=>200,'body'=>['ok'=>true,'preferences'=>PCChatPreferenceRepository::preferences(pc_db(),$uid)]];}
 public static function set(int $uid,int $cid,array $d):array{$pinned=$d['pinned']??false;$archived=$d['archived']??false;if(!is_bool($pinned)||!is_bool($archived))return ['status'=>422,'body'=>['ok'=>false,'error'=>'invalid_chat_preferences']];$muted=null;if(isset($d['muted_until'])&&$d['muted_until']!==null&&$d['muted_until']!==''){if(!is_string($d['muted_until'])||strtotime($d['muted_until'])===false)return ['status'=>422,'body'=>['ok'=>false,'error'=>'invalid_mute_time']];$muted=gmdate('Y-m-d H:i:s',strtotime($d['muted_until']));}return PCChatPreferenceRepository::setPreferences(pc_db(),$uid,$cid,['pinned'=>$pinned,'archived'=>$archived,'muted_until'=>$muted]);}
 public static function folders(int $uid):array{return ['status'=>200,'body'=>['ok'=>true,'folders'=>PCChatPreferenceRepository::folders(pc_db(),$uid)]];}
 public static function createFolder(int $uid,mixed $raw):array{$name=is_string($raw)?trim($raw):'';if($name===''||pc_len($name)>40)return ['status'=>422,'body'=>['ok'=>false,'error'=>'invalid_folder_name']];return PCChatPreferenceRepository::createFolder(pc_db(),$uid,$name);}
 public static function deleteFolder(int $uid,int $id):array{if($id<1)return ['status'=>422,'body'=>['ok'=>false,'error'=>'invalid_folder_id']];return PCChatPreferenceRepository::deleteFolder(pc_db(),$uid,$id);}
 public static function addItem(int $uid,int $folder,int $cid):array{if($folder<1||$cid<1)return ['status'=>422,'body'=>['ok'=>false,'error'=>'invalid_folder_item']];return PCChatPreferenceRepository::addItem(pc_db(),$uid,$folder,$cid);}
 public static function removeItem(int $uid,int $folder,int $cid):array{if($folder<1||$cid<1)return ['status'=>422,'body'=>['ok'=>false,'error'=>'invalid_folder_item']];return PCChatPreferenceRepository::removeItem(pc_db(),$uid,$folder,$cid);}
}
