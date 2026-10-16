<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/AttachmentService.php';

function pc_files_dispatch(string $path, string $method): bool
{
    if (preg_match('#^/api/v1/messages/([0-9]+)/attachments$#', $path, $matches) && $method === 'POST') {
        pc_rate_limit('attachment-upload', 20, 3600);
        $user = pc_require_verified_user();
        $result = PCAttachmentService::upload((int)$user['id'], (int)$matches[1], $_POST, $_FILES);
        pc_json($result['status'], $result['body']);
    }

    if (preg_match('#^/api/v1/attachments/([0-9]+)$#', $path, $matches) && $method === 'GET') {
        $user = pc_require_verified_user();
        $result = PCAttachmentService::download((int)$user['id'], (int)$matches[1]);
        if (isset($result['status'])) {
            pc_json($result['status'], $result['body']);
        }
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . (string)$result['size']);
        header('Content-Disposition: attachment; filename="encrypted.bin"');
        header('X-Content-Type-Options: nosniff');
        readfile($result['path']);
        exit;
    }
    return false;
}
