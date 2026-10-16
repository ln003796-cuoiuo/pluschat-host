<?php
declare(strict_types=1);

require_once __DIR__ . '/../validators/ChannelValidator.php';
require_once __DIR__ . '/../repositories/ChannelRepository.php';

final class PCChannelService
{
    public static function create(int $ownerId, array $input): array
    {
        $data = PCChannelValidator::validate($input);
        if (isset($data['error'])) return ['status' => 422, 'body' => ['ok' => false, 'error' => $data['error']]];
        return ['status' => 201, 'body' => ['ok' => true, 'channel' => PCChannelRepository::create(pc_db(), $ownerId, $data)]];
    }
}
