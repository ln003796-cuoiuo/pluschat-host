<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
$token=(string)($_GET['token']??'');
$expected=(string)(getenv('INSTALL_TOKEN')?:'');
$to=filter_var($_GET['to']??'',FILTER_VALIDATE_EMAIL);
if($expected===''||!hash_equals($expected,$token)||!$to){http_response_code(403);exit('Access denied');}
try{(new PlusChat\Auth\Mailer())->verification($to,'PlusChat test','123456');echo 'OK';}catch(Throwable $e){http_response_code(500);echo 'Mail test failed';}
