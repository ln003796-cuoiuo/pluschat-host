<?php
declare(strict_types=1);
final class PCSavedMessageRepository {
 public static function save(PDO $pdo,int $uid,int $mid): array {
  $s=$pdo->prepare('SELECT m.id FROM messages m JOIN conversation_members c ON c.conversation_id=m.conversation_id AND c.user_id=? WHERE m.id=? LIMIT 1'); $s->execute([$uid,$mid]);
  if(!$s->fetchColumn()) return ['status'=>404,'body'=>['ok'=>false,'error'=>'message_not_found']];
  $pdo->prepare('INSERT IGNORE INTO saved_messages (user_id,message_id) VALUES (?,?)')->execute([$uid,$mid]);
  return ['status'=>200,'body'=>['ok'=>true,'saved'=>true,'message_id'=>(string)$mid]];
 }
 public static function remove(PDO $pdo,int $uid,int $mid): array {
  $pdo->prepare('DELETE FROM saved_messages WHERE user_id=? AND message_id=?')->execute([$uid,$mid]);
  return ['status'=>200,'body'=>['ok'=>true,'saved'=>false,'message_id'=>(string)$mid]];
 }
 public static function list(PDO $pdo,int $uid): array {
  $s=$pdo->prepare('SELECT m.id,m.conversation_id,m.sender_id,m.body,m.body_encrypted,m.encryption_version,m.created_at,sm.created_at AS saved_at FROM saved_messages sm JOIN messages m ON m.id=sm.message_id JOIN conversation_members cm ON cm.conversation_id=m.conversation_id AND cm.user_id=sm.user_id WHERE sm.user_id=? AND NOT EXISTS (SELECT 1 FROM message_hidden h WHERE h.message_id=m.id AND h.user_id=sm.user_id) ORDER BY sm.created_at DESC LIMIT 500'); $s->execute([$uid]);
  return array_map(static fn(array $m):array=>['id'=>(string)$m['id'],'conversation_id'=>(string)$m['conversation_id'],'sender_id'=>(string)$m['sender_id'],'body'=>$m['body'],'encrypted'=>(bool)$m['body_encrypted'],'encryption_version'=>$m['encryption_version'],'created_at'=>$m['created_at'],'saved_at'=>$m['saved_at']],$s->fetchAll());
 }
}
