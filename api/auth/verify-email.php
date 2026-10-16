<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/Http.php';

Http::method('GET');
$token = trim((string)($_GET['token'] ?? ''));
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    Http::respond(400, ['error' => 'invalid_token']);
}

$db = Database::connection();
$hash = hash('sha256', $token);
$stmt = $db->prepare(
    'SELECT id, user_id FROM email_verification_tokens
     WHERE token_hash = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1'
);
$stmt->execute([$hash]);
$row = $stmt->fetch();
if (!$row) {
    Http::respond(400, ['error' => 'invalid_or_expired_token']);
}

$db->beginTransaction();
try {
    $update = $db->prepare('UPDATE users SET email_verified_at = UTC_TIMESTAMP() WHERE id = ?');
    $update->execute([(int)$row['user_id']]);
    $delete = $db->prepare('DELETE FROM email_verification_tokens WHERE user_id = ?');
    $delete->execute([(int)$row['user_id']]);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $e;
}

Http::respond(200, ['status' => 'email_verified', 'message' => 'Email confirmed. You can now sign in.']);
