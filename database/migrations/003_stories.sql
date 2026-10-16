CREATE TABLE IF NOT EXISTS stories (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 author_id BIGINT UNSIGNED NOT NULL,
 encrypted_payload MEDIUMTEXT NOT NULL,
 encryption_version VARCHAR(32) NOT NULL DEFAULT 'pc-e2ee-v1',
 visibility ENUM('friends','everyone') NOT NULL DEFAULT 'friends',
 expires_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_stories_feed (expires_at, visibility, created_at),
 INDEX idx_stories_author (author_id, expires_at),
 CONSTRAINT fk_story_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS story_views (
 story_id BIGINT UNSIGNED NOT NULL,
 viewer_id BIGINT UNSIGNED NOT NULL,
 viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (story_id, viewer_id),
 INDEX idx_story_views_viewer (viewer_id, viewed_at),
 CONSTRAINT fk_story_view_story FOREIGN KEY (story_id) REFERENCES stories(id) ON DELETE CASCADE,
 CONSTRAINT fk_story_view_user FOREIGN KEY (viewer_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
