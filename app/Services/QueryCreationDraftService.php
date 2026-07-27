<?php

declare(strict_types=1);

final class QueryCreationDraftService
{
    private const DRAFT_TTL_HOURS = 24;
    private const STORAGE_SUBDIR = 'query-drafts';
    private const TOKEN_PATTERN = '/^[a-f0-9]{64}$/';

    /** @return array{id:int,token:string,current_step:int,expires_at:string} */
    public static function create(
        int $adminId,
        string $originalFilename = '',
        string $suggestedName = ''
    ): array {
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + self::DRAFT_TTL_HOURS * 3600);

        $stmt = db()->prepare(
            'INSERT INTO query_creation_drafts
                (token, admin_id, current_step, original_filename, suggested_name, expires_at)
             VALUES (?, ?, 1, ?, ?, ?)'
        );
        $stmt->execute([
            $token,
            $adminId,
            mb_substr(basename($originalFilename), 0, 255, 'UTF-8'),
            mb_substr($suggestedName, 0, 150, 'UTF-8'),
            $expiresAt,
        ]);
        $id = (int) db()->lastInsertId();

        audit_log('query_creation_started', 'query_creation_draft', $id, true, [
            'suggested_name' => mb_substr($suggestedName, 0, 80, 'UTF-8'),
        ]);

