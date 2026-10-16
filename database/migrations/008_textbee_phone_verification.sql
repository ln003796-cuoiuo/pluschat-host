-- Idempotent phone/SMS verification migration for both existing and fresh databases.
SET @pc_phone_col = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'phone');
SET @pc_sql = IF(@pc_phone_col = 0, 'ALTER TABLE users ADD COLUMN phone VARCHAR(20) NULL AFTER email', 'SELECT 1');
PREPARE pc_stmt FROM @pc_sql;
EXECUTE pc_stmt;
DEALLOCATE PREPARE pc_stmt;

SET @pc_verified_col = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'phone_verified_at');
SET @pc_sql = IF(@pc_verified_col = 0, 'ALTER TABLE users ADD COLUMN phone_verified_at DATETIME NULL AFTER email_verified_at', 'SELECT 1');
PREPARE pc_stmt FROM @pc_sql;
EXECUTE pc_stmt;
DEALLOCATE PREPARE pc_stmt;

SET @pc_phone_index = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'uq_users_phone');
SET @pc_sql = IF(@pc_phone_index = 0, 'ALTER TABLE users ADD UNIQUE KEY uq_users_phone (phone)', 'SELECT 1');
PREPARE pc_stmt FROM @pc_sql;
EXECUTE pc_stmt;
DEALLOCATE PREPARE pc_stmt;

CREATE TABLE IF NOT EXISTS phone_verification_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    phone VARCHAR(20) NOT NULL,
    code_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_phone_verification_user (user_id),
    UNIQUE KEY uq_phone_verification_phone (phone),
    INDEX idx_phone_verification_expiry (expires_at),
    CONSTRAINT fk_phone_verification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
