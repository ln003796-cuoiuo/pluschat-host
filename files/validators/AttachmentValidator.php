<?php
declare(strict_types=1);

final class PCAttachmentValidator
{
    public static function validateUpload(array $post, array $files): array
    {
        if (($post['encrypted'] ?? '') !== '1' || !isset($files['file']) || !is_array($files['file'])) {
            return ['status' => 422, 'error' => 'encrypted_file_required', 'message' => 'Upload client-encrypted file bytes with encrypted=1.'];
        }
        $file = $files['file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
            return ['status' => 422, 'error' => 'upload_failed'];
        }
        $size = (int)($file['size'] ?? 0);
        if ($size < 1) return ['status' => 422, 'error' => 'empty_attachment'];
        if ($size > 25 * 1024 * 1024) return ['status' => 413, 'error' => 'attachment_size_limit_25mb'];

        $name = basename(str_replace("\\", '/', (string)($post['original_name'] ?? 'encrypted.bin')));
        $name = preg_replace('/[\\x00-\\x1F\\x7F]/u', '', $name) ?: 'encrypted.bin';
        if (pc_len($name) > 255) $name = substr($name, 0, 255);
        $mime = pc_string($post, 'mime_type') ?: 'application/octet-stream';
        if (pc_len($mime) > 127 || !preg_match('#^[a-zA-Z0-9.+-]+/[a-zA-Z0-9.+-]+$#', $mime)) {
            $mime = 'application/octet-stream';
        }
        return ['size' => $size, 'original_name' => $name, 'mime_type' => $mime, 'tmp_name' => (string)$file['tmp_name']];
    }
}