        return [
            'id' => $id,
            'token' => $token,
            'current_step' => 1,
            'expires_at' => $expiresAt,
        ];
    }

    public static function validateToken(string $token): string
    {
        $token = strtolower(trim($token));
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new InvalidArgumentException('草稿令牌无效。');
        }
        return $token;
    }

    public static function findByToken(string $token, int $adminId): ?array
    {
        $draft = self::findOwnedByToken($token, $adminId, false);
        if ($draft === null || strtotime((string) $draft['expires_at']) < time()) {
            return null;
        }
        return $draft;
    }

    public static function findOwnedByToken(
        string $token,
        int $adminId,
        bool $includePublished = true
    ): ?array {
        try {
            $token = self::validateToken($token);
        } catch (InvalidArgumentException) {
            return null;
        }
        $sql = 'SELECT * FROM query_creation_drafts WHERE token = ? AND admin_id = ?';
        if (!$includePublished) {
            $sql .= ' AND published_at IS NULL';
        }
        $stmt = db()->prepare($sql);
        $stmt->execute([$token, $adminId]);
        $draft = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($draft) ? $draft : null;
    }

    /** @return list<array<string,mixed>> */
    public static function listUnpublished(int $adminId): array
    {
        $stmt = db()->prepare(
            'SELECT id, token, current_step, original_filename, suggested_name, expires_at, created_at
             FROM query_creation_drafts
             WHERE admin_id = ? AND published_at IS NULL AND expires_at > NOW()
             ORDER BY updated_at DESC'
        );
        $stmt->execute([$adminId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function updateStep(int $draftId, int $step): void
    {
        if ($step < 1 || $step > 5) {
            throw new InvalidArgumentException('步骤编号必须在1至5之间。');
        }
        $stmt = db()->prepare(
            'UPDATE query_creation_drafts
             SET current_step = ?, updated_at = NOW()
             WHERE id = ? AND published_at IS NULL'
        );
        $stmt->execute([$step, $draftId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('草稿状态已变化，请刷新后重试。');
        }
    }

    public static function updateStepData(int $draftId, array $data): void
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new RuntimeException('无法保存草稿配置。');
        }
        $stmt = db()->prepare(
            'UPDATE query_creation_drafts
             SET step_data_json = ?, updated_at = NOW()
             WHERE id = ? AND published_at IS NULL'
        );
        $stmt->execute([$encoded, $draftId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('草稿状态已变化，请刷新后重试。');
        }
    }

    public static function getStepData(int $draftId): array
    {
        $stmt = db()->prepare('SELECT step_data_json FROM query_creation_drafts WHERE id = ?');
        $stmt->execute([$draftId]);
        $json = $stmt->fetchColumn();
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function buildDraftPath(string $token): string
    {
        return self::storageDirectory(true) . DIRECTORY_SEPARATOR
            . 'draft_' . self::validateToken($token) . '.dat';
    }

    public static function buildSourcePath(string $token, string $extension): string
    {
        $extension = strtolower($extension);
        if (!in_array($extension, ['xlsx', 'xls'], true)) {
            throw new InvalidArgumentException('草稿源文件扩展名无效。');
        }
        return self::storageDirectory(true) . DIRECTORY_SEPARATOR
            . 'source_' . self::validateToken($token) . '.' . $extension;
    }

    public static function storeSourceFile(
        string $token,
        string $sourcePath,
        string $extension
    ): string {
        if (!is_file($sourcePath) || is_link($sourcePath)) {
            throw new InvalidArgumentException('上传临时文件无效。');
        }
        $destination = self::buildSourcePath($token, $extension);
        if (file_exists($destination) || is_link($destination)) {
            throw new RuntimeException('草稿源文件已存在，请重新上传。');
        }
        if (!copy($sourcePath, $destination)) {
            throw new RuntimeException('无法保存上传文件。');
        }
        @chmod($destination, 0660);
        return $destination;
    }

    public static function savePreviewFile(int $draftId, string $token, array $payload): string
    {
        $token = self::validateToken($token);
        $path = self::buildDraftPath($token);
        $temporary = self::storageDirectory(true) . DIRECTORY_SEPARATOR . 'temp_' . $token . '.dat';

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new RuntimeException('无法编码草稿预览数据。');
        }
        if (file_put_contents($temporary, encrypt_value($encoded), LOCK_EX) === false) {
            throw new RuntimeException('无法保存草稿预览数据。');
        }
        @chmod($temporary, 0660);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('无法完成草稿预览数据写入。');
        }

        $filename = basename($path);
        $stmt = db()->prepare(
            'UPDATE query_creation_drafts
             SET preview_path = ?, updated_at = NOW()
             WHERE id = ? AND token = ? AND published_at IS NULL'
        );
        $stmt->execute([$filename, $draftId, $token]);
        if ($stmt->rowCount() !== 1) {
            self::deleteDraftFiles($token);
            throw new RuntimeException('草稿状态已变化，请重新上传。');
        }
        return $filename;
    }

    public static function readPreviewFile(string $filename): ?array
    {
        if (preg_match('/^draft_([a-f0-9]{64})\.dat$/', basename($filename), $matches) !== 1) {
            return null;
        }
        $path = self::buildDraftPath($matches[1]);
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        $real = realpath($path);
        if ($real === false || !hash_equals(self::storageDirectory(false), dirname($real))) {
            return null;
        }
        $encrypted = file_get_contents($real);
        if (!is_string($encrypted)) {
            return null;
        }
        try {
            $payload = json_decode(decrypt_value($encrypted), true);
        } catch (Throwable) {
            return null;
        }
        return is_array($payload) ? $payload : null;
    }

    public static function delete(int $draftId): void
    {
        $pdo = db();
        if ($pdo->inTransaction()) {
            throw new RuntimeException('草稿删除不能嵌套在其他事务中。');
        }
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT token FROM query_creation_drafts
                 WHERE id = ? AND published_at IS NULL
                 FOR UPDATE'
            );
            $stmt->execute([$draftId]);
            $token = $stmt->fetchColumn();
            if (!is_string($token)) {
                throw new RuntimeException('草稿不存在或已经发布。');
            }
            $token = self::validateToken($token);

            $delete = $pdo->prepare(
                'DELETE FROM query_creation_drafts
                 WHERE id = ? AND token = ? AND published_at IS NULL'
            );
            $delete->execute([$draftId, $token]);
            if ($delete->rowCount() !== 1) {
                throw new RuntimeException('草稿状态已变化，请刷新后重试。');
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        self::deleteDraftFiles($token);
        audit_log('query_creation_draft_deleted', 'query_creation_draft', $draftId);
    }

    public static function markPublished(
        PDO $pdo,
        int $draftId,
        int $adminId,
        int $projectId
    ): void {
        $stmt = $pdo->prepare(
            'UPDATE query_creation_drafts
             SET published_project_id = ?, published_at = NOW(), updated_at = NOW()
             WHERE id = ? AND admin_id = ?
               AND published_at IS NULL AND published_project_id IS NULL'
        );
        $stmt->execute([$projectId, $draftId, $adminId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('该查询已经发布，请勿重复提交。');
        }
    }

    public static function isPublished(int $draftId): bool
    {
        $stmt = db()->prepare(
            'SELECT published_at, published_project_id
             FROM query_creation_drafts WHERE id = ?'
        );
        $stmt->execute([$draftId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)
            && (!empty($row['published_at']) || !empty($row['published_project_id']));
    }

    /**
     * Delete only files derived from the validated token.
     *
     * @return array{deleted:int,failed:int}
     */
    public static function deleteDraftFiles(string $token): array
    {
        $token = self::validateToken($token);
        $directory = self::storageDirectory(false);
        if ($directory === '') {
            return ['deleted' => 0, 'failed' => 0];
        }

        $names = [
            'draft_' . $token . '.dat',
            'temp_' . $token . '.dat',
            'source_' . $token . '.xlsx',
            'source_' . $token . '.xls',
        ];
        $deleted = 0;
        $failed = 0;
        foreach ($names as $name) {
            try {
                if (self::deleteSafeFile($directory, $name)) {
                    $deleted++;
                }
            } catch (Throwable $exception) {
                $failed++;
                error_log(sprintf(
                    'Query draft cleanup failed (%s, %s).',
                    $name,
                    get_class($exception)
                ));
            }
        }
        if ($failed > 0) {
            throw new RuntimeException('部分草稿临时文件未能清理。');
        }
        return ['deleted' => $deleted, 'failed' => $failed];
    }

    public static function deleteSourceFiles(string $token): int
    {
        $token = self::validateToken($token);
        $directory = self::storageDirectory(false);
        if ($directory === '') {
            return 0;
        }
        $deleted = 0;
        foreach (['source_' . $token . '.xlsx', 'source_' . $token . '.xls'] as $name) {
            if (self::deleteSafeFile($directory, $name)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    public static function storageDirectoryPath(): string
    {
        return self::storageDirectory(true);
    }

    private static function storageDirectory(bool $create): string
    {
        $configured = APP_ROOT . '/storage/' . self::STORAGE_SUBDIR;
        if ($create && !is_dir($configured)) {
            if (!mkdir($configured, 0770, true) && !is_dir($configured)) {
                throw new RuntimeException('无法创建草稿存储目录。');
            }
        }
        $real = realpath($configured);
        if ($real === false) {
            return $create
                ? throw new RuntimeException('无法解析草稿存储目录。')
                : '';
        }
        $storageRoot = realpath(APP_ROOT . '/storage');
        if ($storageRoot === false || !hash_equals($storageRoot, dirname($real))) {
            throw new RuntimeException('草稿存储目录不在允许范围内。');
        }
        return $real;
    }

    private static function deleteSafeFile(string $directory, string $name): bool
    {
        if (basename($name) !== $name) {
            throw new RuntimeException('草稿临时文件名无效。');
        }
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (!file_exists($path) && !is_link($path)) {
            return false;
        }
        if (is_link($path) || is_dir($path)) {
            throw new RuntimeException('拒绝删除符号链接或目录。');
        }
        $real = realpath($path);
        if ($real === false || !hash_equals($directory, dirname($real))) {
            throw new RuntimeException('草稿临时文件路径越界。');
        }
        if (!unlink($real)) {
            throw new RuntimeException('草稿临时文件删除失败。');
        }
        return true;
    }
}
