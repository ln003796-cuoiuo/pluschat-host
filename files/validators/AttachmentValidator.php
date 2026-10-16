<?php
declare(strict_types=1);

final class PCAttachmentValidator
{
    public static function validateUpload(array $post, array $files): array
    {
        if (($post['encrypted'] ?? '') !== '1' || ($post['encryption_version'] ?? '') !== 'pc-e2ee-v1' || !isset($files['file']) || !is_array($files['file'])) {
            return ['status' => 422, 'error' => 'encrypted_file_required', 'message' => 'Upload client-encrypted file bytes with encrypted=1 and encryption_version=pc-e2ee-v1.'];
        }
        $file = $files['file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
            return ['status' => 422, 'error' => 'upload_failed'];
        }
        $size = (int)($file['size'] ?? 0);
        if ($size < 28) return ['status' => 422, 'error' => 'encrypted_attachment_too_small'];
        if ($size > 25 * 1024 * 1024) return ['status' => 413, 'error' => 'attachment_size_limit_25mb'];

        // Keep filename and MIME metadata private; the encrypted client manifest carries the real values.
        $name = 'encrypted.bin';
        $mime = 'application/octet-stream';
        return ['size' => $size, 'original_name' => $name, 'mime_type' => $mime, 'tmp_name' => (string)$file['tmp_name']];
    }
}
