<?php

declare(strict_types=1);

require_once __DIR__ . '/MigrationRunner.php';
require_once __DIR__ . '/AdminValidation.php';

final class Installer
{
    /**
     * @return array{
     *   migrations:array{executed:list<string>,skipped:list<string>,batch:int},
     *   admin_created:bool,
     *   admin_username:string,
     *   temporary_password:?string,
     *   tables_verified:int,
     *   columns_verified:int
     * }
     */
    public static function install(
        PDO $pdo,
        string $schemaPath,
        string $migrationDirectory,
        ?callable $logger = null,
        ?array $superAdministrator = null
    ): array {
        $logger ??= static function (string $message): void {
            fwrite(STDOUT, $message . PHP_EOL);
        };

        self::runSchema($pdo, $schemaPath);
        $runner = new MigrationRunner($pdo, $migrationDirectory, $logger);
        $runner->ensureMigrationsTable();
        $migrations = $runner->runPendingMigrations();
        [$tablesVerified, $columnsVerified] = self::verifyRequiredStructure($runner);

        $adminCreated = false;
        $temporaryPassword = null;
        $adminUsername = 'admin';
        $configuredPassword = null;
        if ($superAdministrator !== null) {
            $adminUsername = trim((string) ($superAdministrator['username'] ?? ''));
            $configuredPassword = (string) ($superAdministrator['password'] ?? '');
            $usernameError = AdminValidation::validateUsername($adminUsername, true);
            $passwordError = AdminValidation::validatePassword(
                $configuredPassword,
                $adminUsername
            );
            if ($usernameError !== null) {
                throw new InvalidArgumentException($usernameError);
            }
            if ($passwordError !== null) {
                throw new InvalidArgumentException($passwordError);
            }
        }

        $startedTransaction = !$pdo->inTransaction();
        if ($startedTransaction) {
            $pdo->beginTransaction();
        }
        try {
            self::seedSettings($pdo);

            $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
            if ($adminCount === 0) {
                $password = $configuredPassword;
                $mustChangePassword = 0;
                if ($password === null) {
                    $temporaryPassword = self::generateTemporaryPassword();
                    $password = $temporaryPassword;
                    $mustChangePassword = 1;
                }
                $stmt = $pdo->prepare(
                    "INSERT INTO admin_users
                        (username, username_normalized, password_hash, role, status, must_change_password)
                     VALUES (?, ?, ?, 'super_admin', 'active', ?)"
                );
                $stmt->execute([
                    $adminUsername,
                    AdminValidation::normalizeUsername($adminUsername),
                    password_hash($password, PASSWORD_DEFAULT),
                    $mustChangePassword,
                ]);
                $adminCreated = true;
            }

            if ($startedTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return [
            'migrations' => $migrations,
            'admin_created' => $adminCreated,
            'admin_username' => $adminUsername,
            'temporary_password' => $temporaryPassword,
            'tables_verified' => $tablesVerified,
            'columns_verified' => $columnsVerified,
        ];
    }

    public static function runSchema(PDO $pdo, string $schemaPath): void
    {
        $schema = file_get_contents($schemaPath);
        if (!is_string($schema)) {
            throw new RuntimeException('Unable to read database/schema.sql.');
        }

        $statements = MigrationRunner::parseSqlStatements($schema);
        foreach ($statements as $index => $statement) {
            try {
                $pdo->exec($statement);
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    sprintf(
                        'Schema installation failed at statement #%d. MySQL DDL may already have taken effect; inspect the schema before retrying. %s',
                        $index + 1,
                        $exception->getMessage()
                    ),
                    0,
                    $exception
                );
            }
        }
    }

    /** @return array{int,int} */
    public static function verifyRequiredStructure(MigrationRunner $runner): array
    {
        $tables = [
            'schema_migrations',
            'admin_users',
            'admin_login_attempts',
            'auth_ip_blocks',
            'admin_audit_logs',
            'system_settings',
            'admin_email_verification_codes',
            'query_projects',
            'query_fields',
            'query_records',
            'query_record_values',
            'query_logs',
            'import_batches',
            'query_creation_drafts',
            'excel_upload_attempts',
        ];
        foreach ($tables as $table) {
            $runner->assertTableExists($table);
        }

        $columns = [
            'query_projects' => ['login_notice', 'result_notice', 'published_at'],
            'import_batches' => ['draft_token', 'purpose'],
            'admin_email_verification_codes' => [
                'email_hash',
                'email_encrypted',
                'code_hash',
                'session_hash',
                'verification_token',
                'ip_hash',
                'ip_encrypted',
                'max_attempts',
                'expires_at',
                'consumed_at',
            ],
        ];
        $columnCount = 0;
        foreach ($columns as $table => $requiredColumns) {
            foreach ($requiredColumns as $column) {
                $runner->assertColumnExists($table, $column);
                $columnCount++;
            }
        }
        if (
            !$runner->indexExists(
                'query_creation_drafts',
                'uq_draft_published_project',
                ['published_project_id'],
                true
            )
            || !$runner->indexExists(
                'import_batches',
                'uq_import_batches_draft_token',
                ['draft_token'],
                true
            )
        ) {
            throw new RuntimeException('Required publish idempotency index is missing.');
        }
        $updatedBy = $runner->columnInfo('system_settings', 'updated_by');
        if (
            $updatedBy === null
            || strtoupper((string) $updatedBy['IS_NULLABLE']) !== 'YES'
            || !$runner->foreignKeyExists(
                'system_settings',
                'fk_system_settings_admin'
            )
        ) {
            throw new RuntimeException(
                'system_settings.updated_by structure is incompatible.'
            );
        }

        return [count($tables), $columnCount];
    }

    private static function seedSettings(PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO system_settings
                (setting_key, value_text, value_type, is_secret, updated_by)
             VALUES (?, ?, ?, 0, NULL)'
        );
        foreach ([
            ['turnstile_enabled', '0', 'bool'],
            ['turnstile_login_enabled', '1', 'bool'],
            ['turnstile_register_enabled', '1', 'bool'],
            ['turnstile_site_key', '', 'string'],
            ['turnstile_allowed_hostnames', '[]', 'json'],
            ['turnstile_theme', 'auto', 'string'],
            ['turnstile_size', 'normal', 'string'],
            ['registration_email_verification_enabled', '0', 'bool'],
        ] as [$key, $value, $type]) {
            $stmt->execute([$key, $value, $type]);
        }
    }

    private static function generateTemporaryPassword(): string
    {
        $groups = [
            'ABCDEFGHJKLMNPQRSTUVWXYZ',
            'abcdefghijkmnopqrstuvwxyz',
            '23456789',
            '!@#$%*-_',
        ];
        $characters = [];
        foreach ($groups as $group) {
            $characters[] = $group[random_int(0, strlen($group) - 1)];
        }
        $alphabet = implode('', $groups);
        while (count($characters) < 20) {
            $characters[] = $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        for ($i = count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }
        return implode('', $characters);
    }
}
