CREATE TABLE IF NOT EXISTS admin_email_verification_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email_hash CHAR(64) NOT NULL,
    email_encrypted TEXT NOT NULL,
    code_hash CHAR(64) NOT NULL,
    verification_token CHAR(64) NOT NULL,
    session_hash CHAR(64) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    ip_encrypted TEXT NOT NULL,
    purpose VARCHAR(30) NOT NULL DEFAULT 'admin_registration',
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_verification_token (verification_token),
    KEY idx_email_hash_purpose (email_hash, purpose, status),
    KEY idx_session_hash (session_hash, created_at),
    KEY idx_ip_hash (ip_hash, created_at),
    KEY idx_expires_at (expires_at),
    KEY idx_status (status, email_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO system_settings (setting_key, value_text, value_type, is_secret, updated_by)
VALUES ('registration_email_verification_enabled', '0', 'bool', 0, NULL);
