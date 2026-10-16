<?php
declare(strict_types=1);

final class PCRegistrationRepository
{
    public static function isTaken(PDO $pdo, string $email, string $username): bool
    {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1');
        $stmt->execute([$email, $username]);
        return (bool)$stmt->fetch();
    }

    public static function create(PDO $pdo, array $user): string
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO users (email, username, password_hash, first_name, last_name, birth_date) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $user['email'],
                $user['username'],
                password_hash($user['password'], PASSWORD_DEFAULT),
                $user['first_name'],
                $user['last_name'],
                $user['birth_date'],
            ]);
            $userId = (int)$pdo->lastInsertId();
            $token = bin2hex(random_bytes(32));
            $verify = $pdo->prepare('INSERT INTO email_verification_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR))');
            $verify->execute([$userId, hash('sha256', $token)]);
            $pdo->commit();
            return $token;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function confirm(PDO $pdo, string $token): bool
    {
        $stmt = $pdo->prepare('SELECT id, user_id FROM email_verification_tokens WHERE token_hash = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1');
        $stmt->execute([hash('sha256', $token)]);
        $record = $stmt->fetch();
        if (!$record) {
            return false;
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET email_verified_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$record['user_id']]);
            $pdo->prepare('DELETE FROM email_verification_tokens WHERE user_id = ?')->execute([$record['user_id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return true;
    }

    public static function unverifiedByEmail(PDO $pdo, string $email): ?array
    {
        $stmt = $pdo->prepare('SELECT id, email, username, email_verified_at FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function replaceVerificationToken(PDO $pdo, int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $pdo->prepare('DELETE FROM email_verification_tokens WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('INSERT INTO email_verification_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR))')
            ->execute([$userId, hash('sha256', $token)]);
        return $token;
    }
}
