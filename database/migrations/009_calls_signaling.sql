-- Same-host WebRTC call signaling. Media is not stored on the server.
CREATE TABLE IF NOT EXISTS calls (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    call_type ENUM('audio','video') NOT NULL DEFAULT 'audio',
    status ENUM('ringing','active','ended','missed') NOT NULL DEFAULT 'ringing',
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at DATETIME NULL,
    CONSTRAINT fk_call_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_call_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_calls_conversation_status (conversation_id, status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS call_participants (
    call_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    status ENUM('invited','joined','declined','left') NOT NULL DEFAULT 'invited',
    joined_at DATETIME NULL,
    left_at DATETIME NULL,
    PRIMARY KEY (call_id, user_id),
    CONSTRAINT fk_call_participant_call FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE,
    CONSTRAINT fk_call_participant_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_call_participants_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS call_signals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    call_id BIGINT UNSIGNED NOT NULL,
    from_user_id BIGINT UNSIGNED NOT NULL,
    to_user_id BIGINT UNSIGNED NOT NULL,
    signal_type ENUM('offer','answer','ice') NOT NULL,
    payload_json JSON NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_call_signal_call FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE,
    CONSTRAINT fk_call_signal_from FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_call_signal_to FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_call_signals_poll (call_id, to_user_id, id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
