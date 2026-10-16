<?php
declare(strict_types=1);

require_once __DIR__ . '/../validators/GroupValidator.php';
require_once __DIR__ . '/../repositories/GroupRepository.php';

final class PCGroupService
{
    public static function create(int $ownerId, array $input): array
    {
        $data = PCGroupValidator::validate($input);
        if (isset($data['error'])) return ['status' => 422, 'body' => ['ok' => false, 'error' => $data['error']]];
        return ['status' => 201, 'body' => ['ok' => true, 'group' => PCGroupRepository::create(pc_db(), $ownerId, $data)]];
    }
}
