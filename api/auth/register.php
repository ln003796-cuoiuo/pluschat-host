<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/Http.php';

Http::method('POST');
$data = Http::json();

$email = strtolower(trim((string)($data['email'] ?? '')));
$username = trim((string)($data['username'] ?? ''));
$password = (string)($data['password'] ?? '');
$firstName = trim((string)($data['first_name'] ?? ''));
$lastName = trim((string)($data['last_name'] ?? ''));
$birthDate = trim((string)($data['birth_date'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    Http::respond(422, ['error' => 'invalid_email']);
}
if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) {
    Http::respond(422, ['error' => 'invalid_username', 'message' => 'Username must be 3–32 letters, digits or underscores.']);
}
if (strlen($password) < 10 || strlen($password) > 200) {
    Http::respond(422, ['error' => 'weak_password', 'message' => 'Password must contain at least 10 characters.']);
}
if (mb_strlen($firstName) > 80 || mb_strlen($lastName) > 80) {
    Http::respond(422, ['error' => 'name_too_long']);
}
if ($birthDate !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate) || !checkdate((int)substr($birthDate, 5, 2), (int)substr($birthDate, 8, 2), (int)substr($birthDate, 0, 4)))) {
    Http::respond(422, ['error' => 'invalid_birth_date']);
}

$db = Database::connection();
$check = $db->prepare('SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1');
$check->execute([$email, $username]);
if ($check->fetch()) {
    Http::respond(409, ['error' => 'email_or_username_taken']);
}

$token = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);
try {
    $db->beginTransaction();
    $insert = $db->prepare(
        'INSERT INTO users (email, username, password_hash, first_name, last_name, birth_date)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([
        $email, $username, password_hash($password, PASSWORD_DEFAULT),
        $firstName, $lastName, $birthDate === '' ? null : $birthDate
    ]);
    $userId = (int)$db->lastInsertId();
    $verification = $db->prepare(
        'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at)
         VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR))'
    );
    $verification->execute([$userId, $tokenHash]);
    $db->commit();
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if ($e->getCode() === '23000') {
        Http::respond(409, ['error' => 'email_or_username_taken']);
    }
    throw $e;
}

$verifyUrl = Http::originUrl() . '/api/auth/verify-email.php?token=' . urlencode($token);
$from = Config::get('MAIL_FROM', 'register@плюсчат.рф');
$host = parse_url(Http::originUrl(), PHP_URL_HOST) ?: 'Pluschat';
$subject = 'Подтверждение регистрации Pluschat';
$message = "Здравствуйте!\n\nПодтвердите адрес электронной почты, открыв ссылку:\n{$verifyUrl}\n\nСсылка действует 24 часа. Если вы не регистрировались, проигнорируйте письмо.";
$headers = "From: {$from}\r\nContent-Type: text/plain; charset=UTF-8";
if (!mail($email, '=?UTF-8?B?' . base64_encode($subject) . '?=', $message, $headers)) {
    // Do not expose the confirmation token in the API response.
    Http::respond(503, ['error' => 'email_delivery_failed', 'message' => 'Account was created but confirmation email could not be sent. Contact the site administrator.']);
}

Http::respond(201, [
    'status' => 'confirmation_sent',
    'message' => 'Check your email to confirm your account.'
]);
