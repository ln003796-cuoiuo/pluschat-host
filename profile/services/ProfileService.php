<?php
declare(strict_types=1);

require_once __DIR__ . '/../validators/ProfileValidator.php';
require_once __DIR__ . '/../repositories/ProfileRepository.php';

final class PCProfileService
{
    public static function update(int $userId, array $data): array
    {
        $validated = PCProfileValidator::validate($data);
        if (isset($validated['error'])) {
            return ['status' => 422, 'body' => ['ok' => false, 'error' => $validated['error']]];
        }
        $pdo = pc_db();
        $current = $pdo->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
        $current->execute([$userId]);
        $currentUsername = (string)$current->fetchColumn();
        if (str_starts_with($currentUsername, 'pc_pending_') && !isset($validated['updates']['username'])) {
            return ['status' => 422, 'body' => ['ok' => false, 'error' => 'username_required', 'message' => 'Выбери ник для завершения профиля.']];
        }
        if (isset($validated['updates']['username'])) {
            $taken = $pdo->prepare('SELECT 1 FROM users WHERE username = ? AND id <> ? LIMIT 1');
            $taken->execute([$validated['updates']['username'], $userId]);
            if ($taken->fetchColumn()) {
                return ['status' => 409, 'body' => ['ok' => false, 'error' => 'username_taken', 'message' => 'Этот ник уже занят.']];
            }
        }
        try {
            $user = PCProfileRepository::update($pdo, $userId, $validated['updates']);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000' && isset($validated['updates']['username'])) {
                return ['status' => 409, 'body' => ['ok' => false, 'error' => 'username_taken', 'message' => 'Этот ник уже занят.']];
            }
            throw $e;
        }
        return ['status' => 200, 'body' => ['ok' => true, 'user' => pc_public_user($user)]];
    }
}
