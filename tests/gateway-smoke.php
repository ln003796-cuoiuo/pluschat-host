<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/config.php';
$dsn = 'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4';
$db = new PDO($dsn, $config['db_user'], $config['db_password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
if ($schema === false) throw new RuntimeException('Cannot read database/schema.sql');
foreach (preg_split('/;\\s*(?:\\r?\\n|$)/', $schema) as $statement) {
    $statement = trim($statement);
    if ($statement !== '') $db->exec($statement);
}

function api(string $method, string $path, ?array $payload = null, ?string $token = null, array $expected = [200, 201]): array {
    $headers = ['Accept: application/json'];
    if ($payload !== null) $headers[] = 'Content-Type: application/json; charset=utf-8';
    if ($token !== null && $token !== '') $headers[] = 'Authorization: Bearer ' . $token;
    $options = ['http'=>[
        'method'=>$method,
        'header'=>implode("\r\n", $headers),
        'ignore_errors'=>true,
        'timeout'=>10,
    ]];
    if ($payload !== null) $options['http']['content'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $context = stream_context_create($options);
    $body = file_get_contents('http://127.0.0.1:8080/api/v1/' . ltrim($path, '/'), false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $header) if (preg_match('#^HTTP/\\S+\\s+(\\d+)#', $header, $m)) $status = (int)$m[1];
    $json = is_string($body) && $body !== '' ? json_decode($body, true) : null;
    if (!in_array($status, $expected, true)) throw new RuntimeException($method . ' ' . $path . ' returned HTTP ' . $status . ': ' . (is_string($body) ? $body : 'empty response'));
    if (!is_array($json)) throw new RuntimeException($method . ' ' . $path . ' returned invalid JSON');
    return ['status'=>$status,'body'=>$json];
}
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('Assertion failed: ' . $message);
}

api('GET', 'health', null, null, [200]);
$email = 'gateway-test-' . bin2hex(random_bytes(3)) . '@example.test';
$challengeId = bin2hex(random_bytes(32));
$code = '314159';
$hash = hash_hmac('sha256', $challengeId . ':' . $code, (string)$config['db_password']);
$db->prepare("INSERT INTO auth_challenges(id,kind,destination,code_hash,expires_at) VALUES(?,'email',?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE))")
   ->execute([$challengeId,$email,$hash]);
api('POST','auth/registration/email/verify',['challenge_id'=>$challengeId,'code'=>$code]);
$registration = api('POST','auth/registration/complete',[
    'email_challenge_id'=>$challengeId,'email'=>$email,'password'=>'GatewayTestPass123!',
    'first_name'=>'Gateway','last_name'=>'Tester','username'=>'gw_' . bin2hex(random_bytes(4)),
    'bio'=>'CI smoke test','birth_date'=>'2000-01-01','education'=>''
]);
check(!empty($registration['body']['access_token']), 'registration returns access token');
$login = api('POST','auth/login',['identifier'=>$email,'password'=>'GatewayTestPass123!']);
$access = (string)$login['body']['access_token'];
$refresh = (string)$login['body']['refresh_token'];
check($access !== '' && $refresh !== '', 'login returns access and refresh tokens');

$friendEmail = 'friend-' . bin2hex(random_bytes(3)) . '@example.test';
$friendUsername = 'friend_' . bin2hex(random_bytes(3));
$db->prepare('INSERT INTO users(email,password_hash,first_name,last_name,username,email_verified_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())')
   ->execute([$friendEmail,password_hash('FriendPass123!', PASSWORD_DEFAULT),'Test','Friend',$friendUsername]);
$friendId = (int)$db->lastInsertId();

$rotated = api('POST','auth/token/refresh',['refresh_token'=>$refresh]);
$newAccess = (string)$rotated['body']['access_token'];
check($newAccess !== '' && $newAccess !== $access, 'refresh rotates access token');
api('GET','me',null,$access,[401]);
$access = $newAccess;
$me = api('GET','me',null,$access);
check(($me['body']['user']['email'] ?? '') === $email, 'profile endpoint returns current user');
api('PATCH','me',['first_name'=>'GatewayUpdated','last_name'=>'Tester','username'=>'updated_' . bin2hex(random_bytes(3)),'bio'=>'Updated bio','birth_date'=>'2000-01-01','education'=>'Test'], $access);
$search = api('GET','users/search?q=' . rawurlencode(substr($friendUsername,0,8)),null,$access);
check(count($search['body']['users'] ?? []) >= 1, 'user search returns matching user');

$contact = api('POST','contacts',['user_id'=>$friendId],$access);
$contacts = api('GET','contacts',null,$access);
check(count($contacts['body']['contacts'] ?? []) === 1, 'contacts endpoint lists saved contact');
api('GET','notifications',null,$access);
api('POST','notifications/read-all',[],$access);

$chat = api('POST','chats',['type'=>'direct','user_id'=>$friendId],$access);
$chatId = (string)($chat['body']['chat_id'] ?? $chat['body']['id'] ?? '');
check($chatId !== '', 'direct chat creation returns chat ID');
$messageId = '123e4567-e89b-12d3-a456-426614174000';
api('POST','chats/' . $chatId . '/messages',['client_message_id'=>$messageId,'type'=>'text','body'=>'Gateway smoke test'], $access);
$history = api('GET','chats/' . $chatId . '/messages?limit=50',null,$access);
check(count($history['body']['messages'] ?? []) >= 1, 'message history returns sent message');

$group = api('POST','groups',['title'=>'CI group','description'=>'Smoke test group','visibility'=>'private'],$access);
$groupId = (string)($group['body']['id'] ?? '');
check($groupId !== '', 'group creation returns ID');
api('GET','groups',null,$access);
api('POST','groups/' . $groupId . '/topics',['title'=>'First topic','description'=>'Topic test'],$access);
$topics = api('GET','groups/' . $groupId . '/topics',null,$access);
check(count($topics['body']['topics'] ?? []) === 1, 'group topics can be created and listed');
api('POST','groups/' . $groupId . '/members',['user_id'=>$friendId],$access);
$members = api('GET','groups/' . $groupId . '/members',null,$access);
check(count($members['body']['members'] ?? []) === 2, 'group member list includes invited member');

$channel = api('POST','channels',['title'=>'CI channel','description'=>'Smoke test channel','visibility'=>'public'],$access);
$channelId = (string)($channel['body']['id'] ?? '');
check($channelId !== '', 'channel creation returns ID');
api('GET','channels',null,$access);
api('POST','channels/' . $channelId . '/posts',['body'=>'Gateway channel post','description'=>'Post test'],$access);
$posts = api('GET','channels/' . $channelId . '/posts',null,$access);
check(count($posts['body']['posts'] ?? []) === 1, 'channel posts can be created and listed');
$subscribers = api('GET','channels/' . $channelId . '/subscribers',null,$access);
check(count($subscribers['body']['subscribers'] ?? []) === 1, 'channel subscribers endpoint returns owner');

api('POST','auth/logout',[],$access);
api('GET','me',null,$access,[401]);
echo "Gateway smoke tests passed: health, registration verification/completion, login, token rotation, profile, search, contacts, notifications, direct chats/messages, groups/topics/members, channels/posts/subscribers, logout.\n";
