<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Request-Id');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

function respond(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) respond(['error' => ['code' => 'INVALID_JSON']], 400);
    return $data;
}
function need_string(array $data, string $key, int $min, int $max): string {
    $v = trim((string)($data[$key] ?? ''));
    if (mb_strlen($v) < $min || mb_strlen($v) > $max) respond(['error'=>['code'=>'INVALID_'.$key]], 422);
    return $v;
}
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $file = __DIR__ . '/config.php';
    if (!is_file($file)) respond(['error'=>['code'=>'GATEWAY_NOT_CONFIGURED']], 503);
    $c = require $file;
    foreach (['db_host','db_name','db_user','db_password','token_pepper','base_url','mail_from'] as $key) {
        if (!isset($c[$key]) || $c[$key] === '' || str_contains((string)$c[$key], 'CHANGE_ME') || str_contains((string)$c[$key], 'REPLACE_WITH')) {
            respond(['error'=>['code'=>'GATEWAY_NOT_CONFIGURED','field'=>$key]], 503);
        }
    }
    try {
        $pdo = new PDO(
            'mysql:host='.$c['db_host'].';dbname='.$c['db_name'].';charset=utf8mb4',
            $c['db_user'],
            $c['db_password'],
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
        );
        return $pdo;
    } catch (Throwable $e) {
        error_log('Pluschat DB connection failed: '.$e->getMessage());
        respond(['error'=>['code'=>'DATABASE_UNAVAILABLE']], 503);
    }
}
function config(): array { return require __DIR__.'/config.php'; }
function hash_token(string $token): string { return hash_hmac('sha256', $token, (string)config()['token_pepper']); }
function current_user(): array {
    $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/^Bearer\s+([A-Za-z0-9._~-]{32,200})$/i', $h, $m)) respond(['error'=>['code'=>'UNAUTHORIZED']], 401);
    $s = db()->prepare('SELECT s.id AS session_id, u.id, u.email, u.username, u.first_name, u.last_name FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token_hash=? AND s.expires_at>UTC_TIMESTAMP() AND s.revoked_at IS NULL AND u.email_verified_at IS NOT NULL LIMIT 1');
    $s->execute([hash_token($m[1])]);
    $u = $s->fetch();
    if (!$u) respond(['error'=>['code'=>'UNAUTHORIZED']], 401);
    return $u;
}
function require_member(int $chatId, int $userId): void {
    $s = db()->prepare('SELECT 1 FROM chat_members WHERE chat_id=? AND user_id=?');
    $s->execute([$chatId, $userId]);
    if (!$s->fetchColumn()) respond(['error'=>['code'=>'CHAT_NOT_FOUND']], 404);
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    if ($path === '/' || $path === '/api/v1/health') {
        if ($method !== 'GET') respond(['error'=>['code'=>'METHOD_NOT_ALLOWED']], 405);
        respond(['ok'=>true,'service'=>'pluschat-gateway','api_version'=>'1','time'=>gmdate('c')]);
    }

    if ($path === '/api/v1/auth/register' && $method === 'POST') {
        $c = config();
        if (empty($c['allow_registration'])) respond(['error'=>['code'=>'REGISTRATION_CLOSED']], 403);
        $b = input();
        $email = mb_strtolower(need_string($b,'email',5,254));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['error'=>['code'=>'INVALID_EMAIL']], 422);
        $username = need_string($b,'username',3,32);
        if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) respond(['error'=>['code'=>'INVALID_USERNAME']], 422);
        $password = (string)($b['password'] ?? '');
        if (strlen($password) < 10 || strlen($password) > 200) respond(['error'=>['code'=>'PASSWORD_LENGTH']], 422);
        $first = trim((string)($b['first_name'] ?? ''));
        $last = trim((string)($b['last_name'] ?? ''));
        if (mb_strlen($first)>80 || mb_strlen($last)>80) respond(['error'=>['code'=>'INVALID_NAME']], 422);
        $token = bin2hex(random_bytes(32));
        $p = db();
        try {
            $p->beginTransaction();
            $s = $p->prepare('INSERT INTO users(email,username,password_hash,first_name,last_name,verification_hash,verification_expires_at) VALUES(?,?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE))');
            $s->execute([$email,$username,password_hash($password,PASSWORD_DEFAULT),$first,$last,hash_token($token)]);
            $p->commit();
        } catch (PDOException $e) {
            if ($p->inTransaction()) $p->rollBack();
            if ($e->getCode()==='23000') respond(['error'=>['code'=>'EMAIL_OR_USERNAME_TAKEN']], 409);
            throw $e;
        }
        $verifyUrl = rtrim($c['base_url'],'/').'/api/v1/auth/verify-email?token='.rawurlencode($token);
        $subject = 'Подтверждение почты Pluschat';
        $body = "Здравствуйте! Для подтверждения адреса электронной почты откройте ссылку (действует 30 минут):\n\n".$verifyUrl."\n\nЕсли вы не регистрировались в Pluschat, проигнорируйте это письмо.";
        $headers = 'From: '.$c['mail_from_name'].' <'.$c['mail_from'].'>'."\r\n".'Content-Type: text/plain; charset=UTF-8';
        if (!@mail($email, '=?UTF-8?B?'.base64_encode($subject).'?=', $body, $headers)) {
            error_log('Pluschat verification email could not be sent for user id '.$p->lastInsertId());
            respond(['ok'=>true,'verification_email_sent'=>false,'message'=>'Аккаунт создан, но письмо не отправлено. Проверьте SMTP/mail() на хостинге.'], 202);
        }
        respond(['ok'=>true,'verification_email_sent'=>true], 201);
    }

    if ($path === '/api/v1/auth/verify-email' && $method === 'GET') {
        $token = (string)($_GET['token'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) respond(['error'=>['code'=>'INVALID_OR_EXPIRED_TOKEN']], 400);
        $s = db()->prepare('UPDATE users SET email_verified_at=UTC_TIMESTAMP(), verification_hash=NULL, verification_expires_at=NULL WHERE verification_hash=? AND verification_expires_at>UTC_TIMESTAMP() AND email_verified_at IS NULL');
        $s->execute([hash_token($token)]);
        if ($s->rowCount() !== 1) respond(['error'=>['code'=>'INVALID_OR_EXPIRED_TOKEN']], 400);
        respond(['ok'=>true,'email_verified'=>true,'message'=>'Почта подтверждена. Теперь можно войти в приложение.']);
    }

    if ($path === '/api/v1/auth/login' && $method === 'POST') {
        $b = input();
        $email = mb_strtolower(need_string($b,'email',5,254));
        $password = (string)($b['password'] ?? '');
        $s = db()->prepare('SELECT id,email,username,password_hash,email_verified_at FROM users WHERE email=? LIMIT 1');
        $s->execute([$email]); $u = $s->fetch();
        if (!$u || !password_verify($password,(string)$u['password_hash'])) respond(['error'=>['code'=>'INVALID_CREDENTIALS']], 401);
        if (!$u['email_verified_at']) respond(['error'=>['code'=>'EMAIL_NOT_VERIFIED']], 403);
        $token = rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');
        $s = db()->prepare('INSERT INTO sessions(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY))');
        $s->execute([$u['id'],hash_token($token)]);
        respond(['ok'=>true,'access_token'=>$token,'token_type'=>'Bearer','expires_in'=>2592000,'user'=>['id'=>(int)$u['id'],'email'=>$u['email'],'username'=>$u['username']]]);
    }

    if ($path === '/api/v1/auth/logout' && $method === 'POST') {
        $u = current_user();
        db()->prepare('UPDATE sessions SET revoked_at=UTC_TIMESTAMP() WHERE id=?')->execute([$u['session_id']]);
        respond(['ok'=>true]);
    }

    if ($path === '/api/v1/me' && $method === 'GET') {
        $u = current_user(); unset($u['session_id']); $u['id']=(int)$u['id']; respond(['user'=>$u]);
    }

    if ($path === '/api/v1/chats' && $method === 'GET') {
        $u = current_user();
        $s = db()->prepare('SELECT c.id,c.type,c.title,c.created_at,cm.role FROM chats c JOIN chat_members cm ON cm.chat_id=c.id WHERE cm.user_id=? ORDER BY c.updated_at DESC,c.id DESC LIMIT 100');
        $s->execute([$u['id']]); respond(['chats'=>$s->fetchAll()]);
    }

    if ($path === '/api/v1/chats' && $method === 'POST') {
        $u = current_user(); $b = input();
        $type = (string)($b['type'] ?? 'group');
        if (!in_array($type,['direct','group','channel'],true)) respond(['error'=>['code'=>'INVALID_CHAT_TYPE']],422);
        $title = trim((string)($b['title'] ?? ''));
        if ($type !== 'direct' && ($title==='' || mb_strlen($title)>120)) respond(['error'=>['code'=>'INVALID_TITLE']],422);
        $p=db(); $p->beginTransaction();
        try {
            $s=$p->prepare('INSERT INTO chats(type,title,created_by) VALUES(?,?,?)');
            $s->execute([$type,$title,$u['id']]); $id=(int)$p->lastInsertId();
            $p->prepare('INSERT INTO chat_members(chat_id,user_id,role) VALUES(?,?,?)')->execute([$id,$u['id'],'owner']);
            $p->commit();
        } catch(Throwable $e) { if($p->inTransaction())$p->rollBack(); throw $e; }
        respond(['ok'=>true,'chat_id'=>$id],201);
    }

    if ($path === '/api/v1/chats/members' && $method === 'POST') {
        $u=current_user(); $b=input(); $chat=(int)($b['chat_id']??0); $target=(int)($b['user_id']??0);
        if ($chat<1 || $target<1) respond(['error'=>['code'=>'INVALID_MEMBER']],422);
        $s=db()->prepare("SELECT role FROM chat_members WHERE chat_id=? AND user_id=?"); $s->execute([$chat,$u['id']]); $role=$s->fetchColumn();
        if (!in_array($role,['owner','admin'],true)) respond(['error'=>['code'=>'FORBIDDEN']],403);
        $s=db()->prepare('SELECT id FROM users WHERE id=? AND email_verified_at IS NOT NULL'); $s->execute([$target]);
        if (!$s->fetchColumn()) respond(['error'=>['code'=>'USER_NOT_FOUND']],404);
        try { db()->prepare('INSERT INTO chat_members(chat_id,user_id,role) VALUES(?,?,?)')->execute([$chat,$target,'member']); }
        catch(PDOException $e) { if($e->getCode()!=='23000') throw $e; }
        respond(['ok'=>true]);
    }

    if (($path === '/api/v1/messages' && in_array($method,['GET','POST'],true))) {
        $u=current_user();
        if ($method==='GET') {
            $chat=(int)($_GET['chat_id']??0); $before=(int)($_GET['before_id']??0);
            if($chat<1) respond(['error'=>['code'=>'INVALID_CHAT_ID']],422);
            require_member($chat,(int)$u['id']);
            $sql='SELECT id,chat_id,sender_id,body,created_at,edited_at,deleted_at FROM messages WHERE chat_id=?'.($before>0?' AND id<?':'').' ORDER BY id DESC LIMIT 50';
            $s=db()->prepare($sql); $before>0?$s->execute([$chat,$before]):$s->execute([$chat]);
            respond(['messages'=>array_reverse($s->fetchAll())]);
        }
        $b=input(); $chat=(int)($b['chat_id']??0); $body=trim((string)($b['body']??''));
        if($chat<1 || $body==='' || mb_strlen($body)>10000) respond(['error'=>['code'=>'INVALID_MESSAGE']],422);
        require_member($chat,(int)$u['id']);
        $s=db()->prepare('INSERT INTO messages(chat_id,sender_id,body) VALUES(?,?,?)'); $s->execute([$chat,$u['id'],$body]);
        db()->prepare('UPDATE chats SET updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$chat]);
        respond(['ok'=>true,'message_id'=>(int)db()->lastInsertId()],201);
    }

    respond(['error'=>['code'=>'NOT_FOUND']],404);
} catch (Throwable $e) {
    error_log('Pluschat gateway error: '.$e->getMessage());
    respond(['error'=>['code'=>'INTERNAL_ERROR']],500);
}
