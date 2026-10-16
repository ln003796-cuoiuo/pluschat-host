<?php
declare(strict_types=1);

/*
 * Pluschat files API routes.
 * Business logic remains in this feature module; shared HTTP/database helpers
 * are loaded by public/index.php through bootstrap.php.
 */
function pc_files_dispatch(string $path, string $method): bool
{
    if (preg_match('#^/api/v1/messages/([0-9]+)/attachments$#', $path, $matches) && $method === 'POST') {
            $user = pc_require_verified_user();
            $messageId = (int)$matches[1];
            $stmt = pc_db()->prepare('SELECT m.id, m.sender_id, m.conversation_id FROM messages m JOIN conversation_members cm ON cm.conversation_id = m.conversation_id WHERE m.id = ? AND cm.user_id = ? AND m.sender_id = ? LIMIT 1');
            $stmt->execute([$messageId, $user['id'], $user['id']]);
            if (!$stmt->fetch()) pc_json(404, ['ok' => false, 'error' => 'message_not_found']);
            if (($_POST['encrypted'] ?? '') !== '1' || !isset($_FILES['file']) || !is_array($_FILES['file'])) {
                pc_json(422, ['ok' => false, 'error' => 'encrypted_file_required', 'message' => 'Upload client-encrypted file bytes with encrypted=1.']);
            }
            $file = $_FILES['file'];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
                pc_json(422, ['ok' => false, 'error' => 'upload_failed']);
            }
            $size = (int)($file['size'] ?? 0);
            if ($size < 1 || $size > 25 * 1024 * 1024) pc_json(413, ['ok' => false, 'error' => 'attachment_size_limit_25mb']);
            $originalName = basename(str_replace("\\", '/', (string)($_POST['original_name'] ?? 'encrypted.bin')));
            $originalName = preg_replace('/[\\x00-\\x1F\\x7F]/u', '', $originalName) ?: 'encrypted.bin';
            if (pc_len($originalName) > 255) $originalName = substr($originalName, 0, 255);
            $mime = pc_string($_POST, 'mime_type') ?: 'application/octet-stream';
            if (pc_len($mime) > 127 || !preg_match('#^[a-zA-Z0-9.+-]+/[a-zA-Z0-9.+-]+$#', $mime)) $mime = 'application/octet-stream';
            $storageDir = dirname(__DIR__) . '/storage/uploads';
            if (!is_dir($storageDir) && !mkdir($storageDir, 0700, true) && !is_dir($storageDir)) pc_json(500, ['ok' => false, 'error' => 'storage_unavailable']);
            $storageKey = bin2hex(random_bytes(32)) . '.bin';
            $target = $storageDir . '/' . $storageKey;
            if (!move_uploaded_file((string)$file['tmp_name'], $target)) pc_json(500, ['ok' => false, 'error' => 'storage_write_failed']);
            @chmod($target, 0600);
            $sha = hash_file('sha256', $target);
            try {
                $pdo = pc_db();
                $pdo->prepare("INSERT INTO message_attachments (message_id, storage_key, original_name, mime_type, byte_size, sha256, encryption_version) VALUES (?, ?, ?, ?, ?, ?, 'client-v1')")
                    ->execute([$messageId, $storageKey, $originalName, $mime, $size, $sha]);
                pc_json(201, ['ok' => true, 'attachment' => ['id' => (string)$pdo->lastInsertId(), 'message_id' => (string)$messageId, 'original_name' => $originalName, 'mime_type' => $mime, 'byte_size' => $size, 'sha256' => $sha, 'encrypted' => true, 'download_url' => '/api/v1/attachments/' . (string)$pdo->lastInsertId()]]);
            } catch (Throwable $e) {
                @unlink($target);
                throw $e;
            }
        }
    return false;
}
