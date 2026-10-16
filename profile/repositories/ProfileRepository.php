<?php
declare(strict_types=1);

final class PCProfileRepository
{
    public static function update(PDO $pdo, int $userId, array $updates): array
    {
        $sets = [];
        $values = [];
        foreach ($updates as $field => $value) {
            if (!in_array($field, ['first_name', 'last_name', 'birth_date'], true)) {
                throw new InvalidArgumentException('Unsupported profile field.');
            }
            if ($field === 'birth_date' && $value === null) {
                $sets[] = 'birth_date = NULL';
                continue;
            }
            $sets[] = $field . ' = ?';
            $values[] = $value;
        }
        $values[] = $userId;
        $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($values);
        $stmt = $pdo->prepare('SELECT id, email, username, first_name, last_name, birth_date, email_verified_at, created_at FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) throw new RuntimeException('User disappeared during profile update.');
        return $user;
    }
}
