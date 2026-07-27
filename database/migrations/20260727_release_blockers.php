<?php

declare(strict_types=1);

return static function (MigrationRunner $runner, PDO $pdo): void {
    $runner->addColumnIfMissing(
        'query_projects',
        'login_notice',
        'TEXT NULL AFTER `notice`'
    );
    $runner->addColumnIfMissing(
        'query_projects',
        'result_notice',
        'TEXT NULL AFTER `login_notice`'
    );
    $runner->addColumnIfMissing(
        'query_projects',
        'published_at',
        'DATETIME NULL AFTER `sort_order`'
    );
    $runner->addColumnIfMissing(
        'import_batches',
        'draft_token',
        'CHAR(64) NULL AFTER `project_id`'
    );
    $runner->addColumnIfMissing(
        'import_batches',
        'purpose',
        "VARCHAR(20) NOT NULL DEFAULT 'import' AFTER `draft_token`"
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS excel_upload_attempts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            admin_id BIGINT UNSIGNED NOT NULL,
            ip_hash CHAR(64) NOT NULL,
            purpose VARCHAR(32) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'accepted\',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_excel_upload_admin_time (admin_id, created_at),
            KEY idx_excel_upload_ip_time (ip_hash, created_at),
            KEY idx_excel_upload_purpose_time (purpose, created_at),
            CONSTRAINT fk_excel_upload_admin FOREIGN KEY (admin_id)
                REFERENCES admin_users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $runner->addIndexIfMissing(
        'query_creation_drafts',
        'uq_draft_published_project',
        ['published_project_id'],
        true
    );
    $runner->addIndexIfMissing(
        'import_batches',
        'uq_import_batches_draft_token',
        ['draft_token'],
        true
    );

    $setting = $pdo->prepare(
        'INSERT IGNORE INTO system_settings
            (setting_key, value_text, value_type, is_secret, updated_by)
         VALUES (?, ?, ?, 0, NULL)'
    );
    $setting->execute(['registration_email_verification_enabled', '0', 'bool']);

    $updatedBy = $runner->columnInfo('system_settings', 'updated_by');
    if ($updatedBy !== null && strtoupper((string) $updatedBy['IS_NULLABLE']) !== 'YES') {
        $pdo->exec('ALTER TABLE system_settings MODIFY COLUMN updated_by BIGINT UNSIGNED NULL');
        $updatedBy = $runner->columnInfo('system_settings', 'updated_by');
    }
    if ($updatedBy === null || strtoupper((string) $updatedBy['IS_NULLABLE']) !== 'YES') {
        throw new RuntimeException('system_settings.updated_by must allow NULL.');
    }
    $pdo->exec(
        'UPDATE system_settings settings_row
         LEFT JOIN admin_users admin_row ON admin_row.id = settings_row.updated_by
         SET settings_row.updated_by = NULL
         WHERE settings_row.updated_by = 0 OR admin_row.id IS NULL'
    );
    if (!$runner->foreignKeyExists('system_settings', 'fk_system_settings_admin')) {
        $pdo->exec(
            'ALTER TABLE system_settings
             ADD CONSTRAINT fk_system_settings_admin
             FOREIGN KEY (updated_by) REFERENCES admin_users(id) ON DELETE SET NULL'
        );
    }
    if (!$runner->foreignKeyExists('system_settings', 'fk_system_settings_admin')) {
        throw new RuntimeException('system_settings.updated_by foreign key is missing.');
    }

    $expectedColumns = [
        ['query_projects', 'login_notice', 'text', true],
        ['query_projects', 'result_notice', 'text', true],
        ['query_projects', 'published_at', 'datetime', true],
        ['import_batches', 'draft_token', 'char(64)', true],
        ['import_batches', 'purpose', 'varchar(20)', false],
    ];
    foreach ($expectedColumns as [$table, $column, $type, $nullable]) {
        $info = $runner->columnInfo($table, $column);
        if (
            $info === null
            || strtolower((string) $info['COLUMN_TYPE']) !== $type
            || (strtoupper((string) $info['IS_NULLABLE']) === 'YES') !== $nullable
        ) {
            throw new RuntimeException(sprintf(
                'Column %s.%s exists with an incompatible definition.',
                $table,
                $column
            ));
        }
    }
};
