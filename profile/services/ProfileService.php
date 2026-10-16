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
        $user = PCProfileRepository::update(pc_db(), $userId, $validated['updates']);
        return ['status' => 200, 'body' => ['ok' => true, 'user' => pc_public_user($user)]];
    }
}
