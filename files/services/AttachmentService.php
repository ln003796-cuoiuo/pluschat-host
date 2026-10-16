<?php
declare(strict_types=1);

require_once __DIR__ . '/../validators/AttachmentValidator.php';
require_once __DIR__ . '/../repositories/AttachmentRepository.php';

final class PCAttachmentService
{
    private static function storageDirectory(): string
    {
        return dirname(__DIR__, 2) . '/storage/uploads';
    }

    public static function upload(int $userId, int $messageId, array $post, array $files): array
    {
        if ($messageId < 1) return ['status' => 422, 'body' => ['ok' => false, 'error' => 'invalid_message_id']];
        $pdo = pc_db();
        if (!PCAttachmentRepository::canAttach($pdo, $userId, $messageId)) {
            return ['status' => 404, 'body' => ['ok' => false, 'error' => 'message_not_found']];
        }
        $meta = PCAttachmentValidator::validateUpload($post, $files);
        if (isset($meta['error'])) {
            $status = (int)$meta['status'];
            unset($meta['status']);
            return ['status' => $status, 'body' => ['ok' => false] + $meta];
        }
        $directory = self::storageDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            return ['status' => 500, 'body' => ['ok' => false, 'error' => 'storage_unavailable']];
        }
        $storageKey = bin2hex(random_bytes(32)) . '.bin';
        $target = $directory . '/' . $storageKey;
        if (!move_uploaded_file($meta['tmp_name'], $target)) {
            return ['status' => 500, 'body' => ['ok' => false, 'error' => 'storage_write_failed']];
        }
        @chmod($target, 0600);
        $sha = hash_file('sha256', $target);
        try {
            $id = PCAttachmentRepository::create($pdo, $messageId, $storageKey, $meta['original_name'], $meta['mime_type'], $meta['size'], $sha);
        } catch (Throwable $e) {
            @unlink($target);
            throw $e;
        }
        return [
            'status' => 201,
            'body' => [
                'ok' => true,
                'attachment' => [
                    'id' => $id, 'message_id' => (string)$messageId,
                    'original_name' => $meta['original_name'], 'mime_type' => $meta['mime_type'],
                    'byte_size' => $meta['size'], 'sha256' => $sha, 'encrypted' => true,
                    'download_url' => '/api/v1/attachments/' . $id,
                ],
            ],
        ];
    }

    public static function delete(int $userId, int $attachmentId): array
    {
        if ($attachmentId < 1) return ['status' => 422, 'body' => ['ok' => false, 'error' => 'invalid_attachment_id']];
        $pdo = pc_db();
        $pdo->beginTransaction();
        try {
            $row = PCAttachmentRepository::deleteForSender($pdo, $userId, $attachmentId);
            if (!$row) {
                $pdo->rollBack();
                return ['status' => 404, 'body' => ['ok' => false, 'error' => 'attachment_not_found']];
            }
            $pdo->commit();
            $key = basename((string)$row['storage_key']);
            $path = self::storageDirectory() . '/' . $key;
            if (is_file($path)) @unlink($path);
            return ['status' => 200, 'body' => ['ok' => true, 'deleted' => true, 'attachment_id' => (string)$attachmentId]];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function download(int $userId, int $attachmentId): array
    {
        if ($attachmentId < 1) return ['status' => 422, 'body' => ['ok' => false, 'error' => 'invalid_attachment_id']];
        $attachment = PCAttachmentRepository::findForUser(pc_db(), $userId, $attachmentId);
        if (!$attachment) return ['status' => 404, 'body' => ['ok' => false, 'error' => 'attachment_not_found']];
        $key = basename((string)$attachment['storage_key']);
        $path = self::storageDirectory() . '/' . $key;
        if (!is_file($path)) return ['status' => 404, 'body' => ['ok' => false, 'error' => 'attachment_file_missing']];
        return ['path' => $path, 'size' => (int)filesize($path)];
    }
}
