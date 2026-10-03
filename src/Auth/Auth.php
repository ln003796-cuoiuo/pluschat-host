<?php
declare(strict_types=1);
namespace PlusChat\Auth;
use PlusChat\Database;use PlusChat\Http\Request;
final class Auth {
 public static function user(Request $r):?array{$h=$r->headers['authorization']??'';if(!preg_match('/^Bearer\\s+(.+)$/i',$h,$m))return null;$q=Database::pdo()->prepare('SELECT u.*,s.id AS session_id FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token_hash=? AND s.revoked_at IS NULL AND s.expires_at>NOW() AND u.is_blocked=FALSE');$q->execute([hash('sha256',$m[1],true)]);$u=$q->fetch();if(!$u)return null;return $u?:null;}
}
