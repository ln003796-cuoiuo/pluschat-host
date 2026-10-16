<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/password/services/PasswordResetService.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
$token=is_string($_POST['token']??$_GET['token']??null)?trim((string)($_POST['token']??$_GET['token'])):'';
$message='';$success=false;
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){$r=PCPasswordResetService::reset($token,$_POST['password']??null);$success=$r['status']===200;$message=$success?'Пароль изменён. Теперь можно войти в Pluschat.':'Ссылка недействительна или срок её действия истёк. Запросите восстановление ещё раз.';}
$valid=(bool)preg_match('/^[a-f0-9]{64}$/i',$token);
function pc_h(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Восстановление Pluschat</title><style>:root{color-scheme:dark;font-family:system-ui,sans-serif;background:#0a0b0e;color:#f4f5f7}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:22px}main{width:min(100%,440px);padding:30px;border:1px solid #30343d;border-radius:24px;background:#17191f}h1{font-size:25px;margin:0 0 10px}p{color:#aab0bc;line-height:1.5}label{display:block;margin:20px 0 8px;font-weight:650}input{width:100%;padding:14px;border-radius:12px;border:1px solid #424754;background:#0d0f13;color:#fff;font:inherit}button{width:100%;padding:14px;margin-top:18px;border:0;border-radius:12px;background:#f4f5f7;color:#111;font:inherit;font-weight:750}small{color:#9299a5}.msg{padding:12px;border-radius:12px;background:#252933}</style></head><body><main><h1>Новый пароль</h1><p>Создайте новый пароль для своего аккаунта Pluschat. Ссылка действует один час.</p><?php if($message!==''): ?><p class="msg"><?=pc_h($message)?></p><?php endif; ?><?php if(!$success&&$valid): ?><form method="post" action="/password-reset.php"><input type="hidden" name="token" value="<?=pc_h($token)?>"><label for="password">Новый пароль</label><input id="password" name="password" type="password" minlength="10" maxlength="200" autocomplete="new-password" required><small>Не менее 10 символов.</small><button type="submit">Сохранить пароль</button></form><?php elseif(!$success): ?><p class="msg">Ссылка отсутствует или некорректна. Запросите восстановление пароля ещё раз.</p><?php endif; ?></main></body></html>
