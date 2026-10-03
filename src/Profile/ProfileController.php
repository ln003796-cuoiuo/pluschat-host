<?php
declare(strict_types=1);
namespace PlusChat\Profile;
use PlusChat\Auth\Auth;
use PlusChat\Database;
use PlusChat\Http\Request;
use PlusChat\Http\Response;
final class ProfileController {
  public function complete(Request $r):Response {
    $u=Auth::user($r);if(!$u)return Response::json(['error'=>['code'=>'UNAUTHORIZED']],401);
    $d=$r->json();$first=trim((string)($d['first_name']??''));$last=trim((string)($d['last_name']??''));$username=strtolower(trim((string)($d['username']??'')));
    if(!preg_match('/^[a-z0-9_]{4,32}$/',$username)||$first===''||$last==='')return Response::json(['error'=>['code'=>'INVALID_PROFILE']],422);
    if(isset($d['birth_date'])&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$d['birth_date']))return Response::json(['error'=>['code'=>'INVALID_BIRTH_DATE']],422);
    $db=Database::pdo();$q=$db->prepare('SELECT id FROM users WHERE username=? AND id<>?');$q->execute([$username,$u['id']]);if($q->fetch())return Response::json(['error'=>['code'=>'USERNAME_EXISTS']],409);
    $location=null;
    if(($d['location_permission']??false)===true&&is_array($d['location']??null)){
      $lat=(float)($d['location']['lat']??0);$lon=(float)($d['location']['lon']??0);
      if($lat>=-90&&$lat<=90&&$lon>=-180&&$lon<=180)$location=json_encode(['lat'=>$lat,'lon'=>$lon,'shared'=>true],JSON_UNESCAPED_UNICODE);
    }
    $school=is_array($d['school_or_work']??null)?json_encode($d['school_or_work'],JSON_UNESCAPED_UNICODE):null;
    $q=$db->prepare('UPDATE users SET username=?,first_name=?,last_name=?,birth_date=?,location=?,school_or_work=?,updated_at=NOW() WHERE id=?');
    $q->execute([$username,$first,$last,$d['birth_date']??null,$location,$school,$u['id']]);
    if($location!==null)$this->joinLocationGroup($db,$u['id'],$location);
    if($school!==null)$this->joinSchoolGroup($db,$u['id'],$d['school_or_work']);
    return Response::json(['ok'=>true,'user_id'=>(int)$u['id'],'next'=>'app']);
  }
  private function joinLocationGroup(\PDO $db,int $uid,string $location):void {
    // Location is matched only against explicitly configured communities; coordinates are never reverse-geocoded by guessing.
    $data=json_decode($location,true);$lat=$data['lat']??null;$lon=$data['lon']??null;
    $q=$db->prepare('SELECT chat_id FROM location_communities WHERE chat_id IS NOT NULL AND ABS(?-?::double precision)<0');
    // No automatic membership is granted until the server has an approved location mapping.
    unset($q,$lat,$lon,$uid);
  }
  private function joinSchoolGroup(\PDO $db,int $uid,array $school):void {
    $key=trim((string)($school['class_key']??$school['workplace_key']??''));if($key==='')return;
    $q=$db->prepare('SELECT chat_id FROM community_groups WHERE kind IN (\'school\',\'work\') AND key_name=? LIMIT 1');$q->execute([$key]);$chat=$q->fetchColumn();
    if($chat)$db->prepare("INSERT INTO chat_members(chat_id,user_id,role) VALUES(?,?,?) ON CONFLICT DO NOTHING")->execute([(int)$chat,$uid,'member']);
  }
}
