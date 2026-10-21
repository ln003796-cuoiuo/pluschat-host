-- Pluschat encrypted media, group key envelopes and backup audit records
CREATE TABLE IF NOT EXISTS user_one_time_prekeys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    device_key_hash CHAR(64) NOT NULL,
    prekey_id VARCHAR(80) NOT NULL,
    public_key VARCHAR(128) NOT NULL,
    signature VARCHAR(128) NOT NULL,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_one_time_prekey (user_id, device_key_hash, prekey_id),
    INDEX idx_one_time_prekey_available (user_id, consumed_at, id),
    CONSTRAINT fk_one_time_prekey_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS encrypted_key_envelopes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope_type ENUM('conversation','group','channel') NOT NULL,
    scope_id BIGINT UNSIGNED NOT NULL,
    recipient_user_id BIGINT UNSIGNED NOT NULL,
    recipient_device_hash CHAR(64) NOT NULL,
    sender_user_id BIGINT UNSIGNED NOT NULL,
    key_id VARCHAR(128) NOT NULL,
    algorithm VARCHAR(40) NOT NULL DEFAULT 'X25519-HKDF-SHA256-AES256GCM',
    envelope_ciphertext MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_scope_recipient_device_key (scope_type, scope_id, recipient_user_id, recipient_device_hash, key_id),
    INDEX idx_key_envelope_recipient (recipient_user_id, recipient_device_hash, created_at),
    CONSTRAINT fk_key_envelope_recipient FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_key_envelope_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backup_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    backup_name VARCHAR(255) NOT NULL,
    sha256 CHAR(64) NOT NULL,
    byte_size BIGINT UNSIGNED NOT NULL,
    status ENUM('created','verified','failed','restored') NOT NULL,
    details VARCHAR(1000) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_backup_runs_created (created_at, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @pc_has_media_kind = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'message_attachments' AND column_name = 'media_kind');
SET @pc_sql = IF(@pc_has_media_kind = 0, 'ALTER TABLE message_attachments ADD COLUMN media_kind ENUM(''file'',''voice'',''sticker'',''gif'') NOT NULL DEFAULT ''file'' AFTER encryption_version', 'SELECT 1');
PREPARE pc_stmt FROM @pc_sql;
EXECUTE pc_stmt;
DEALLOCATE PREPARE pc_stmt;

SET @pc_has_duration = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'message_attachments' AND column_name = 'duration_ms');
SET @pc_sql = IF(@pc_has_duration = 0, 'ALTER TABLE message_attachments ADD COLUMN duration_ms INT UNSIGNED NULL AFTER media_kind', 'SELECT 1');
PREPARE pc_stmt FROM @pc_sql;
EXECUTE pc_stmt;
DEALLOCATE PREPARE pc_stmt;
