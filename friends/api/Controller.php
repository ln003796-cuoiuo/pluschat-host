<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/services/FriendService.php';
function pc_friends_dispatch(string $path, string $method): bool {
 if ($path === '/api/v1/friends' && $method === 'GET') { $u=pc_require_verified_user(); $r=PCFriendService::list((int)$u['id']); pc_json($r['status'],$r['body']); }
 if ($path === '/api/v1/friends/requests' && $method === 'GET') { $u=pc_require_verified_user(); $r=PCFriendService::requests((int)$u['id'],$_GET['direction']??'incoming'); pc_json($r['status'],$r['body']); }
 if ($path === '/api/v1/friends/requests' && $method === 'POST') { pc_rate_limit('friend-request',40,3600); $u=pc_require_verified_user(); $d=pc_input(); $r=PCFriendService::request((int)$u['id'],$d['user_id']??null); pc_json($r['status'],$r['body']); }
 if (preg_match('#^/api/v1/friends/requests/([0-9]+)/(accept|decline)$#',$path,$m) && $method==='POST') { $u=pc_require_verified_user(); $r=PCFriendService::respond((int)$u['id'],(int)$m[1],$m[2]); pc_json($r['status'],$r['body']); }
 if (preg_match('#^/api/v1/friends/([0-9]+)$#',$path,$m) && $method==='DELETE') { $u=pc_require_verified_user(); $r=PCFriendService::remove((int)$u['id'],(int)$m[1]); pc_json($r['status'],$r['body']); }
 return false;
}
