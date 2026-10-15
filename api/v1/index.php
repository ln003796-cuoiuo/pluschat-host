<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
date_default_timezone_set('UTC');

function respond(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(int $status, string $code, string $message): never {
    respond($status, ['error' => ['code' => $code, 'message' => $message]]);
}
set_exception_handler(static function (Throwable $e): void {
    error_log('Pluschat API uncaught exception: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode(['error'=>['code'=>'internal_error','message'=>'Внутренняя ошибка шлюза.']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
});
function input(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) fail(400, 'invalid_json', 'Ожидался JSON-объект.');
    return $data;
}
function random_id(int $bytes = 32): string { return bin2hex(random_bytes($bytes)); }
function hash_token(string $value): string { return hash('sha256', $value); }
function code_hash(string $id, string $code): string { return hash_hmac('sha256', $id . ':' . $code, (string)($GLOBALS['config']['db_password'] ?? '')); }
function user_public(array $u): array {
    return ['id'=>(string)$u['id'], 'phone'=>$u['phone'], 'email'=>$u['email'], 'first_name'=>$u['first_name'],
        'last_name'=>$u['last_name'], 'username'=>$u['username'], 'bio'=>$u['bio'], 'birth_date'=>$u['birth_date'],
        'education'=>$u['education'], 'created_at'=>$u['created_at']];
}
function send_email_code(string $email, string $code): bool {
    $from = (string)$GLOBALS['config']['mail_from'];
    $name = (string)$GLOBALS['config']['mail_from_name'];
    $subject = 'Код подтверждения Pluschat';
    $message = "Твой код подтверждения Pluschat: {$code}\n\nКод действует 10 минут. Если ты не начинал регистрацию, просто проигнорируй это письмо.";
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    $headers .= 'From: ' . mb_encode_mimeheader($name, 'UTF-8') . " <{$from}>\r\n";
    return @mail($email, mb_encode_mimeheader($subject, 'UTF-8'), $message, $headers);
}
function send_sms_code(array $config, string $phone, string $code): bool {
    $url = trim((string)$config['sms_api_url']);
    $token = trim((string)$config['sms_api_token']);
    if ($url === '' || $token === '' || !function_exists('curl_init')) return false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_POSTFIELDS => json_encode(['to'=>$phone, 'message'=>"Код Pluschat: {$code}. Действует 10 минут."], JSON_UNESCAPED_UNICODE),
    ]);
    $result = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $result !== false && $status >= 200 && $status < 300;
}
function new_challenge(PDO $db, string $kind, string $destination, ?string $parent = null): array {
    $id = random_id();
    $code = (string)random_int(100000, 999999);
    $hash = code_hash($id, $code);
    $stmt = $db->prepare('INSERT INTO auth_challenges (id,kind,destination,code_hash,parent_challenge_id,expires_at) VALUES (?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE))');
    $stmt->execute([$id, $kind, $destination, $hash, $parent]);
    return ['challenge_id'=>$id, 'code'=>$code];
}
function verify_challenge(PDO $db, string $id, string $code, string $kind): array {
    $stmt = $db->prepare('SELECT * FROM auth_challenges WHERE id=? AND kind=? LIMIT 1');
    $stmt->execute([$id, $kind]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$c || $c['verified_at'] !== null || strtotime((string)$c['expires_at']) < time() || (int)$c['attempts'] >= 5) {
        fail(400, 'challenge_expired', 'Код истёк или попытки закончились. Запроси новый код.');
    }
    $db->prepare('UPDATE auth_challenges SET attempts=attempts+1 WHERE id=?')->execute([$id]);
    if (!hash_equals((string)$c['code_hash'], code_hash($id, trim($code)))) fail(400, 'invalid_code', 'Неверный код подтверждения.');
    $db->prepare('UPDATE auth_challenges SET verified_at=UTC_TIMESTAMP() WHERE id=?')->execute([$id]);
    return $c;
}
function create_session(PDO $db, int $userId, array $config): array {
    $access = random_id(32); $refresh = random_id(48);
    $db->prepare('INSERT INTO sessions (user_id,access_hash,refresh_hash,access_expires_at,refresh_expires_at) VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND),DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))')
       ->execute([$userId, hash_token($access), hash_token($refresh), (int)$config['access_ttl'], (int)$config['refresh_ttl']]);
    return ['access_token'=>$access, 'refresh_token'=>$refresh, 'expires_in'=>(int)$config['access_ttl']];
}
function auth_user(PDO $db): array {
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $m)) fail(401, 'unauthorized', 'Войди в аккаунт.');
    $stmt = $db->prepare('SELECT u.* FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.access_hash=? AND s.revoked_at IS NULL AND s.access_expires_at>UTC_TIMESTAMP() LIMIT 1');
    $stmt->execute([hash_token($m[1])]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) fail(401, 'session_expired', 'Сессия истекла. Войди снова.');
    return $u;
}
function require_member(PDO $db, int $chatId, int $userId): void {
    $q = $db->prepare('SELECT 1 FROM chat_members WHERE chat_id=? AND user_id=?');
    $q->execute([$chatId, $userId]);
    if (!$q->fetchColumn()) fail(404, 'chat_not_found', 'Чат не найден.');
}
function require_role(PDO $db, int $chatId, int $userId, array $roles): string {
    $q = $db->prepare('SELECT role FROM chat_members WHERE chat_id=? AND user_id=? LIMIT 1');
    $q->execute([$chatId, $userId]);
    $role = $q->fetchColumn();
    if ($role === false) fail(404, 'community_not_found', 'Сообщество не найдено.');
    if (!in_array((string)$role, $roles, true)) fail(403, 'forbidden', 'Недостаточно прав для этого действия.');
    return (string)$role;
}

