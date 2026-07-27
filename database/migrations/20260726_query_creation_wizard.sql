-- Query creation wizard base table.
-- Column additions are handled by the PHP compatibility migration so the
-- upgrade remains compatible with both MySQL 5.7 and MySQL 8.0.
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
    KEY idx_draft_admin_expires (admin_id, expires_at),
    KEY idx_draft_published (published_project_id),
    CONSTRAINT fk_draft_admin FOREIGN KEY (admin_id) REFERENCES admin_users(id),
    CONSTRAINT fk_draft_project FOREIGN KEY (published_project_id) REFERENCES query_projects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
