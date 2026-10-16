-- Mandatory TOTP second factor. Secrets are encrypted with a server-only master key.
CREATE TABLE IF NOT EXISTS user_two_factor (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    secret_ciphertext TEXT NOT NULL,
    enabled_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_two_factor_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS two_factor_setups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    setup_id_hash CHAR(64) NOT NULL UNIQUE,
    secret_ciphertext TEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_two_factor_setup_user (user_id),
    INDEX idx_two_factor_setup_expiry (expires_at),
    CONSTRAINT fk_two_factor_setup_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS two_factor_challenges (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    challenge_hash CHAR(64) NOT NULL UNIQUE,
    device_key_hash CHAR(64) NOT NULL,
    device_name VARCHAR(120) NOT NULL DEFAULT 'Unknown device',
    platform VARCHAR(40) NOT NULL DEFAULT 'unknown',
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_two_factor_challenge_user (user_id),
    INDEX idx_two_factor_challenge_expiry (expires_at),
    CONSTRAINT fk_two_factor_challenge_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