try {
    $config = require dirname(__DIR__, 2) . '/config.php';
    $GLOBALS['config'] = $config;
    if (empty($config['db_password'])) fail(503, 'server_not_configured', 'На сервере не настроен пароль базы данных.');
    $dsn = 'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4';
    $db = new PDO($dsn, $config['db_user'], $config['db_password'], [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
} catch (Throwable $e) {
    if ($e instanceof PDOException) fail(503, 'database_unavailable', 'База данных недоступна. Проверь настройки хостинга.');
    throw $e;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = '/api/v1/';
$pos = strpos($path, $base);
$route = $pos === false ? trim($path, '/') : trim(substr($path, $pos + strlen($base)), '/');
$method = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? $_SERVER['REQUEST_METHOD'] ?? 'GET');
$data = input();

if ($method === 'GET' && $route === 'health') respond(200, ['ok'=>true, 'service'=>'pluschat-api', 'version'=>1]);

if ($method === 'POST' && $route === 'auth/registration/start') {
    $phone = preg_replace('/[^+0-9]/', '', trim((string)($data['phone'] ?? '')));
    if (strlen($phone) < 8 || strlen($phone) > 16 || !str_starts_with($phone, '+')) fail(422, 'invalid_phone', 'Укажи номер в международном формате, например +79991234567.');
    $rate = $db->prepare("SELECT COUNT(*) FROM auth_challenges WHERE kind='phone' AND destination=? AND created_at>DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)");
    $rate->execute([$phone]);
    if ((int)$rate->fetchColumn() >= 3) fail(429, 'too_many_requests', 'Слишком много запросов кода. Попробуй позже.');
    $challenge = new_challenge($db, 'phone', $phone);
    if (!send_sms_code($config, $phone, $challenge['code'])) {
        $db->prepare('DELETE FROM auth_challenges WHERE id=?')->execute([$challenge['challenge_id']]);
        fail(503, 'sms_unavailable', 'SMS-провайдер не подключён или отклонил отправку. Настрой PLUSCHAT_SMS_API_URL и PLUSCHAT_SMS_API_TOKEN на хостинге.');
    }
    unset($challenge['code']);
    respond(200, $challenge + ['expires_in'=>600]);
}
if ($method === 'POST' && $route === 'auth/registration/verify-phone') {
    $c = verify_challenge($db, (string)($data['challenge_id'] ?? ''), (string)($data['code'] ?? ''), 'phone');
    respond(200, ['verified'=>true, 'challenge_id'=>$c['id']]);
}
if ($method === 'POST' && $route === 'auth/registration/email/start') {
    $parent = trim((string)($data['challenge_id'] ?? ''));
    if ($parent !== '') {
        $q = $db->prepare("SELECT * FROM auth_challenges WHERE id=? AND kind='phone' AND verified_at IS NOT NULL AND expires_at>UTC_TIMESTAMP() LIMIT 1");
        $q->execute([$parent]); $phone = $q->fetch();
        if (!$phone) fail(400, 'phone_not_verified', 'Сначала подтверди номер телефона.');
    }
    $email = strtolower(trim((string)($data['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail(422, 'invalid_email', 'Укажи корректный email.');
    $rate = $db->prepare("SELECT COUNT(*) FROM auth_challenges WHERE kind='email' AND destination=? AND created_at>DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)");
    $rate->execute([$email]);
    if ((int)$rate->fetchColumn() >= 3) fail(429, 'too_many_requests', 'Слишком много запросов кода на этот адрес. Попробуй позже.');
    $q = $db->prepare('SELECT id FROM users WHERE email=?'); $q->execute([$email]);
    if ($q->fetchColumn()) fail(409, 'email_exists', 'Этот email уже зарегистрирован.');
    $challenge = new_challenge($db, 'email', $email, $parent);
    if (!send_email_code($email, $challenge['code'])) {
        $db->prepare('DELETE FROM auth_challenges WHERE id=?')->execute([$challenge['challenge_id']]);
        fail(503, 'email_unavailable', 'Не удалось отправить письмо. Проверь почту и настройку отправки mail() на хостинге.');
    }
    unset($challenge['code']);
    respond(200, $challenge + ['expires_in'=>600]);
}
if ($method === 'POST' && $route === 'auth/registration/email/verify') {
    $c = verify_challenge($db, (string)($data['challenge_id'] ?? ''), (string)($data['code'] ?? ''), 'email');
    respond(200, ['verified'=>true, 'challenge_id'=>$c['id']]);
}
if ($method === 'POST' && $route === 'auth/registration/complete') {
    $phoneId = trim((string)($data['challenge_id'] ?? ''));
    $emailId = trim((string)($data['email_challenge_id'] ?? ''));
    $phone = null;
    if ($phoneId !== '') {
        $phoneQ = $db->prepare("SELECT * FROM auth_challenges WHERE id=? AND kind='phone' AND verified_at IS NOT NULL AND expires_at>UTC_TIMESTAMP() LIMIT 1");
        $phoneQ->execute([$phoneId]); $phone = $phoneQ->fetch();
    }
    $emailQ = $db->prepare("SELECT * FROM auth_challenges WHERE id=? AND kind='email' AND verified_at IS NOT NULL AND expires_at>UTC_TIMESTAMP() LIMIT 1");
    $emailQ->execute([$emailId]); $email = $emailQ->fetch();
    if (!$email || ($phoneId !== '' && (!$phone || $email['parent_challenge_id'] !== $phoneId))) fail(400, 'verification_required', 'Подтверди email перед созданием аккаунта.');
    $fn = trim((string)($data['first_name'] ?? '')); $ln = trim((string)($data['last_name'] ?? ''));
    $username = ltrim(trim((string)($data['username'] ?? '')), '@');
    $password = (string)($data['password'] ?? '');
    if ($fn === '' || $ln === '' || !preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username) || strlen($password) < 10) fail(422, 'invalid_profile', 'Проверь имя, фамилию, username (3–32 символа: латиница, цифры, _) и пароль (от 10 символов).');
    if (str_contains($password, "\0")) fail(422, 'invalid_password', 'Недопустимый пароль.');
    $birth = trim((string)($data['birth_date'] ?? ''));
    if ($birth !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth)) fail(422, 'invalid_birth_date', 'Дата рождения должна быть в формате YYYY-MM-DD.');
    $birth = $birth === '' ? null : $birth;
    try {
        $stmt = $db->prepare('INSERT INTO users (phone,email,password_hash,first_name,last_name,username,bio,birth_date,education,email_verified_at) VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())');
        $stmt->execute([$phone ? $phone['destination'] : null, $email['destination'], password_hash($password, PASSWORD_DEFAULT), $fn, $ln, $username, mb_substr(trim((string)($data['bio'] ?? '')),0,500), $birth, mb_substr(trim((string)($data['education'] ?? '')),0,160)]);
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') fail(409, 'account_exists', 'Телефон, email или username уже заняты.');
        throw $e;
    }
    $userId = (int)$db->lastInsertId();
    $stmt = $db->prepare('SELECT * FROM users WHERE id=?'); $stmt->execute([$userId]); $user = $stmt->fetch();
    if ($phoneId !== '') $db->prepare('DELETE FROM auth_challenges WHERE id=?')->execute([$phoneId]);
    $db->prepare('DELETE FROM auth_challenges WHERE id=?')->execute([$emailId]);
    respond(201, ['user'=>user_public($user)] + create_session($db, $userId, $config));
}
if ($method === 'POST' && $route === 'auth/login') {
    $identifier = trim((string)($data['identifier'] ?? '')); $password = (string)($data['password'] ?? '');
    $stmt = $db->prepare('SELECT * FROM users WHERE email=? OR phone=? LIMIT 1'); $stmt->execute([strtolower($identifier), $identifier]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) fail(401, 'invalid_credentials', 'Неверный телефон/email или пароль.');
    respond(200, ['user'=>user_public($user)] + create_session($db, (int)$user['id'], $config));
}
if ($method === 'POST' && $route === 'auth/token/refresh') {
    $refresh = trim((string)($data['refresh_token'] ?? ''));
    $q = $db->prepare('SELECT * FROM sessions WHERE refresh_hash=? AND revoked_at IS NULL AND refresh_expires_at>UTC_TIMESTAMP() LIMIT 1');
    $q->execute([hash_token($refresh)]); $s = $q->fetch();
    if (!$s) fail(401, 'invalid_refresh_token', 'Сессия истекла. Войди снова.');
    $access = random_id(32); $newRefresh = random_id(48);
    $db->prepare('UPDATE sessions SET access_hash=?,refresh_hash=?,access_expires_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND),refresh_expires_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND) WHERE id=?')
       ->execute([hash_token($access),hash_token($newRefresh),(int)$config['access_ttl'],(int)$config['refresh_ttl'],$s['id']]);
    respond(200, ['access_token'=>$access,'refresh_token'=>$newRefresh,'expires_in'=>(int)$config['access_ttl']]);
}
if ($method === 'POST' && $route === 'auth/logout') {
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $m)) $db->prepare('UPDATE sessions SET revoked_at=UTC_TIMESTAMP() WHERE access_hash=?')->execute([hash_token($m[1])]);
    respond(200, ['ok'=>true]);
}

$user = auth_user($db);
$userId = (int)$user['id'];

if ($method === 'GET' && $route === 'me') respond(200, ['user'=>user_public($user)]);
if ($method === 'PATCH' && $route === 'me') {
    $fn = trim((string)($data['first_name'] ?? $user['first_name']));
    $ln = trim((string)($data['last_name'] ?? $user['last_name']));
    $bio = mb_substr(trim((string)($data['bio'] ?? $user['bio'])),0,500);
    $username = ltrim(trim((string)($data['username'] ?? $user['username'])), '@');
    $birth = trim((string)($data['birth_date'] ?? ($user['birth_date'] ?? '')));
    if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) fail(422, 'invalid_username', 'Username: 3–32 символа, латиница, цифры и _.');
    if ($birth !== '' && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $birth)) fail(422, 'invalid_birth_date', 'Дата рождения должна быть в формате YYYY-MM-DD.');
    try {
        $db->prepare('UPDATE users SET first_name=?,last_name=?,username=?,bio=?,birth_date=?,education=? WHERE id=?')->execute([$fn,$ln,$username,$bio,$birth === '' ? null : $birth,mb_substr(trim((string)($data['education'] ?? $user['education'])),0,160),$userId]);
    } catch (PDOException $e) { if ((string)$e->getCode() === '23000') fail(409, 'username_exists', 'Этот username уже занят.'); throw $e; }
    $q=$db->prepare('SELECT * FROM users WHERE id=?');$q->execute([$userId]);respond(200,['user'=>user_public($q->fetch())]);
}
if ($method === 'GET' && $route === 'users/search') {
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) respond(200, ['users'=>[]]);
    $like = '%' . str_replace(['%','_'], ['\\%','\\_'], $q) . '%';
    $stmt = $db->prepare('SELECT id,first_name,last_name,username FROM users WHERE id<>? AND (username LIKE ? OR first_name LIKE ? OR last_name LIKE ?) ORDER BY username LIMIT 30');
    $stmt->execute([$userId,$like,$like,$like]);
    respond(200, ['users'=>array_map(fn($u)=>['id'=>(string)$u['id'],'first_name'=>$u['first_name'],'last_name'=>$u['last_name'],'username'=>$u['username']],$stmt->fetchAll())]);
}
if ($method === 'GET' && $route === 'chats') {
    $stmt=$db->prepare("SELECT c.id,c.type,CASE WHEN c.type='direct' THEN COALESCE((SELECT CONCAT(u.first_name,' ',u.last_name,' · @',u.username) FROM chat_members other JOIN users u ON u.id=other.user_id WHERE other.chat_id=c.id AND other.user_id<>? LIMIT 1),'Личный чат') ELSE c.title END AS title,(SELECT m.body FROM messages m WHERE m.chat_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_message FROM chats c JOIN chat_members cm ON cm.chat_id=c.id WHERE cm.user_id=? ORDER BY COALESCE((SELECT MAX(m.id) FROM messages m WHERE m.chat_id=c.id),c.id) DESC LIMIT 100");
    $stmt->execute([$userId,$userId]);respond(200,['chats'=>$stmt->fetchAll()]);
}
if ($method === 'POST' && $route === 'chats') {
    if (($data['type'] ?? '') !== 'direct') fail(422,'unsupported_chat_type','Пока доступны только личные чаты.');
    $other=(int)($data['user_id'] ?? 0);if($other<1||$other===$userId)fail(422,'invalid_user','Выбери другого пользователя.');
    $q=$db->prepare('SELECT id FROM users WHERE id=?');$q->execute([$other]);if(!$q->fetchColumn())fail(404,'user_not_found','Пользователь не найден.');
    $q=$db->prepare("SELECT c.id FROM chats c JOIN chat_members a ON a.chat_id=c.id AND a.user_id=? JOIN chat_members b ON b.chat_id=c.id AND b.user_id=? WHERE c.type='direct' LIMIT 1");$q->execute([$userId,$other]);$existing=$q->fetchColumn();
    if($existing)respond(200,['id'=>(string)$existing,'chat_id'=>(string)$existing,'chat'=>['id'=>(string)$existing]]);
    $db->beginTransaction();
    try{$db->prepare("INSERT INTO chats (type,title,created_by) VALUES ('direct','',?)")->execute([$userId]);$chatId=(int)$db->lastInsertId();$db->prepare('INSERT INTO chat_members (chat_id,user_id) VALUES (?,?),(?,?)')->execute([$chatId,$userId,$chatId,$other]);$db->commit();}
    catch(Throwable $e){$db->rollBack();throw $e;}
    respond(201,['id'=>(string)$chatId,'chat_id'=>(string)$chatId,'chat'=>['id'=>(string)$chatId,'type'=>'direct']]);
}
if (preg_match('#^chats/(\d+)$#', $route, $m) && $method === 'GET') {
    $chatId=(int)$m[1];require_member($db,$chatId,$userId);
    $q=$db->prepare('SELECT c.id,c.type,c.title FROM chats c WHERE c.id=?');$q->execute([$chatId]);respond(200,['chat'=>$q->fetch()]);
}
if (preg_match('#^chats/(\d+)/messages$#', $route, $m)) {
    $chatId=(int)$m[1];require_member($db,$chatId,$userId);
    if($method==='GET'){
        $limit=max(1,min(100,(int)($_GET['limit']??50)));
        $before=max(0,(int)($_GET['before']??0));
        $sql='SELECT m.id,m.chat_id,m.sender_id,m.client_message_id,m.type,m.body,m.created_at,u.username AS sender_username,u.username AS sender_name,CASE WHEN m.sender_id=? THEN 1 ELSE 0 END AS is_mine FROM messages m JOIN users u ON u.id=m.sender_id WHERE m.chat_id=?';
        $params=[$userId,$chatId];if($before>0){$sql.=' AND m.id<?';$params[]=$before;}
        $sql.=' ORDER BY m.id DESC LIMIT '.$limit;
        $stmt=$db->prepare($sql);$stmt->execute($params);$rows=array_reverse($stmt->fetchAll());foreach($rows as &$row){$row['is_mine']=(bool)$row['is_mine'];}unset($row);respond(200,['messages'=>$rows]);
    }
    if($method==='POST'){
        $body=trim((string)($data['body']??''));$clientId=trim((string)($data['client_message_id']??''));
        if($body===''||mb_strlen($body)>10000)fail(422,'invalid_message','Сообщение должно содержать от 1 до 10000 символов.');
        if(!preg_match('/^[a-f0-9-]{36}$/i',$clientId))fail(422,'invalid_client_message_id','Некорректный ID сообщения.');
        $stmt=$db->prepare("INSERT INTO messages (chat_id,sender_id,client_message_id,type,body) VALUES (?,?,?,'text',?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
        $stmt->execute([$chatId,$userId,$clientId,$body]);$messageId=(int)$db->lastInsertId();
        $q=$db->prepare('SELECT id,chat_id,sender_id,client_message_id,type,body,created_at FROM messages WHERE id=?');$q->execute([$messageId]);respond(201,['message'=>$q->fetch()]);
    }
}

if ($method === 'GET' && $route === 'contacts') {
    $stmt = $db->prepare('SELECT u.id,u.first_name,u.last_name,u.username,c.alias,c.created_at AS added_at FROM contacts c JOIN users u ON u.id=c.contact_id WHERE c.owner_id=? ORDER BY COALESCE(c.alias,u.username),u.username LIMIT 500');
    $stmt->execute([$userId]);
    respond(200, ['contacts'=>$stmt->fetchAll()]);
}
if ($method === 'POST' && $route === 'contacts') {
    $target = (int)($data['user_id'] ?? 0);
    if ($target < 1 || $target === $userId) fail(422, 'invalid_contact', 'Выбери другого пользователя.');
    $q = $db->prepare('SELECT id FROM users WHERE id=?');
    $q->execute([$target]);
    if (!$q->fetchColumn()) fail(404, 'user_not_found', 'Пользователь не найден.');
    $db->prepare('INSERT IGNORE INTO contacts(owner_id,contact_id,alias) VALUES(?,?,?)')->execute([$userId,$target,mb_substr(trim((string)($data['alias'] ?? '')),0,120) ?: null]);
    respond(200, ['ok'=>true]);
}
if (preg_match('#^contacts/(\\d+)$#', $route, $m) && $method === 'DELETE') {
    $db->prepare('DELETE FROM contacts WHERE owner_id=? AND contact_id=?')->execute([$userId,(int)$m[1]]);
    respond(200, ['ok'=>true]);
}
if ($method === 'GET' && $route === 'notifications') {
    $stmt = $db->prepare('SELECT id,type,payload,read_at,created_at FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 100');
    $stmt->execute([$userId]);
    respond(200, ['notifications'=>$stmt->fetchAll()]);
}
if (preg_match('#^notifications/(\\d+)/read$#', $route, $m) && $method === 'POST') {
    $db->prepare('UPDATE notifications SET read_at=UTC_TIMESTAMP() WHERE id=? AND user_id=? AND read_at IS NULL')->execute([(int)$m[1],$userId]);
    respond(200, ['ok'=>true]);
}
if ($method === 'POST' && $route === 'notifications/read-all') {
    $db->prepare('UPDATE notifications SET read_at=UTC_TIMESTAMP() WHERE user_id=? AND read_at IS NULL')->execute([$userId]);
    respond(200, ['ok'=>true]);
}
if ($route === 'me/avatar' && $method === 'POST') {
    if (!isset($_FILES['avatar']) || !is_uploaded_file($_FILES['avatar']['tmp_name'])) fail(422, 'avatar_missing', 'Выбери изображение для загрузки.');
    $file = $_FILES['avatar'];
    if ((int)$file['error'] !== UPLOAD_ERR_OK) fail(422, 'avatar_upload_failed', 'Не удалось принять файл аватара.');
    if ((int)$file['size'] < 1 || (int)$file['size'] > 5 * 1024 * 1024) fail(413, 'avatar_too_large', 'Размер аватара не должен превышать 5 МБ.');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $extensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if (!isset($extensions[$mime])) fail(415, 'avatar_type_not_supported', 'Поддерживаются только JPEG, PNG и WebP.');
    $avatarDir = dirname(__DIR__, 2) . '/storage/avatars';
    if (!is_dir($avatarDir) && !mkdir($avatarDir, 0750, true) && !is_dir($avatarDir)) fail(500, 'storage_unavailable', 'Хранилище файлов недоступно.');
    $filename = bin2hex(random_bytes(24)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $avatarDir . '/' . $filename)) fail(500, 'avatar_save_failed', 'Не удалось сохранить аватар.');
    $old = (string)($user['avatar_path'] ?? '');
    $db->prepare('UPDATE users SET avatar_path=? WHERE id=?')->execute([$filename,$userId]);
    if ($old !== '' && basename($old) === $old) @unlink($avatarDir . '/' . $old);
    respond(200, ['ok'=>true, 'avatar_url'=>'me/avatar']);
}
if ($route === 'me/avatar' && $method === 'GET') {
    $filename = basename((string)($user['avatar_path'] ?? ''));
    $filePath = dirname(__DIR__, 2) . '/storage/avatars/' . $filename;
    if ($filename === '' || !is_file($filePath)) fail(404, 'avatar_not_found', 'Аватар не загружен.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
    if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) fail(415, 'avatar_type_not_supported', 'Неподдерживаемый формат аватара.');
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=300');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
}


