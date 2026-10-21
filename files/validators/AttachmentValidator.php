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
        if ($size < 32) return ['status' => 422, 'error' => 'encrypted_attachment_too_small'];
        if ($size > 25 * 1024 * 1024 + 32) return ['status' => 413, 'error' => 'attachment_size_limit_25mb'];
        $handle = @fopen((string)($file['tmp_name'] ?? ''), 'rb');
        $magic = $handle === false ? false : fread($handle, 4);
        if (is_resource($handle)) fclose($handle);
        if ($magic !== "PCF1") return ['status' => 422, 'error' => 'invalid_encrypted_attachment_envelope'];

        $kind = is_string($post['media_kind'] ?? null) ? $post['media_kind'] : 'file';
        if (!in_array($kind, ['file', 'voice', 'sticker', 'gif'], true)) {
            return ['status' => 422, 'error' => 'invalid_media_kind'];
        }
        $duration = null;
        if ($kind === 'voice') {
            $duration = filter_var($post['duration_ms'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3600000]]);
            if ($duration === false) return ['status' => 422, 'error' => 'invalid_voice_duration'];
        }
        // Keep filename and MIME metadata private; encrypted client manifest carries real values.
        return ['size' => $size, 'original_name' => 'encrypted.bin', 'mime_type' => 'application/octet-stream',
            'media_kind' => $kind, 'duration_ms' => $duration, 'tmp_name' => (string)$file['tmp_name']];
    }
}
