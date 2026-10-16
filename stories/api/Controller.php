<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/services/StoryService.php';
function pc_stories_dispatch(string $path,string $method):bool {
 if($path==='/api/v1/stories'&&$method==='GET'){$u=pc_require_verified_user();$r=PCStoryService::feed((int)$u['id']);pc_json($r['status'],$r['body']);}
 if($path==='/api/v1/stories'&&$method==='POST'){pc_rate_limit('story-create',20,3600);$u=pc_require_verified_user();$r=PCStoryService::create((int)$u['id'],pc_input());pc_json($r['status'],$r['body']);}
 if(preg_match('#^/api/v1/stories/([0-9]+)/view$#',$path,$m)&&$method==='POST'){$u=pc_require_verified_user();$r=PCStoryService::view((int)$u['id'],(int)$m[1]);pc_json($r['status'],$r['body']);}
 if(preg_match('#^/api/v1/stories/([0-9]+)$#',$path,$m)&&$method==='DELETE'){$u=pc_require_verified_user();$r=PCStoryService::delete((int)$u['id'],(int)$m[1]);pc_json($r['status'],$r['body']);}
 return false;
}