if ($route === 'groups' && $method === 'GET') {
    $stmt = $db->prepare("SELECT c.id,c.id AS group_id,c.title,COALESCE(g.description,c.description,'') AS description,IF(g.is_public=1,'public','private') AS visibility,g.topics_enabled FROM chats c JOIN `groups` g ON g.chat_id=c.id JOIN chat_members cm ON cm.chat_id=c.id WHERE cm.user_id=? ORDER BY c.id DESC LIMIT 200");
    $stmt->execute([$userId]);
    respond(200, ['groups'=>$stmt->fetchAll()]);
}
if ($route === 'groups' && $method === 'POST') {
    $title = trim((string)($data['title'] ?? ''));
    $description = mb_substr(trim((string)($data['description'] ?? '')), 0, 4000);
    $visibility = strtolower(trim((string)($data['visibility'] ?? 'private')));
    if ($title === '' || mb_strlen($title) > 120 || !in_array($visibility, ['private','public'], true)) fail(422, 'invalid_group', 'Укажи название до 120 символов и видимость private или public.');
    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO chats(type,title,description,created_by) VALUES('group',?,?,?)")->execute([$title,$description,$userId]);
        $id = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO chat_members(chat_id,user_id,role) VALUES(?,?, 'owner')")->execute([$id,$userId]);
        $db->prepare('INSERT INTO `groups`(chat_id,is_public,topics_enabled,description) VALUES(?,?,1,?)')->execute([$id,$visibility==='public'?1:0,$description]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    respond(201, ['id'=>(string)$id,'group_id'=>(string)$id,'title'=>$title,'description'=>$description]);
}
if (preg_match('#^groups/(\\d+)$#', $route, $m) && $method === 'PATCH') {
    $id = (int)$m[1]; require_role($db,$id,$userId,['owner','admin']);
    $title = trim((string)($data['title'] ?? '')); $description = mb_substr(trim((string)($data['description'] ?? '')),0,4000);
    if ($title === '' || mb_strlen($title)>120) fail(422,'invalid_group','Название должно содержать от 1 до 120 символов.');
    $db->prepare("UPDATE chats SET title=?,description=? WHERE id=? AND type='group'")->execute([$title,$description,$id]);
    $db->prepare('UPDATE `groups` SET description=? WHERE chat_id=?')->execute([$description,$id]);
    respond(200,['ok'=>true,'id'=>(string)$id,'title'=>$title,'description'=>$description]);
}
if (preg_match('#^groups/(\\d+)/topics$#', $route, $m)) {
    $id=(int)$m[1]; require_member($db,$id,$userId);
    if ($method==='GET') {
        $q=$db->prepare('SELECT id,chat_id,title,description,created_at FROM topics WHERE chat_id=? ORDER BY id DESC LIMIT 200');
        $q->execute([$id]); respond(200,['topics'=>$q->fetchAll()]);
    }
    if ($method==='POST') {
        require_role($db,$id,$userId,['owner','admin','moderator']);
        $title=trim((string)($data['title']??''));$description=mb_substr(trim((string)($data['description']??'')),0,4000);
        if($title===''||mb_strlen($title)>120)fail(422,'invalid_topic','Название темы должно содержать от 1 до 120 символов.');
        $q=$db->prepare("SELECT 1 FROM chats WHERE id=? AND type='group'");$q->execute([$id]);if(!$q->fetchColumn())fail(404,'group_not_found','Группа не найдена.');
        $db->prepare('INSERT INTO topics(chat_id,title,description,created_by) VALUES(?,?,?,?)')->execute([$id,$title,$description,$userId]);
        respond(201,['id'=>(string)$db->lastInsertId(),'chat_id'=>(string)$id,'title'=>$title,'description'=>$description]);
    }
}
if (preg_match('#^groups/(\\d+)/members$#', $route, $m)) {
    $id=(int)$m[1];
    if ($method==='GET') {
        require_member($db,$id,$userId);
        $q=$db->prepare('SELECT u.id,u.first_name,u.last_name,u.username,cm.role,cm.joined_at FROM chat_members cm JOIN users u ON u.id=cm.user_id WHERE cm.chat_id=? ORDER BY cm.joined_at LIMIT 500');
        $q->execute([$id]);respond(200,['members'=>$q->fetchAll()]);
    }
    if ($method==='POST') {
        require_role($db,$id,$userId,['owner','admin','moderator']);
        $target=(int)($data['user_id']??0);if($target<1||$target===$userId)fail(422,'invalid_user','Укажи ID другого пользователя.');
        $q=$db->prepare('SELECT id FROM users WHERE id=?');$q->execute([$target]);if(!$q->fetchColumn())fail(404,'user_not_found','Пользователь не найден.');
        $q=$db->prepare("SELECT 1 FROM chats WHERE id=? AND type='group'");$q->execute([$id]);if(!$q->fetchColumn())fail(404,'group_not_found','Группа не найдена.');
        $db->prepare("INSERT IGNORE INTO chat_members(chat_id,user_id,role) VALUES(?,?,'member')")->execute([$id,$target]);
        respond(200,['ok'=>true]);
    }
}
if ($route === 'channels' && $method === 'GET') {
    $stmt=$db->prepare("SELECT c.id,c.id AS channel_id,c.title,COALESCE(ch.description,c.description,'') AS description,IF(ch.is_public=1,'public','private') AS visibility FROM chats c JOIN channels ch ON ch.chat_id=c.id JOIN chat_members cm ON cm.chat_id=c.id WHERE cm.user_id=? ORDER BY c.id DESC LIMIT 200");
    $stmt->execute([$userId]);respond(200,['channels'=>$stmt->fetchAll()]);
}
if ($route === 'channels' && $method === 'POST') {
    $title=trim((string)($data['title']??''));$description=mb_substr(trim((string)($data['description']??'')),0,4000);
    $visibility=strtolower(trim((string)($data['visibility']??'private')));
    if($title===''||mb_strlen($title)>120||!in_array($visibility,['private','public'],true))fail(422,'invalid_channel','Укажи название до 120 символов и видимость private или public.');
    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO chats(type,title,description,created_by) VALUES('channel',?,?,?)")->execute([$title,$description,$userId]);
        $id=(int)$db->lastInsertId();
        $db->prepare("INSERT INTO chat_members(chat_id,user_id,role) VALUES(?,?,'owner')")->execute([$id,$userId]);
        $db->prepare('INSERT INTO channels(chat_id,is_public,description) VALUES(?,?,?)')->execute([$id,$visibility==='public'?1:0,$description]);
        $db->commit();
    } catch(Throwable $e) { if($db->inTransaction())$db->rollBack(); throw $e; }
    respond(201,['id'=>(string)$id,'channel_id'=>(string)$id,'title'=>$title,'description'=>$description]);
}
if (preg_match('#^channels/(\\d+)$#', $route, $m) && $method === 'PATCH') {
    $id=(int)$m[1];require_role($db,$id,$userId,['owner','admin']);
    $title=trim((string)($data['title']??''));$description=mb_substr(trim((string)($data['description']??'')),0,4000);
    if($title===''||mb_strlen($title)>120)fail(422,'invalid_channel','Название должно содержать от 1 до 120 символов.');
    $db->prepare("UPDATE chats SET title=?,description=? WHERE id=? AND type='channel'")->execute([$title,$description,$id]);
    $db->prepare('UPDATE channels SET description=? WHERE chat_id=?')->execute([$description,$id]);
    respond(200,['ok'=>true,'id'=>(string)$id,'title'=>$title,'description'=>$description]);
}
if (preg_match('#^channels/(\\d+)/posts$#', $route, $m)) {
    $id=(int)$m[1];require_member($db,$id,$userId);
    if($method==='GET') {
        $q=$db->prepare('SELECT p.id,p.channel_id,p.body,p.created_at,u.username,u.first_name,u.last_name FROM channel_posts p JOIN users u ON u.id=p.sender_id WHERE p.channel_id=? ORDER BY p.id DESC LIMIT 100');
        $q->execute([$id]);$rows=array_reverse($q->fetchAll());respond(200,['posts'=>$rows]);
    }
    if($method==='POST') {
        require_role($db,$id,$userId,['owner','admin']);
        $body=trim((string)($data['body']??''));if($body===''||mb_strlen($body)>20000)fail(422,'invalid_post','Текст публикации должен содержать от 1 до 20000 символов.');
        $q=$db->prepare("SELECT 1 FROM chats WHERE id=? AND type='channel'");$q->execute([$id]);if(!$q->fetchColumn())fail(404,'channel_not_found','Канал не найден.');
        $db->prepare('INSERT INTO channel_posts(channel_id,sender_id,body) VALUES(?,?,?)')->execute([$id,$userId,$body]);
        respond(201,['id'=>(string)$db->lastInsertId(),'channel_id'=>(string)$id,'body'=>$body]);
    }
}
if (preg_match('#^channels/(\\d+)/subscribers$#', $route, $m) && $method === 'GET') {
    $id=(int)$m[1];require_member($db,$id,$userId);
    $q=$db->prepare('SELECT u.id,u.first_name,u.last_name,u.username,cm.role,cm.joined_at FROM chat_members cm JOIN users u ON u.id=cm.user_id WHERE cm.chat_id=? ORDER BY cm.joined_at LIMIT 1000');
    $q->execute([$id]);respond(200,['subscribers'=>$q->fetchAll()]);
}

fail(404,'route_not_found','Такого API-маршрута нет.');
