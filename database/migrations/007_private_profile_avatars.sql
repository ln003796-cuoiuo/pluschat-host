-- Private profile avatars are served through authenticated API routes.
CREATE TABLE IF NOT EXISTS user_avatars (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    storage_key CHAR(64) NOT NULL UNIQUE,
    mime_type VARCHAR(40) NOT NULL,
    byte_size INT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_avatar_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
