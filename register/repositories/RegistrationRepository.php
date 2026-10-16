<?php
declare(strict_types=1);

final class PCRegistrationRepository
{
    public static function isTaken(PDO $pdo, string $email, string $username, string $phone = ''): bool
    {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? OR username = ? OR phone = ? LIMIT 1');
        $stmt->execute([$email, $username, $phone]);
        return (bool)$stmt->fetch();
    }

    /** Returns both one-time codes; only their hashes are stored in the database. */
    public static function create(PDO $pdo, array $user): array
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO users (email, phone, username, password_hash, first_name, last_name, birth_date) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $user['email'], $user['phone'], $user['username'],
                password_hash($user['password'], PASSWORD_DEFAULT),
                $user['first_name'], $user['last_name'], $user['birth_date'],
            ]);
            $userId = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO user_settings (user_id) VALUES (?)')->execute([$userId]);

            $emailCode = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $emailStmt = $pdo->prepare('INSERT INTO email_verification_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE))');
            $emailStmt->execute([$userId, hash('sha256', $user['email'] . '|' . $emailCode)]);

            $phoneCode = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $phoneStmt = $pdo->prepare('INSERT INTO phone_verification_codes (user_id, phone, code_hash, expires_at, attempts, last_sent_at) VALUES (?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE), 0, UTC_TIMESTAMP())');
            $phoneStmt->execute([$userId, $user['phone'], hash('sha256', $user['phone'] . '|' . $phoneCode)]);
            $pdo->commit();
            return ['email_code' => $emailCode, 'phone_code' => $phoneCode];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function confirm(PDO $pdo, string $token): bool
    {
        $stmt = $pdo->prepare('SELECT id, user_id FROM email_verification_tokens WHERE token_hash = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1');
        $stmt->execute([hash('sha256', $token)]);
        $record = $stmt->fetch();
        if (!$record) return false;
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET email_verified_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$record['user_id']]);
            $pdo->prepare('DELETE FROM email_verification_tokens WHERE user_id = ?')->execute([$record['user_id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return true;
    }

    public static function confirmCode(PDO $pdo, string $email, string $code): bool
    {
        if (!preg_match('/^[0-9]{6}$/', $code)) return false;
        $stmt = $pdo->prepare('SELECT id, user_id FROM email_verification_tokens WHERE token_hash = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1');
        $stmt->execute([hash('sha256', strtolower($email) . '|' . $code)]);
        $record = $stmt->fetch();
        if (!$record) return false;
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET email_verified_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$record['user_id']]);
            $pdo->prepare('DELETE FROM email_verification_tokens WHERE user_id = ?')->execute([$record['user_id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return true;
    }

    public static function confirmPhoneCode(PDO $pdo, string $phone, string $code): bool
    {
        if (!preg_match('/^[0-9]{6}$/', $code)) return false;
        $stmt = $pdo->prepare('SELECT id, user_id, code_hash, attempts FROM phone_verification_codes WHERE phone = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1');
        $stmt->execute([$phone]);
        $record = $stmt->fetch();
        if (!$record || (int)$record['attempts'] >= 5) return false;
        if (!hash_equals((string)$record['code_hash'], hash('sha256', $phone . '|' . $code))) {
            $pdo->prepare('UPDATE phone_verification_codes SET attempts = attempts + 1 WHERE id = ?')->execute([$record['id']]);
            return false;
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET phone_verified_at = UTC_TIMESTAMP() WHERE id = ? AND phone = ?')->execute([$record['user_id'], $phone]);
            $pdo->prepare('DELETE FROM phone_verification_codes WHERE user_id = ?')->execute([$record['user_id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
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
        $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $email = strtolower((string)$stmt->fetchColumn());
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $pdo->prepare('DELETE FROM email_verification_tokens WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('INSERT INTO email_verification_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE))')
            ->execute([$userId, hash('sha256', $email . '|' . $code)]);
        return $code;
    }

    /** Atomically rotate a phone code only after a 60-second resend cooldown. */
    public static function replacePhoneCode(PDO $pdo, string $phone): ?array
    {
        $stmt = $pdo->prepare('SELECT id, user_id, phone_verified_at FROM users WHERE phone = ? LIMIT 1');
        $stmt->execute([$phone]);
        $user = $stmt->fetch();
        if (!$user || !empty($user['phone_verified_at'])) return null;

        $current = $pdo->prepare('SELECT last_sent_at FROM phone_verification_codes WHERE user_id = ? LIMIT 1');
        $current->execute([$user['id']]);
        $last = $current->fetchColumn();
        if ($last && strtotime((string)$last . ' UTC') > time() - 60) return ['cooldown' => true];

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $pdo->prepare('DELETE FROM phone_verification_codes WHERE user_id = ?')->execute([$user['id']]);
        $pdo->prepare('INSERT INTO phone_verification_codes (user_id, phone, code_hash, expires_at, attempts, last_sent_at) VALUES (?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE), 0, UTC_TIMESTAMP())')
            ->execute([$user['id'], $phone, hash('sha256', $phone . '|' . $code)]);
        return ['code' => $code, 'user_id' => (int)$user['id']];
    }
}
