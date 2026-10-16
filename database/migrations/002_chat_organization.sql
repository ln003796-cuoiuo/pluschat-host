CREATE TABLE IF NOT EXISTS chat_preferences (
 user_id BIGINT UNSIGNED NOT NULL,
 conversation_id BIGINT UNSIGNED NOT NULL,
 is_pinned TINYINT(1) NOT NULL DEFAULT 0,
 is_archived TINYINT(1) NOT NULL DEFAULT 0,
 muted_until DATETIME NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (user_id, conversation_id),
 INDEX idx_chat_preferences_pinned (user_id, is_pinned, updated_at),
 INDEX idx_chat_preferences_archived (user_id, is_archived, updated_at),
 CONSTRAINT fk_chat_pref_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_chat_pref_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_folders (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(40) NOT NULL,
 position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_chat_folder_user_name (user_id, name),
 INDEX idx_chat_folder_user_position (user_id, position, id),
 CONSTRAINT fk_chat_folder_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_folder_items (
 folder_id BIGINT UNSIGNED NOT NULL,
 conversation_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (folder_id, conversation_id),
 INDEX idx_chat_folder_items_conversation (conversation_id),
 CONSTRAINT fk_chat_folder_item_folder FOREIGN KEY (folder_id) REFERENCES chat_folders(id) ON DELETE CASCADE,
 CONSTRAINT fk_chat_folder_item_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
