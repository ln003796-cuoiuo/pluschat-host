<?php
declare(strict_types=1);

final class PCProfileAvatarService
{
    private static function storageDirectory(): string
    {
        return dirname(__DIR__, 2) . '/storage/avatars';
    }

    public static function upload(int $userId, array $files): array
    {
        $file = $files['avatar'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
            return ['status' => 422, 'body' => ['ok' => false, 'error' => 'avatar_upload_failed']];
        }
        $size = (int)($file['size'] ?? 0);
        if ($size < 1 || $size > 5 * 1024 * 1024) {
            return ['status' => 413, 'body' => ['ok' => false, 'error' => 'avatar_size_limit_5mb']];
        }
        $image = @getimagesize((string)$file['tmp_name']);
        $mime = is_array($image) ? (string)($image['mime'] ?? '') : '';
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (!is_array($image) || !in_array($mime, $allowed, true)) {
            return ['status' => 422, 'body' => ['ok' => false, 'error' => 'avatar_image_type_not_allowed']];
        }
        $directory = self::storageDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            return ['status' => 500, 'body' => ['ok' => false, 'error' => 'avatar_storage_unavailable']];
        }
        $storageKey = bin2hex(random_bytes(32));
        $target = $directory . '/' . $storageKey;
        if (!move_uploaded_file((string)$file['tmp_name'], $target)) {
            return ['status' => 500, 'body' => ['ok' => false, 'error' => 'avatar_storage_write_failed']];
        }
        @chmod($target, 0600);
        $sha = hash_file('sha256', $target);
        $pdo = pc_db();
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT storage_key FROM user_avatars WHERE user_id = ? FOR UPDATE');
            $stmt->execute([$userId]);
            $oldKey = $stmt->fetchColumn();
            $pdo->prepare('INSERT INTO user_avatars (user_id, storage_key, mime_type, byte_size, sha256) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE storage_key = VALUES(storage_key), mime_type = VALUES(mime_type), byte_size = VALUES(byte_size), sha256 = VALUES(sha256), updated_at = UTC_TIMESTAMP()')
                ->execute([$userId, $storageKey, $mime, $size, $sha]);
            $pdo->commit();
            if (is_string($oldKey) && preg_match('/^[a-f0-9]{64}$/', $oldKey) && $oldKey !== $storageKey) {
                @unlink($directory . '/' . $oldKey);
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            @unlink($target);
            throw $e;
        }
        return ['status' => 200, 'body' => ['ok' => true, 'avatar_uploaded' => true, 'mime_type' => $mime, 'byte_size' => $size, 'sha256' => $sha, 'download_url' => '/api/v1/me/avatar']];
    }

    public static function findForUser(int $userId): array
    {
        $stmt = pc_db()->prepare('SELECT storage_key, mime_type, byte_size FROM user_avatars WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) return ['status' => 404, 'body' => ['ok' => false, 'error' => 'avatar_not_found']];
        $key = (string)$row['storage_key'];
        if (!preg_match('/^[a-f0-9]{64}$/', $key)) return ['status' => 404, 'body' => ['ok' => false, 'error' => 'avatar_not_found']];
        $path = self::storageDirectory() . '/' . $key;
        if (!is_file($path)) return ['status' => 404, 'body' => ['ok' => false, 'error' => 'avatar_file_missing']];
        return ['path' => $path, 'mime_type' => (string)$row['mime_type'], 'size' => (int)$row['byte_size']];
    }
}
