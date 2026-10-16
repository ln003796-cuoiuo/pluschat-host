<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/services/PollService.php';
function pc_polls_dispatch(string $path,string $method):bool {
 if(preg_match('#^/api/v1/conversations/([0-9]+)/polls$#',$path,$m)&&$method==='POST'){pc_rate_limit('poll-create',30,3600);$u=pc_require_verified_user();$r=PCPollService::create((int)$u['id'],(int)$m[1],pc_input());pc_json($r['status'],$r['body']);}
 if(preg_match('#^/api/v1/polls/([0-9]+)$#',$path,$m)&&$method==='GET'){$u=pc_require_verified_user();$r=PCPollService::get((int)$u['id'],(int)$m[1]);pc_json($r['status'],$r['body']);}
 if(preg_match('#^/api/v1/polls/([0-9]+)/vote$#',$path,$m)&&$method==='POST'){pc_rate_limit('poll-vote',120,3600);$u=pc_require_verified_user();$d=pc_input();$r=PCPollService::vote((int)$u['id'],(int)$m[1],$d['option_ids']??null);pc_json($r['status'],$r['body']);}
 return false;
}
