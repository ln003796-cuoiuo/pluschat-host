<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/services/ChatPreferenceService.php';
function pc_chatprefs_dispatch(string $path,string $method):bool {
 if($path==='/api/v1/chat-preferences'&&$method==='GET'){$u=pc_require_verified_user();$r=PCChatPreferenceService::all((int)$u['id']);pc_json($r['status'],$r['body']);}
 if(preg_match('#^/api/v1/conversations/([0-9]+)/preferences$#',$path,$m)&&$method==='POST'){$u=pc_require_verified_user();$r=PCChatPreferenceService::set((int)$u['id'],(int)$m[1],pc_input());pc_json($r['status'],$r['body']);}
 if($path==='/api/v1/chat-folders'&&$method==='GET'){$u=pc_require_verified_user();$r=PCChatPreferenceService::folders((int)$u['id']);pc_json($r['status'],$r['body']);}
 if($path==='/api/v1/chat-folders'&&$method==='POST'){pc_rate_limit('chat-folder-create',30,3600);$u=pc_require_verified_user();$d=pc_input();$r=PCChatPreferenceService::createFolder((int)$u['id'],$d['name']??null);pc_json($r['status'],$r['body']);}
 if(preg_match('#^/api/v1/chat-folders/([0-9]+)$#',$path,$m)&&$method==='DELETE'){$u=pc_require_verified_user();$r=PCChatPreferenceService::deleteFolder((int)$u['id'],(int)$m[1]);pc_json($r['status'],$r['body']);}
 if(preg_match('#^/api/v1/chat-folders/([0-9]+)/items$#',$path,$m)&&$method==='POST'){$u=pc_require_verified_user();$d=pc_input();$cid=filter_var($d['conversation_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$r=PCChatPreferenceService::addItem((int)$u['id'],(int)$m[1],(int)($cid?:0));pc_json($r['status'],$r['body']);}
 if(preg_match('#^/api/v1/chat-folders/([0-9]+)/items/([0-9]+)$#',$path,$m)&&$method==='DELETE'){$u=pc_require_verified_user();$r=PCChatPreferenceService::removeItem((int)$u['id'],(int)$m[1],(int)$m[2]);pc_json($r['status'],$r['body']);}
 return false;
}
