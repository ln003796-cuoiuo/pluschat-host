CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(254) NOT NULL UNIQUE,
    username VARCHAR(32) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(80) NOT NULL DEFAULT '',
    last_name VARCHAR(80) NOT NULL DEFAULT '',
    birth_date DATE NULL,
    email_verified_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_verification_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_verify_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_verify_user (user_id),
    INDEX idx_verify_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    device_key_hash CHAR(64) NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_auth_device (user_id, device_key_hash),
    CONSTRAINT fk_auth_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_auth_user (user_id),
    INDEX idx_auth_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversation_members (
    conversation_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (conversation_id, user_id),
    CONSTRAINT fk_member_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_member_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_member_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    sender_id BIGINT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    body_encrypted TINYINT(1) NOT NULL DEFAULT 0,
    encryption_version VARCHAR(32) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    edited_at DATETIME NULL,
    CONSTRAINT fk_message_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_message_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_messages_conversation_id (conversation_id, id),
    INDEX idx_messages_sender (sender_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Pluschat extended product features: groups, channels, topics, privacy and devices.
CREATE TABLE IF NOT EXISTS user_settings (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    profile_visibility ENUM('everyone','contacts','nobody') NOT NULL DEFAULT 'everyone',
    show_birth_date TINYINT(1) NOT NULL DEFAULT 0,
    show_local_group TINYINT(1) NOT NULL DEFAULT 0,
    location_sharing TINYINT(1) NOT NULL DEFAULT 0,
    area_code VARCHAR(100) NULL,
    language VARCHAR(12) NOT NULL DEFAULT 'ru',
    currency CHAR(3) NOT NULL DEFAULT 'RUB',
    theme ENUM('system','light','dark') NOT NULL DEFAULT 'system',
    notifications_enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_devices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    device_key_hash CHAR(64) NOT NULL,
    device_name VARCHAR(120) NOT NULL DEFAULT 'Unknown device',
    platform VARCHAR(40) NOT NULL DEFAULT 'unknown',
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME NULL,
    UNIQUE KEY uq_device_user_key (user_id, device_key_hash),
    INDEX idx_devices_user (user_id, revoked_at),
    CONSTRAINT fk_device_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_blocks (
    user_id BIGINT UNSIGNED NOT NULL,
    blocked_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, blocked_user_id),
    CONSTRAINT fk_block_owner FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_block_target FOREIGN KEY (blocked_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    owner_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(120) NOT NULL,
    description VARCHAR(1000) NOT NULL DEFAULT '',
    group_kind ENUM('group','local_area') NOT NULL DEFAULT 'group',
    area_code VARCHAR(100) NULL,
    is_private TINYINT(1) NOT NULL DEFAULT 1,
    invite_code CHAR(32) NOT NULL UNIQUE,
    topics_enabled TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_groups_owner (owner_id),
    INDEX idx_groups_area (group_kind, area_code),
    UNIQUE KEY uq_group_kind_area (group_kind, area_code),
    CONSTRAINT fk_group_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS group_members (
    group_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role ENUM('owner','admin','moderator','member') NOT NULL DEFAULT 'member',
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    muted_until DATETIME NULL,
    PRIMARY KEY (group_id, user_id),
    INDEX idx_group_member_user (user_id),
    CONSTRAINT fk_group_member_group FOREIGN KEY (group_id) REFERENCES chat_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_group_member_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS channels (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    owner_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(120) NOT NULL,
    description VARCHAR(1000) NOT NULL DEFAULT '',
    is_public TINYINT(1) NOT NULL DEFAULT 0,
    invite_code CHAR(32) NOT NULL UNIQUE,
    topics_enabled TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_channels_owner (owner_id),
    CONSTRAINT fk_channel_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS channel_members (
    channel_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role ENUM('owner','admin','subscriber') NOT NULL DEFAULT 'subscriber',
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (channel_id, user_id),
    INDEX idx_channel_member_user (user_id),
    CONSTRAINT fk_channel_member_channel FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE,
    CONSTRAINT fk_channel_member_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS topics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NULL,
    channel_id BIGINT UNSIGNED NULL,
    creator_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(120) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_topics_group (group_id),
    INDEX idx_topics_channel (channel_id),
    CONSTRAINT fk_topic_group FOREIGN KEY (group_id) REFERENCES chat_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_topic_channel FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE,
    CONSTRAINT fk_topic_creator FOREIGN KEY (creator_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS topic_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    topic_id BIGINT UNSIGNED NOT NULL,
    sender_id BIGINT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    body_encrypted TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    edited_at DATETIME NULL,
    INDEX idx_topic_messages_page (topic_id, id),
    CONSTRAINT fk_topic_message_topic FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE CASCADE,
    CONSTRAINT fk_topic_message_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_attachments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    message_id BIGINT UNSIGNED NOT NULL,
    storage_key VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(127) NOT NULL DEFAULT 'application/octet-stream',
    byte_size BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    encryption_version VARCHAR(32) NOT NULL DEFAULT 'client-v1',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attachment_message (message_id),
    CONSTRAINT fk_attachment_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_receipts (
    message_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    delivered_at DATETIME NULL,
    read_at DATETIME NULL,
    PRIMARY KEY (message_id, user_id),
    INDEX idx_receipts_user (user_id, read_at),
    CONSTRAINT fk_receipt_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_receipt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_reactions (
    message_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    reaction VARCHAR(32) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (message_id, user_id, reaction),
    CONSTRAINT fk_reaction_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_reaction_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reporter_id BIGINT UNSIGNED NOT NULL,
    target_user_id BIGINT UNSIGNED NULL,
    message_id BIGINT UNSIGNED NULL,
    reason VARCHAR(80) NOT NULL,
    details VARCHAR(2000) NOT NULL DEFAULT '',
    status ENUM('open','reviewing','resolved','rejected') NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reports_status (status, created_at),
    CONSTRAINT fk_report_reporter FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_report_target FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_report_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_users (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    role ENUM('support','moderator','admin','owner') NOT NULL DEFAULT 'support',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_admin_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(40) NOT NULL,
    payload JSON NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_user (user_id, read_at, id),
    CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS group_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    sender_id BIGINT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    body_encrypted TINYINT(1) NOT NULL DEFAULT 0,
    encryption_version VARCHAR(32) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    edited_at DATETIME NULL,
    INDEX idx_group_messages_page (group_id, id),
    CONSTRAINT fk_group_message_group FOREIGN KEY (group_id) REFERENCES chat_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_group_message_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS channel_posts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    channel_id BIGINT UNSIGNED NOT NULL,
    sender_id BIGINT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    body_encrypted TINYINT(1) NOT NULL DEFAULT 0,
    encryption_version VARCHAR(32) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    edited_at DATETIME NULL,
    INDEX idx_channel_posts_page (channel_id, id),
    CONSTRAINT fk_channel_post_channel FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE,
    CONSTRAINT fk_channel_post_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS api_rate_limits (
    bucket_key CHAR(64) NOT NULL PRIMARY KEY,
    window_started_at DATETIME NOT NULL,
    hit_count INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_rate_limit_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Security foundation: public key material only. Private keys must remain on clients.
CREATE TABLE IF NOT EXISTS user_crypto_keys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    device_key_hash CHAR(64) NOT NULL,
    identity_public_key TEXT NOT NULL,
    signed_prekey TEXT NOT NULL,
    signed_prekey_signature TEXT NOT NULL,
    key_version VARCHAR(32) NOT NULL DEFAULT 'pc-e2ee-v1',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_crypto_user_device (user_id, device_key_hash),
    INDEX idx_crypto_user (user_id),
    CONSTRAINT fk_crypto_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-to-one call metadata. Audio/video itself is transported peer-to-peer by WebRTC.
CREATE TABLE IF NOT EXISTS calls (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    caller_id BIGINT UNSIGNED NOT NULL,
    callee_id BIGINT UNSIGNED NOT NULL,
    call_type ENUM('audio','video') NOT NULL,
    status ENUM('ringing','accepted','declined','missed','ended','failed') NOT NULL DEFAULT 'ringing',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    answered_at DATETIME NULL,
    ended_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    INDEX idx_calls_caller (caller_id, created_at),
    INDEX idx_calls_callee (callee_id, status, created_at),
    INDEX idx_calls_expiry (expires_at),
    CONSTRAINT fk_calls_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_calls_caller FOREIGN KEY (caller_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_calls_callee FOREIGN KEY (callee_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Short-lived WebRTC signaling only; never store audio/video recordings here.
CREATE TABLE IF NOT EXISTS call_signals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    call_id BIGINT UNSIGNED NOT NULL,
    sender_id BIGINT UNSIGNED NOT NULL,
    recipient_id BIGINT UNSIGNED NOT NULL,
    signal_type ENUM('offer','answer','ice-candidate','decline','hangup') NOT NULL,
    payload MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    INDEX idx_call_signals_poll (call_id, recipient_id, id),
    INDEX idx_call_signals_expiry (expires_at),
    CONSTRAINT fk_signal_call FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE,
    CONSTRAINT fk_signal_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_signal_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

