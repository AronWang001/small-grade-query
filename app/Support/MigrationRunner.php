<?php

declare(strict_types=1);

final class MigrationExecutionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $migration,
        public readonly int $statementNumber = 0,
        public readonly bool $ddlMayHaveApplied = false,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}

/**
 * Shared, side-effect-free migration implementation.
 *
 * Loading this file only defines classes. Database work starts only after a
 * caller constructs MigrationRunner and invokes one of its methods.
 */
final class MigrationRunner
{
    /** @var callable(string): void */
    private $logger;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationDirectory,
        ?callable $logger = null
    ) {
        $this->logger = $logger ?? static function (string $message): void {
            fwrite(STDOUT, $message . PHP_EOL);
        };
    }

    /**
     * Parse SQL without damaging comments or semicolons inside literals.
     *
     * DELIMITER, stored procedures and triggers are intentionally unsupported.
     *
     * @return list<string>
     */
    public static function parseSqlStatements(string $sql): array
    {
        $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
        $length = strlen($sql);
        $statements = [];
        $buffer = '';
        $state = 'normal';
        $lineStart = true;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($state === 'line_comment') {
                if ($char === "\n") {
                    $buffer .= "\n";
                    $state = 'normal';
                    $lineStart = true;
                }
                continue;
            }

            if ($state === 'block_comment') {
                if ($char === '*' && $next === '/') {
                    $i++;
                    $state = 'normal';
                } elseif ($char === "\n") {
                    $buffer .= "\n";
                    $lineStart = true;
                }
                continue;
            }

            if ($state !== 'normal') {
                $buffer .= $char;
                if ($char === '\\' && $next !== '') {
                    $buffer .= $next;
                    $i++;
                    continue;
                }
                $quote = $state === 'single' ? "'" : ($state === 'double' ? '"' : '`');
                if ($char === $quote) {
                    if ($next === $quote) {
                        $buffer .= $next;
                        $i++;
                    } else {
                        $state = 'normal';
                    }
                }
                if ($char === "\n") {
                    $lineStart = true;
                }
                continue;
            }

            if ($lineStart && ($char === ' ' || $char === "\t" || $char === "\r")) {
                $buffer .= $char;
                continue;
            }

            if ($lineStart && strncasecmp(substr($sql, $i, 9), 'DELIMITER', 9) === 0) {
                $after = $i + 9 < $length ? $sql[$i + 9] : '';
                if ($after === '' || ctype_space($after)) {
                    throw new InvalidArgumentException(
                        'Migration SQL does not support DELIMITER, stored procedures or triggers.'
                    );
                }
            }

            $lineStart = false;

            if ($char === '-' && $next === '-') {
                $after = $i + 2 < $length ? $sql[$i + 2] : '';
                if ($after === '' || ctype_space($after)) {
                    $state = 'line_comment';
                    $i++;
                    continue;
                }
            }
            if ($char === '#') {
                $state = 'line_comment';
                continue;
            }
            if ($char === '/' && $next === '*') {
                $state = 'block_comment';
                $i++;
                continue;
            }
            if ($char === "'") {
                $state = 'single';
                $buffer .= $char;
                continue;
            }
            if ($char === '"') {
                $state = 'double';
                $buffer .= $char;
                continue;
            }
            if ($char === '`') {
                $state = 'backtick';
                $buffer .= $char;
                continue;
            }
            if ($char === ';') {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $char;
            if ($char === "\n") {
                $lineStart = true;
            }
        }

        if ($state === 'single' || $state === 'double' || $state === 'backtick' || $state === 'block_comment') {
            throw new InvalidArgumentException('Migration SQL contains an unterminated literal or comment.');
        }

        $remaining = trim($buffer);
        if ($remaining !== '') {
            $statements[] = $remaining;
        }

        return $statements;
    }

    public function ensureMigrationsTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                migration VARCHAR(255) NOT NULL,
                batch INT NOT NULL,
                executed_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_schema_migrations_name (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return list<string> */
    public function discoverMigrations(?string $directory = null): array
    {
        $directory ??= $this->migrationDirectory;
        if (!is_dir($directory)) {
            throw new RuntimeException('Migration directory does not exist.');
        }

        $files = [];
        foreach (['*.sql', '*.php'] as $pattern) {
            foreach (glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $pattern) ?: [] as $file) {
                if (is_file($file)) {
                    $files[] = $file;
                }
            }
        }
        usort($files, static fn (string $a, string $b): int => basename($a) <=> basename($b));
        return $files;
    }

    /** @return list<string> */
    public function getExecutedMigrations(): array
    {
        $this->ensureMigrationsTable();
        $rows = $this->pdo->query('SELECT migration FROM schema_migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_map('strval', $rows));
    }

    public function getNextBatch(): int
    {
        $this->ensureMigrationsTable();
        return (int) $this->pdo->query('SELECT COALESCE(MAX(batch), 0) + 1 FROM schema_migrations')->fetchColumn();
    }

    /**
     * @return array{executed:list<string>,skipped:list<string>,batch:int}
     */
    public function runPendingMigrations(?string $directory = null): array
    {
        $this->ensureMigrationsTable();
        $executed = array_fill_keys($this->getExecutedMigrations(), true);
        $batch = $this->getNextBatch();
        $result = ['executed' => [], 'skipped' => [], 'batch' => $batch];

        foreach ($this->discoverMigrations($directory) as $file) {
            $name = basename($file);
            if (isset($executed[$name])) {
                $this->log('SKIP ' . $name . ' (already executed)');
                $result['skipped'][] = $name;
                continue;
            }

            $this->log('RUN  ' . $name);
            $this->runMigrationFile($file, $batch);
            $result['executed'][] = $name;
            $executed[$name] = true;
            $this->log('OK   ' . $name);
        }

        return $result;
    }

    public function runMigrationFile(
        string $file,
        int $batch,
        ?callable $additionalVerifier = null,
        bool $record = true
    ): void {
        if (!is_file($file)) {
            throw new RuntimeException('Migration file does not exist: ' . basename($file));
        }

        $name = basename($file);
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $ddlExecuted = false;
        $statementNumber = 0;

        try {
            if ($extension === 'sql') {
                $sql = file_get_contents($file);
                if (!is_string($sql)) {
                    throw new RuntimeException('Unable to read migration file.');
                }
                $statements = self::parseSqlStatements($sql);
                foreach ($statements as $index => $statement) {
                    $statementNumber = $index + 1;
                    if (preg_match('/^\s*(CREATE|ALTER|DROP|RENAME|TRUNCATE)\b/i', $statement) === 1) {
                        $ddlExecuted = true;
                    }
                    $this->pdo->exec($statement);
                }
            } elseif ($extension === 'php') {
                $migration = require $file;
                if (!is_callable($migration)) {
                    throw new RuntimeException('PHP migration must return a callable.');
                }
                $migration($this, $this->pdo);
                $ddlExecuted = true;
            } else {
                throw new RuntimeException('Unsupported migration extension.');
            }

            $this->verifyMigrationResult($name);
            if ($additionalVerifier !== null) {
                $additionalVerifier($this, $this->pdo);
            }
            if ($record) {
                $this->recordMigration($name, $batch);
            }
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $message = sprintf(
                'Migration %s failed%s. %s%s',
                $name,
                $statementNumber > 0 ? ' at statement #' . $statementNumber : '',
                $exception->getMessage(),
                $ddlExecuted
                    ? ' MySQL DDL may already have taken effect; inspect the schema before retrying.'
                    : ''
            );
            throw new MigrationExecutionException(
                $message,
                $name,
                $statementNumber,
                $ddlExecuted,
                $exception
            );
        }
    }

    public function verifyMigrationResult(string $migration): void
    {
        if (str_contains($migration, 'admin_security')) {
            foreach (['auth_ip_blocks', 'admin_registration_attempts', 'system_settings'] as $table) {
                $this->assertTableExists($table);
            }
        }
        if (str_contains($migration, 'admin_email_verification')) {
            $this->assertTableExists('admin_email_verification_codes');
            foreach (['email_hash', 'email_encrypted', 'code_hash', 'session_hash', 'verification_token', 'ip_hash', 'ip_encrypted', 'max_attempts', 'expires_at', 'consumed_at'] as $column) {
                $this->assertColumnExists('admin_email_verification_codes', $column);
            }
        }
        if (str_contains($migration, 'query_creation_wizard')) {
            $this->assertTableExists('query_creation_drafts');
        }
        if (str_contains($migration, 'release_blockers')) {
            $this->assertTableExists('excel_upload_attempts');
            foreach (['login_notice', 'result_notice', 'published_at'] as $column) {
                $this->assertColumnExists('query_projects', $column);
            }
            foreach (['draft_token', 'purpose'] as $column) {
                $this->assertColumnExists('import_batches', $column);
            }
            if (
                !$this->indexExists(
                    'query_creation_drafts',
                    'uq_draft_published_project',
                    ['published_project_id'],
                    true
                )
                || !$this->indexExists(
                    'import_batches',
                    'uq_import_batches_draft_token',
                    ['draft_token'],
                    true
                )
            ) {
                throw new RuntimeException('Required publish idempotency index is missing.');
            }
            $updatedBy = $this->columnInfo('system_settings', 'updated_by');
            if (
                $updatedBy === null
                || strtoupper((string) $updatedBy['IS_NULLABLE']) !== 'YES'
                || !$this->foreignKeyExists(
                    'system_settings',
                    'fk_system_settings_admin'
                )
            ) {
                throw new RuntimeException(
                    'system_settings.updated_by structure is incompatible.'
                );
            }
        }
    }

    public function recordMigration(string $migration, int $batch): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO schema_migrations (migration, batch, executed_at) VALUES (?, ?, NOW())'
        );
        $stmt->execute([$migration, $batch]);
    }

    public function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() === 1;
    }

    /** @return array<string,mixed>|null */
    public function columnInfo(string $table, string $column): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function columnExists(string $table, string $column): bool
    {
        return $this->columnInfo($table, $column) !== null;
    }

    /** @return array{columns:list<string>,unique:bool}|null */
    public function indexInfo(string $table, string $index): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COLUMN_NAME, NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
             ORDER BY SEQ_IN_INDEX'
        );
        $stmt->execute([$table, $index]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return null;
        }
        return [
            'columns' => array_values(array_map(
                static fn (array $row): string => (string) $row['COLUMN_NAME'],
                $rows
            )),
            'unique' => (int) $rows[0]['NON_UNIQUE'] === 0,
        ];
    }

    /** @return list<string>|null */
    public function indexColumns(string $table, string $index): ?array
    {
        return $this->indexInfo($table, $index)['columns'] ?? null;
    }

    public function indexExists(
        string $table,
        string $index,
        ?array $expectedColumns = null,
        ?bool $expectedUnique = null
    ): bool
    {
        $info = $this->indexInfo($table, $index);
        if ($info === null) {
            return false;
        }
        if ($expectedColumns !== null && array_values($expectedColumns) !== $info['columns']) {
            throw new RuntimeException(
                sprintf('Index %s.%s exists with incompatible columns.', $table, $index)
            );
        }
        if ($expectedUnique !== null && $expectedUnique !== $info['unique']) {
            throw new RuntimeException(
                sprintf('Index %s.%s has an incompatible uniqueness definition.', $table, $index)
            );
        }
        return true;
    }

    public function foreignKeyExists(string $table, string $constraint): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL'
        );
        $stmt->execute([$table, $constraint]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function addColumnIfMissing(string $table, string $column, string $definition): bool
    {
        $this->assertIdentifier($table);
        $this->assertIdentifier($column);
        if ($this->columnExists($table, $column)) {
            return false;
        }
        $this->pdo->exec(sprintf(
            'ALTER TABLE `%s` ADD COLUMN `%s` %s',
            $table,
            $column,
            $definition
        ));
        $this->assertColumnExists($table, $column);
        return true;
    }

    /** @param list<string> $columns */
    public function addIndexIfMissing(
        string $table,
        string $index,
        array $columns,
        bool $unique = false
    ): bool {
        $this->assertIdentifier($table);
        $this->assertIdentifier($index);
        foreach ($columns as $column) {
            $this->assertIdentifier($column);
        }
        if ($this->indexExists($table, $index, $columns, $unique)) {
            return false;
        }
        $quotedColumns = implode(', ', array_map(
            static fn (string $column): string => '`' . $column . '`',
            $columns
        ));
        $this->pdo->exec(sprintf(
            'ALTER TABLE `%s` ADD %s INDEX `%s` (%s)',
            $table,
            $unique ? 'UNIQUE' : '',
            $index,
            $quotedColumns
        ));
        $this->indexExists($table, $index, $columns, $unique);
        return true;
    }

    public function assertTableExists(string $table): void
    {
        if (!$this->tableExists($table)) {
            throw new RuntimeException('Required table is missing: ' . $table);
        }
    }

    public function assertColumnExists(string $table, string $column): void
    {
        if (!$this->columnExists($table, $column)) {
            throw new RuntimeException(sprintf('Required column is missing: %s.%s', $table, $column));
        }
    }

    private function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
            throw new InvalidArgumentException('Invalid database identifier.');
        }
    }

    private function log(string $message): void
    {
        ($this->logger)($message);
    }
}
