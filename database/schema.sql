CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(32) NULL UNIQUE,
  email VARCHAR(254) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  first_name VARCHAR(80) NOT NULL DEFAULT '',
  last_name VARCHAR(80) NOT NULL DEFAULT '',
  username VARCHAR(32) NOT NULL UNIQUE,
  bio VARCHAR(500) NOT NULL DEFAULT '',
  birth_date DATE NULL,
  education VARCHAR(160) NOT NULL DEFAULT '',
  avatar_path VARCHAR(255) NULL,
  email_verified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_challenges (
  id CHAR(64) NOT NULL PRIMARY KEY,
  kind ENUM('phone','email') NOT NULL,
  destination VARCHAR(254) NOT NULL,
  code_hash CHAR(64) NOT NULL,
  parent_challenge_id CHAR(64) NULL,
  expires_at DATETIME NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  verified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_challenge_destination (destination),
  KEY idx_challenge_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  access_hash CHAR(64) NOT NULL UNIQUE,
  refresh_hash CHAR(64) NOT NULL UNIQUE,
  access_expires_at DATETIME NOT NULL,
  refresh_expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sessions_user (user_id),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chats (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  type ENUM('direct','group','channel') NOT NULL DEFAULT 'direct',
  title VARCHAR(120) NOT NULL DEFAULT '',
  description TEXT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_chats_created_by (created_by),
  CONSTRAINT fk_chats_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_members (
  chat_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (chat_id, user_id),
  KEY idx_chat_members_user (user_id),
  CONSTRAINT fk_chat_members_chat FOREIGN KEY (chat_id) REFERENCES chats(id) ON DELETE CASCADE,
  CONSTRAINT fk_chat_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chat_id BIGINT UNSIGNED NOT NULL,
  sender_id BIGINT UNSIGNED NOT NULL,
  client_message_id CHAR(36) NOT NULL,
  type ENUM('text') NOT NULL DEFAULT 'text',
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_message_client_id (sender_id, client_message_id),
  KEY idx_messages_chat_id (chat_id, id),
  CONSTRAINT fk_messages_chat FOREIGN KEY (chat_id) REFERENCES chats(id) ON DELETE CASCADE,
  CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contacts (
  owner_id BIGINT UNSIGNED NOT NULL,
  contact_id BIGINT UNSIGNED NOT NULL,
  alias VARCHAR(120) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (owner_id, contact_id),
  KEY idx_contacts_contact (contact_id),
  CONSTRAINT fk_contacts_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_contacts_contact FOREIGN KEY (contact_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(50) NOT NULL,
  payload JSON NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notifications_user (user_id, id),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS groups (
  chat_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  is_public TINYINT(1) NOT NULL DEFAULT 0,
  topics_enabled TINYINT(1) NOT NULL DEFAULT 1,
  description TEXT NULL,
  CONSTRAINT fk_groups_chat FOREIGN KEY (chat_id) REFERENCES chats(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS channels (
  chat_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  is_public TINYINT(1) NOT NULL DEFAULT 0,
  username VARCHAR(32) NULL UNIQUE,
  description TEXT NULL,
  CONSTRAINT fk_channels_chat FOREIGN KEY (chat_id) REFERENCES chats(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS topics (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chat_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(120) NOT NULL,
  description TEXT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_topics_chat (chat_id, id),
  CONSTRAINT fk_topics_chat FOREIGN KEY (chat_id) REFERENCES chats(id) ON DELETE CASCADE,
  CONSTRAINT fk_topics_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS channel_posts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  channel_id BIGINT UNSIGNED NOT NULL,
  sender_id BIGINT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_channel_posts (channel_id, id),
  CONSTRAINT fk_channel_posts_channel FOREIGN KEY (channel_id) REFERENCES channels(chat_id) ON DELETE CASCADE,
  CONSTRAINT fk_channel_posts_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
