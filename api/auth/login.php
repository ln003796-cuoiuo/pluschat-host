<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/Http.php';

Http::method('POST');
$data = Http::json();
$login = strtolower(trim((string)($data['login'] ?? $data['email'] ?? $data['username'] ?? '')));
$password = (string)($data['password'] ?? '');
if ($login === '' || $password === '' || strlen($login) > 254 || strlen($password) > 200) {
    Http::respond(422, ['error' => 'invalid_credentials']);
}

$db = Database::connection();
$stmt = $db->prepare(
    'SELECT id, email, username, password_hash, first_name, last_name, email_verified_at
     FROM users WHERE email = ? OR username = ? LIMIT 1'
);
$stmt->execute([$login, $login]);
$user = $stmt->fetch();
if (!$user || !password_verify($password, $user['password_hash'])) {
    Http::respond(401, ['error' => 'invalid_credentials']);
}
if ($user['email_verified_at'] === null) {
    Http::respond(403, ['error' => 'email_not_verified']);
}

$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);
$insert = $db->prepare(
    'INSERT INTO auth_tokens (user_id, token_hash, expires_at)
     VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY))'
);
$insert->execute([(int)$user['id'], $tokenHash]);

unset($user['password_hash'], $user['email_verified_at']);
Http::respond(200, [
    'token_type' => 'Bearer',
    'access_token' => $rawToken,
    'expires_in' => 2592000,
    'user' => $user
]);
