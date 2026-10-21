-- Pluschat critical features: client key distribution metadata and backup audit.
-- This migration stores only public keys and ciphertext envelopes. Never upload private keys.
CREATE TABLE IF NOT EXISTS user_crypto_prekeys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    device_key_hash CHAR(64) NOT NULL,
    prekey_id VARCHAR(80) NOT NULL,
    public_key TEXT NOT NULL,
    signature TEXT NOT NULL,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_prekey_device_id (user_id, device_key_hash, prekey_id),
    INDEX idx_prekey_available (user_id, consumed_at, id),
    CONSTRAINT fk_prekey_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversation_key_epochs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    epoch BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    algorithm VARCHAR(64) NOT NULL DEFAULT 'pc-e2ee-v1',
    membership_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_conversation_epoch (conversation_id, epoch),
    CONSTRAINT fk_key_epoch_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_key_epoch_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversation_key_envelopes (
    epoch_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    device_key_hash CHAR(64) NOT NULL,
    envelope MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (epoch_id, user_id, device_key_hash),
    CONSTRAINT fk_key_envelope_epoch FOREIGN KEY (epoch_id) REFERENCES conversation_key_epochs(id) ON DELETE CASCADE,
    CONSTRAINT fk_key_envelope_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS channel_key_epochs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    channel_id BIGINT UNSIGNED NOT NULL,
    epoch BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    algorithm VARCHAR(64) NOT NULL DEFAULT 'pc-e2ee-v1',
    membership_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_channel_epoch (channel_id, epoch),
    CONSTRAINT fk_channel_epoch_channel FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE,
    CONSTRAINT fk_channel_epoch_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS channel_key_envelopes (
    epoch_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    device_key_hash CHAR(64) NOT NULL,
    envelope MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (epoch_id, user_id, device_key_hash),
    CONSTRAINT fk_channel_envelope_epoch FOREIGN KEY (epoch_id) REFERENCES channel_key_epochs(id) ON DELETE CASCADE,
    CONSTRAINT fk_channel_envelope_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backup_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    run_type ENUM('database','uploads','full','restore_test') NOT NULL,
    status ENUM('started','success','failed') NOT NULL DEFAULT 'started',
    artifact_name VARCHAR(255) NULL,
    artifact_sha256 CHAR(64) NULL,
    bytes_written BIGINT UNSIGNED NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    details VARCHAR(1000) NOT NULL DEFAULT '',
    INDEX idx_backup_runs_type_time (run_type, started_at),
    INDEX idx_backup_runs_status (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_media_metadata (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    message_id BIGINT UNSIGNED NOT NULL,
    media_kind ENUM('voice','sticker','gif','image','video','file') NOT NULL,
    duration_ms INT UNSIGNED NULL,
    width_px INT UNSIGNED NULL,
    height_px INT UNSIGNED NULL,
    animation TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_media_message (message_id),
    CONSTRAINT fk_media_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
