SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS schema_migrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    migration VARCHAR(255) NOT NULL,
    batch INT NOT NULL,
    executed_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_schema_migrations_name (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(100) NOT NULL,
    username_normalized VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'admin',
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    phone_encrypted TEXT NULL,
    phone_hash CHAR(64) NULL,
    phone_masked VARCHAR(32) NULL,
    email_encrypted TEXT NULL,
    email_hash CHAR(64) NULL,
    email_masked VARCHAR(255) NULL,
    must_change_password TINYINT(1) NOT NULL DEFAULT 1,
    password_changed_at DATETIME NULL,
    failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    rejected_by BIGINT UNSIGNED NULL,
    rejected_at DATETIME NULL,
    rejection_reason VARCHAR(300) NULL,
    disabled_by BIGINT UNSIGNED NULL,
    disabled_at DATETIME NULL,
    last_login_at DATETIME NULL,
    last_login_ip TEXT NULL,
    last_login_ip_hash CHAR(64) NULL,
    registration_ip_encrypted TEXT NULL,
    registration_ip_hash CHAR(64) NULL,
    registration_user_agent VARCHAR(1000) NOT NULL DEFAULT '',
    registration_device_type VARCHAR(30) NOT NULL DEFAULT '其他',
    registration_os_name VARCHAR(80) NOT NULL DEFAULT '未知',
    registration_browser_name VARCHAR(80) NOT NULL DEFAULT '未知',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_users_username (username),
    UNIQUE KEY uq_admin_username_normalized (username_normalized),
    UNIQUE KEY uq_admin_phone_hash (phone_hash),
    UNIQUE KEY uq_admin_email_hash (email_hash),
    KEY idx_admin_role_status (role, status),
    KEY idx_admin_registration_ip (registration_ip_hash, created_at),
    CONSTRAINT fk_admin_approved_by FOREIGN KEY (approved_by) REFERENCES admin_users(id) ON DELETE SET NULL,
    CONSTRAINT fk_admin_rejected_by FOREIGN KEY (rejected_by) REFERENCES admin_users(id) ON DELETE SET NULL,
    CONSTRAINT fk_admin_disabled_by FOREIGN KEY (disabled_by) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username_hash CHAR(64) NOT NULL,
    identifier_hash CHAR(64) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    ip_encrypted TEXT NULL,
    admin_id BIGINT UNSIGNED NULL,
    is_success TINYINT(1) NOT NULL DEFAULT 0,
    failure_reason VARCHAR(40) NOT NULL DEFAULT 'invalid_credentials',
    user_agent VARCHAR(1000) NOT NULL DEFAULT '',
    device_type VARCHAR(30) NOT NULL DEFAULT '其他',
    os_name VARCHAR(80) NOT NULL DEFAULT '未知',
    browser_name VARCHAR(80) NOT NULL DEFAULT '未知',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_attempts_user_time (username_hash, created_at),
    KEY idx_login_attempts_identifier_time (identifier_hash, created_at),
    KEY idx_login_attempts_ip_time (ip_hash, created_at),
    KEY idx_login_attempts_admin_time (admin_id, created_at),
    KEY idx_login_attempts_reason_time (failure_reason, created_at),
    CONSTRAINT fk_login_attempt_admin FOREIGN KEY (admin_id) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_ip_blocks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip_hash CHAR(64) NOT NULL,
    ip_encrypted TEXT NOT NULL,
    reason VARCHAR(60) NOT NULL DEFAULT 'invalid_credentials',
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    blocked_at DATETIME NULL,
    blocked_until DATETIME NULL,
    last_failed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_auth_ip_blocks_ip_hash (ip_hash),
    KEY idx_auth_ip_blocks_until (blocked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_registration_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip_hash CHAR(64) NOT NULL,
    ip_encrypted TEXT NOT NULL,
    was_created TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_registration_ip_time (ip_hash, created_at),
    KEY idx_registration_created_time (was_created, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    setting_key VARCHAR(100) NOT NULL,
    value_text TEXT NULL,
    value_encrypted TEXT NULL,
    value_type VARCHAR(30) NOT NULL DEFAULT 'string',
    is_secret TINYINT(1) NOT NULL DEFAULT 0,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_system_settings_key (setting_key),
    KEY idx_system_settings_updated_by (updated_by),
    CONSTRAINT fk_system_settings_admin FOREIGN KEY (updated_by) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    KEY idx_email_code_ip_hash (ip_hash, created_at),
    KEY idx_email_code_expires_at (expires_at),
    KEY idx_email_code_status (status, email_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS query_projects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    description TEXT NULL,
    notice TEXT NULL,
    login_notice TEXT NULL,
    result_notice TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'enabled',
    sort_order INT NOT NULL DEFAULT 0,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_query_projects_slug (slug),
    KEY idx_query_projects_status_sort (status, deleted_at, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS query_creation_drafts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token CHAR(64) NOT NULL,
    admin_id BIGINT UNSIGNED NOT NULL,
    current_step TINYINT UNSIGNED NOT NULL DEFAULT 1,
    original_filename VARCHAR(255) NULL,
    preview_path VARCHAR(255) NULL,
    suggested_name VARCHAR(150) NULL,
    step_data_json TEXT NULL,
    validation_summary_json TEXT NULL,
    expires_at DATETIME NOT NULL,
    published_project_id BIGINT UNSIGNED NULL,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_draft_token (token),
    UNIQUE KEY uq_draft_published_project (published_project_id),
    KEY idx_draft_admin_expires (admin_id, expires_at),
    CONSTRAINT fk_draft_admin FOREIGN KEY (admin_id) REFERENCES admin_users(id),
    CONSTRAINT fk_draft_project FOREIGN KEY (published_project_id) REFERENCES query_projects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS query_fields (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    field_key VARCHAR(64) NOT NULL,
    field_label VARCHAR(100) NOT NULL,
    field_type VARCHAR(20) NOT NULL DEFAULT 'text',
    field_role VARCHAR(20) NOT NULL DEFAULT 'result',
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    is_sensitive TINYINT(1) NOT NULL DEFAULT 0,
    is_visible TINYINT(1) NOT NULL DEFAULT 1,
    options_json TEXT NULL,
    placeholder VARCHAR(255) NULL,
    validation_rule VARCHAR(255) NULL,
    decimal_places TINYINT UNSIGNED NOT NULL DEFAULT 2,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_query_fields_project_key (project_id, field_key),
    KEY idx_query_fields_project_sort (project_id, deleted_at, sort_order),
    CONSTRAINT fk_query_fields_project FOREIGN KEY (project_id) REFERENCES query_projects(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS query_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    record_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_query_records_project_active (project_id, deleted_at, id),
    KEY idx_query_records_hash (project_id, record_hash, deleted_at),
    CONSTRAINT fk_query_records_project FOREIGN KEY (project_id) REFERENCES query_projects(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS query_record_values (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    record_id BIGINT UNSIGNED NOT NULL,
    field_id BIGINT UNSIGNED NOT NULL,
    value_text TEXT NULL,
    value_number DECIMAL(30,10) NULL,
    value_date DATE NULL,
    value_encrypted TEXT NULL,
    search_hash CHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_record_field (record_id, field_id),
    KEY idx_field_search_hash (field_id, search_hash),
    KEY idx_field_number (field_id, value_number),
    KEY idx_field_date (field_id, value_date),
    CONSTRAINT fk_record_values_record FOREIGN KEY (record_id) REFERENCES query_records(id) ON DELETE CASCADE,
    CONSTRAINT fk_record_values_field FOREIGN KEY (field_id) REFERENCES query_fields(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS query_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NULL,
    record_id BIGINT UNSIGNED NULL,
    is_success TINYINT(1) NOT NULL DEFAULT 0,
    ip_address TEXT NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    user_agent VARCHAR(1000) NOT NULL DEFAULT '',
    device_type VARCHAR(30) NOT NULL DEFAULT '其他',
    os_name VARCHAR(80) NOT NULL DEFAULT '未知',
    browser_name VARCHAR(80) NOT NULL DEFAULT '未知',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_query_logs_project_time (project_id, created_at),
    KEY idx_query_logs_record_time (record_id, created_at),
    KEY idx_query_logs_ip_time (ip_hash, created_at),
    KEY idx_query_logs_success_time (is_success, created_at),
    CONSTRAINT fk_query_logs_project FOREIGN KEY (project_id) REFERENCES query_projects(id),
    CONSTRAINT fk_query_logs_record FOREIGN KEY (record_id) REFERENCES query_records(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NULL,
    entity_id BIGINT UNSIGNED NULL,
    is_success TINYINT(1) NOT NULL DEFAULT 1,
    ip_address TEXT NOT NULL,
    details_json TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_admin_time (admin_id, created_at),
    KEY idx_audit_action_time (action, created_at),
    CONSTRAINT fk_audit_admin FOREIGN KEY (admin_id) REFERENCES admin_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token CHAR(64) NOT NULL,
    admin_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    draft_token CHAR(64) NULL,
    purpose VARCHAR(20) NOT NULL DEFAULT 'import',
    import_mode VARCHAR(20) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    preview_path VARCHAR(255) NOT NULL,
    total_rows INT UNSIGNED NOT NULL DEFAULT 0,
    valid_rows INT UNSIGNED NOT NULL DEFAULT 0,
    error_rows INT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    committed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_import_batches_token (token),
    UNIQUE KEY uq_import_batches_draft_token (draft_token),
    KEY idx_import_batches_admin_expiry (admin_id, expires_at),
    CONSTRAINT fk_import_admin FOREIGN KEY (admin_id) REFERENCES admin_users(id),
    CONSTRAINT fk_import_project FOREIGN KEY (project_id) REFERENCES query_projects(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS excel_upload_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id BIGINT UNSIGNED NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    purpose VARCHAR(32) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'accepted',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_excel_upload_admin_time (admin_id, created_at),
    KEY idx_excel_upload_ip_time (ip_hash, created_at),
    KEY idx_excel_upload_purpose_time (purpose, created_at),
    CONSTRAINT fk_excel_upload_admin FOREIGN KEY (admin_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
