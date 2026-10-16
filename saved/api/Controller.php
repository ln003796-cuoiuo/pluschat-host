<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/services/SavedMessageService.php';
function pc_saved_dispatch(string $path,string $method):bool {
 if($path==='/api/v1/saved-messages' && $method==='GET') { $u=pc_require_verified_user(); $r=PCSavedMessageService::all((int)$u['id']); pc_json($r['status'],$r['body']); }
 if(preg_match('#^/api/v1/messages/([0-9]+)/save$#',$path,$m) && $method==='POST') { $u=pc_require_verified_user(); $r=PCSavedMessageService::save((int)$u['id'],(int)$m[1]); pc_json($r['status'],$r['body']); }
 if(preg_match('#^/api/v1/messages/([0-9]+)/save$#',$path,$m) && $method==='DELETE') { $u=pc_require_verified_user(); $r=PCSavedMessageService::remove((int)$u['id'],(int)$m[1]); pc_json($r['status'],$r['body']); }
 return false;
}
