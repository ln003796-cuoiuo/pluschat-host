<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = pc_config('allowed_origins', ['https://xn--80avljg2a1c.xn--p1ai']);
if (is_array($allowedOrigins) && $origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function pc_string(array $data, string $key): string {
    $value = $data[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function pc_public_user(array $user): array {
    return [
        'id' => (string)$user['id'],
        'email' => (string)$user['email'],
        'username' => (string)$user['username'],
        'first_name' => (string)($user['first_name'] ?? ''),
        'last_name' => (string)($user['last_name'] ?? ''),
        'birth_date' => $user['birth_date'] ?? null,
        'email_verified' => !empty($user['email_verified_at']),
        'created_at' => $user['created_at'] ?? null,
    ];
}

function pc_send_verification(string $email, string $username, string $token): bool {
    $baseUrl = rtrim((string)pc_config('app_base_url', 'https://xn--80avljg2a1c.xn--p1ai'), '/');
    $from = (string)pc_config('mail_from', 'register@плюсчат.рф');
    $link = $baseUrl . '/api/v1/auth/verify?token=' . rawurlencode($token);
    $subject = 'Подтверждение почты Pluschat';
    $body = "Здравствуйте, {$username}!\n\nПодтвердите адрес электронной почты для Pluschat по ссылке:\n{$link}\n\nСсылка действует 24 часа. Если вы не регистрировались, просто проигнорируйте это письмо.";
    $headers = [
        'From: Pluschat <' . $from . '>',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: Pluschat PHP',
    ];
    return @mail($email, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
}

function pc_require_verified_user(): array {
    return pc_token_user();
}

try {
    if ($path === '/' || $path === '/index.php') {
        pc_json(200, [
            'name' => 'Pluschat API',
            'status' => 'online',
            'api_version' => 1,
            'entrypoint_available' => true,
            'health' => '/api/v1/health',
        ]);
    }

    if ($path === '/api/v1/health' && $method === 'GET') {
        pc_db()->query('SELECT 1')->fetchColumn();
        pc_json(200, [
            'ok' => true,
            'service' => 'pluschat-api',
            'api_version' => 1,
            'database' => 'connected',
        ]);
    }

    if ($path === '/api/v1/auth/register' && $method === 'POST') {
        $data = pc_input();
        $email = mb_strtolower(pc_string($data, 'email'));
        $username = pc_string($data, 'username');
        $password = is_string($data['password'] ?? null) ? $data['password'] : '';
        $firstName = pc_string($data, 'first_name');
        $lastName = pc_string($data, 'last_name');
        $birthDate = pc_string($data, 'birth_date');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
            pc_json(422, ['ok' => false, 'error' => 'invalid_email']);
        }
        if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) {
            pc_json(422, ['ok' => false, 'error' => 'invalid_username', 'message' => 'Имя пользователя: 3–32 символа, латинские буквы, цифры и _.']);
        }
        if (strlen($password) < 10 || strlen($password) > 200) {
            pc_json(422, ['ok' => false, 'error' => 'invalid_password', 'message' => 'Пароль должен содержать от 10 до 200 символов.']);
        }
        if (mb_strlen($firstName) > 80 || mb_strlen($lastName) > 80) {
            pc_json(422, ['ok' => false, 'error' => 'profile_field_too_long']);
        }
        if ($birthDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
            pc_json(422, ['ok' => false, 'error' => 'invalid_birth_date']);
        }
        if ($birthDate !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
            if (!$date || $date->format('Y-m-d') !== $birthDate || $date > new DateTimeImmutable('today')) {
                pc_json(422, ['ok' => false, 'error' => 'invalid_birth_date']);
            }
        }

        $pdo = pc_db();
        $check = $pdo->prepare('SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1');
        $check->execute([$email, $username]);
        if ($check->fetch()) {
            pc_json(409, ['ok' => false, 'error' => 'email_or_username_taken']);
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO users (email, username, password_hash, first_name, last_name, birth_date) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$email, $username, password_hash($password, PASSWORD_DEFAULT), $firstName, $lastName, $birthDate !== '' ? $birthDate : null]);
            $userId = (int)$pdo->lastInsertId();
            $token = bin2hex(random_bytes(32));
            $verify = $pdo->prepare('INSERT INTO email_verification_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR))');
            $verify->execute([$userId, hash('sha256', $token)]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $sent = pc_send_verification($email, $username, $token);
        pc_json(201, [
            'ok' => true,
            'registered' => true,
            'email_verification_sent' => $sent,
            'message' => $sent
                ? 'Регистрация создана. Проверьте почту и подтвердите адрес.'
                : 'Аккаунт создан, но письмо не отправлено. Проверьте настройки почты на хостинге и запросите письмо повторно.',
        ]);
    }

    if ($path === '/api/v1/auth/verify' && ($method === 'GET' || $method === 'POST')) {
        $data = $method === 'POST' ? pc_input() : [];
        $token = $method === 'POST' ? pc_string($data, 'token') : (string)($_GET['token'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
            pc_json(400, ['ok' => false, 'error' => 'invalid_verification_token']);
        }
        $pdo = pc_db();
        $stmt = $pdo->prepare('SELECT id, user_id FROM email_verification_tokens WHERE token_hash = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1');
        $stmt->execute([hash('sha256', $token)]);
        $record = $stmt->fetch();
        if (!$record) pc_json(400, ['ok' => false, 'error' => 'invalid_or_expired_verification_token']);
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET email_verified_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$record['user_id']]);
        $pdo->prepare('DELETE FROM email_verification_tokens WHERE user_id = ?')->execute([$record['user_id']]);
        $pdo->commit();
        pc_json(200, ['ok' => true, 'verified' => true, 'message' => 'Почта подтверждена. Теперь можно войти в Pluschat.']);
    }

    if ($path === '/api/v1/auth/resend-verification' && $method === 'POST') {
        $email = mb_strtolower(pc_string(pc_input(), 'email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) pc_json(422, ['ok' => false, 'error' => 'invalid_email']);
        $pdo = pc_db();
        $stmt = $pdo->prepare('SELECT id, email, username, email_verified_at FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user && empty($user['email_verified_at'])) {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('DELETE FROM email_verification_tokens WHERE user_id = ?')->execute([$user['id']]);
            $pdo->prepare('INSERT INTO email_verification_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR))')
                ->execute([$user['id'], hash('sha256', $token)]);
            pc_send_verification($email, (string)$user['username'], $token);
        }
        pc_json(200, ['ok' => true, 'message' => 'Если аккаунт существует и ещё не подтверждён, письмо будет отправлено.']);
    }

    if ($path === '/api/v1/auth/login' && $method === 'POST') {
        $data = pc_input();
        $login = mb_strtolower(pc_string($data, 'login'));
        if ($login === '') $login = mb_strtolower(pc_string($data, 'email'));
        $password = is_string($data['password'] ?? null) ? $data['password'] : '';
        $stmt = pc_db()->prepare('SELECT id, email, username, password_hash, first_name, last_name, birth_date, email_verified_at, created_at FROM users WHERE email = ? OR username = ? LIMIT 1');
        $stmt->execute([$login, $login]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            pc_json(401, ['ok' => false, 'error' => 'invalid_credentials']);
        }
        if (empty($user['email_verified_at'])) {
            pc_json(403, ['ok' => false, 'error' => 'email_not_verified', 'message' => 'Сначала подтвердите электронную почту.']);
        }
        $token = bin2hex(random_bytes(32));
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+30 days')->format('Y-m-d H:i:s');
        $pdo = pc_db();
        $pdo->prepare('INSERT INTO auth_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)')
            ->execute([$user['id'], hash('sha256', $token), $expiresAt]);
        pc_json(200, ['ok' => true, 'token' => $token, 'token_type' => 'Bearer', 'expires_at' => $expiresAt, 'user' => pc_public_user($user)]);
    }

    if ($path === '/api/v1/auth/me' && $method === 'GET') {
        $user = pc_require_verified_user();
        $stmt = pc_db()->prepare('SELECT id, email, username, first_name, last_name, birth_date, email_verified_at, created_at FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        pc_json(200, ['ok' => true, 'user' => pc_public_user($stmt->fetch() ?: $user)]);
    }

    if ($path === '/api/v1/auth/logout' && $method === 'POST') {
        pc_require_verified_user();
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $matches);
        pc_db()->prepare('DELETE FROM auth_tokens WHERE token_hash = ?')->execute([hash('sha256', $matches[1])]);
        pc_json(200, ['ok' => true, 'logged_out' => true]);
    }

    if ($path === '/api/v1/users/search' && $method === 'GET') {
        $user = pc_require_verified_user();
        $query = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($query) < 2 || mb_strlen($query) > 64) {
            pc_json(422, ['ok' => false, 'error' => 'query_must_be_2_to_64_characters']);
        }
        $like = '%' . addcslashes($query, '%_\\') . '%';
        $stmt = pc_db()->prepare('SELECT id, username, first_name, last_name FROM users WHERE id <> ? AND (username LIKE ? OR first_name LIKE ? OR last_name LIKE ?) ORDER BY username LIMIT 30');
        $stmt->execute([$user['id'], $like, $like, $like]);
        pc_json(200, ['ok' => true, 'users' => array_map(static fn(array $u): array => [
            'id' => (string)$u['id'], 'username' => $u['username'],
            'first_name' => $u['first_name'], 'last_name' => $u['last_name'],
        ], $stmt->fetchAll())]);
    }

    if ($path === '/api/v1/conversations' && $method === 'POST') {
        $user = pc_require_verified_user();
        $data = pc_input();
        $otherId = filter_var($data['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$otherId || (int)$otherId === (int)$user['id']) pc_json(422, ['ok' => false, 'error' => 'invalid_recipient']);
        $pdo = pc_db();
        $exists = $pdo->prepare('SELECT id FROM users WHERE id = ? AND email_verified_at IS NOT NULL');
        $exists->execute([$otherId]);
        if (!$exists->fetch()) pc_json(404, ['ok' => false, 'error' => 'user_not_found']);

        $find = $pdo->prepare(
            'SELECT c.id FROM conversations c
             JOIN conversation_members m1 ON m1.conversation_id = c.id AND m1.user_id = ?
             JOIN conversation_members m2 ON m2.conversation_id = c.id AND m2.user_id = ?
             WHERE (SELECT COUNT(*) FROM conversation_members mc WHERE mc.conversation_id = c.id) = 2
             ORDER BY c.id LIMIT 1'
        );
        $find->execute([$user['id'], $otherId]);
        $conversation = $find->fetch();
        if ($conversation) pc_json(200, ['ok' => true, 'conversation_id' => (string)$conversation['id'], 'created' => false]);

        $pdo->beginTransaction();
        try {
            $pdo->exec('INSERT INTO conversations () VALUES ()');
            $conversationId = (int)$pdo->lastInsertId();
            $member = $pdo->prepare('INSERT INTO conversation_members (conversation_id, user_id) VALUES (?, ?)');
            $member->execute([$conversationId, $user['id']]);
            $member->execute([$conversationId, $otherId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        pc_json(201, ['ok' => true, 'conversation_id' => (string)$conversationId, 'created' => true]);
    }

    if ($path === '/api/v1/conversations' && $method === 'GET') {
        $user = pc_require_verified_user();
        $stmt = pc_db()->prepare(
            'SELECT c.id, c.created_at,
                    other.id AS other_user_id, other.username AS other_username,
                    other.first_name AS other_first_name, other.last_name AS other_last_name,
                    (SELECT m.body FROM messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message,
                    (SELECT m.created_at FROM messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message_at
             FROM conversation_members mine
             JOIN conversations c ON c.id = mine.conversation_id
             JOIN conversation_members theirs ON theirs.conversation_id = c.id AND theirs.user_id <> mine.user_id
             JOIN users other ON other.id = theirs.user_id
             WHERE mine.user_id = ?
             ORDER BY COALESCE(last_message_at, c.created_at) DESC LIMIT 100'
        );
        $stmt->execute([$user['id']]);
        pc_json(200, ['ok' => true, 'conversations' => array_map(static fn(array $c): array => [
            'id' => (string)$c['id'], 'created_at' => $c['created_at'],
            'other_user' => ['id' => (string)$c['other_user_id'], 'username' => $c['other_username'], 'first_name' => $c['other_first_name'], 'last_name' => $c['other_last_name']],
            'last_message' => $c['last_message'], 'last_message_at' => $c['last_message_at'],
        ], $stmt->fetchAll())]);
    }

    if (preg_match('#^/api/v1/conversations/(\d+)/messages$#', $path, $matches)) {
        $user = pc_require_verified_user();
        $conversationId = (int)$matches[1];
        $member = pc_db()->prepare('SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ?');
        $member->execute([$conversationId, $user['id']]);
        if (!$member->fetchColumn()) pc_json(404, ['ok' => false, 'error' => 'conversation_not_found']);

        if ($method === 'GET') {
            $before = filter_var($_GET['before'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $limit = filter_var($_GET['limit'] ?? 50, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]) ?: 50;
            if ($before) {
                $stmt = pc_db()->prepare('SELECT id, sender_id, body, created_at, edited_at FROM messages WHERE conversation_id = ? AND id < ? ORDER BY id DESC LIMIT ' . (int)$limit);
                $stmt->execute([$conversationId, $before]);
            } else {
                $stmt = pc_db()->prepare('SELECT id, sender_id, body, created_at, edited_at FROM messages WHERE conversation_id = ? ORDER BY id DESC LIMIT ' . (int)$limit);
                $stmt->execute([$conversationId]);
            }
            $messages = array_reverse($stmt->fetchAll());
            pc_json(200, ['ok' => true, 'messages' => array_map(static fn(array $m): array => [
                'id' => (string)$m['id'], 'sender_id' => (string)$m['sender_id'], 'body' => $m['body'],
                'created_at' => $m['created_at'], 'edited_at' => $m['edited_at'],
            ], $messages)]);
        }

        if ($method === 'POST') {
            $body = pc_string(pc_input(), 'body');
            if ($body === '' || mb_strlen($body) > 4000) pc_json(422, ['ok' => false, 'error' => 'message_must_be_1_to_4000_characters']);
            $stmt = pc_db()->prepare('INSERT INTO messages (conversation_id, sender_id, body) VALUES (?, ?, ?)');
            $stmt->execute([$conversationId, $user['id'], $body]);
            pc_json(201, ['ok' => true, 'message' => [
                'id' => (string)pc_db()->lastInsertId(), 'conversation_id' => (string)$conversationId,
                'sender_id' => (string)$user['id'], 'body' => $body, 'created_at' => gmdate('Y-m-d H:i:s'),
            ]]);
        }
        header('Allow: GET, POST');
        pc_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
    }

    pc_json(404, ['ok' => false, 'error' => 'endpoint_not_found', 'path' => $path]);
} catch (PDOException $e) {
    error_log('[Pluschat API] Database error: ' . $e->getMessage());
    pc_json(503, ['ok' => false, 'error' => 'database_unavailable']);
} catch (Throwable $e) {
    error_log('[Pluschat API] Server error: ' . $e->getMessage());
    pc_json(500, ['ok' => false, 'error' => 'internal_server_error']);
}
