<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/services/PasswordResetService.php';
function pc_password_dispatch(string $path,string $method):bool {
 if($path==='/api/v1/auth/password/forgot'&&$method==='POST'){pc_rate_limit('password-reset-request',4,3600);$d=pc_input();$r=PCPasswordResetService::request($d['email']??null);pc_json($r['status'],$r['body']);}
 if($path==='/api/v1/auth/password/reset'&&$method==='POST'){pc_rate_limit('password-reset-submit',10,3600);$d=pc_input();$r=PCPasswordResetService::reset($d['token']??null,$d['password']??null);pc_json($r['status'],$r['body']);}
 return false;
}
