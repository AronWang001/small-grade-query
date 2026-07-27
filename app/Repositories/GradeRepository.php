<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

final class GradeRepository
{
    public static function projects(bool $admin = false): array
    {
        $sql = 'SELECT p.*,
                    (SELECT COUNT(*) FROM query_fields f WHERE f.project_id = p.id AND f.deleted_at IS NULL) AS field_count,
                    (SELECT COUNT(*) FROM query_records r WHERE r.project_id = p.id AND r.deleted_at IS NULL) AS record_count,
                    (SELECT COUNT(*) FROM query_logs l WHERE l.project_id = p.id) AS query_count
                FROM query_projects p
                WHERE p.deleted_at IS NULL';
        if (!$admin) {
            $sql .= " AND p.status = 'enabled'";
        }
        $sql .= ' ORDER BY p.sort_order ASC, p.id ASC';
        return db()->query($sql)->fetchAll();
    }

    public static function project(int $projectId, bool $admin = false): ?array
    {
        $sql = 'SELECT * FROM query_projects WHERE id = ? AND deleted_at IS NULL';
        if (!$admin) {
            $sql .= " AND status = 'enabled'";
        }
        $stmt = db()->prepare($sql);
        $stmt->execute([$projectId]);
        $project = $stmt->fetch();
        return is_array($project) ? $project : null;
    }

    public static function fields(int $projectId, bool $includeDeleted = false): array
    {
        $sql = 'SELECT f.*,
                    (SELECT COUNT(*) FROM query_record_values rv WHERE rv.field_id = f.id) AS value_count
                FROM query_fields f WHERE f.project_id = ?';
        if (!$includeDeleted) {
            $sql .= ' AND f.deleted_at IS NULL';
        }
        $sql .= ' ORDER BY f.sort_order ASC, f.id ASC';
        $stmt = db()->prepare($sql);
        $stmt->execute([$projectId]);
        $fields = $stmt->fetchAll();
        foreach ($fields as &$field) {
            $decoded = json_decode((string) ($field['options_json'] ?? ''), true);
            $field['options'] = is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded), static fn (string $v): bool => $v !== '')) : [];
        }
        unset($field);
        return $fields;
    }

    public static function publicSchemas(array $projects): array
    {
        $schemas = [];
        foreach ($projects as $project) {
            $conditions = [];
            foreach (self::fields((int) $project['id']) as $field) {
                if (!in_array($field['field_role'], ['condition', 'both'], true)) {
                    continue;
                }
                $conditions[] = [
                    'key' => $field['field_key'],
                    'label' => $field['field_label'],
                    'type' => $field['field_type'],
                    'required' => (bool) $field['is_required'],
                    'placeholder' => $field['placeholder'] ?? '',
                    'options' => $field['options'],
                ];
            }
            $schemas[(string) $project['id']] = $conditions;
        }
        return $schemas;
    }

    public static function validateFieldValue(array $field, string $value, bool $forQuery = false): ?string
    {
        $value = trim($value);
        if (!$forQuery && (int) $field['is_required'] === 1 && $value === '') {
            return $field['field_label'] . '为必填项。';
        }
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value, 'UTF-8') > 1000) {
            return $field['field_label'] . '内容过长。';
        }
        if ($field['field_type'] === 'number' && !is_numeric($value)) {
            return $field['field_label'] . '必须是数字。';
        }
        if ($field['field_type'] === 'date' && normalize_value($value, 'date') === '') {
            return $field['field_label'] . '必须是有效日期。';
        }
        if ($field['field_type'] === 'select' && !in_array($value, $field['options'], true)) {
            return $field['field_label'] . '选项无效。';
        }
        if (!$forQuery && !empty($field['validation_rule'])) {
            $pattern = (string) $field['validation_rule'];
            if (strlen($pattern) <= 255 && @preg_match($pattern, '') !== false && !preg_match($pattern, $value)) {
                return $field['field_label'] . '格式不正确。';
            }
        }
        return null;
    }

    public static function publicQuery(int $projectId, array $input): array
    {
        $project = self::project($projectId);
        if ($project === null) {
            return ['status' => 'not_found'];
        }
        $conditionFields = array_values(array_filter(self::fields($projectId), static fn (array $field): bool => in_array($field['field_role'], ['condition', 'both'], true)));
        if ($conditionFields === []) {
            return ['status' => 'not_found'];
        }

        $pairs = [];
        foreach ($conditionFields as $field) {
            $value = trim((string) ($input[$field['field_key']] ?? ''));
            $error = self::validateFieldValue($field, $value, true);
            if ($error !== null) {
                return ['status' => 'not_found'];
            }
            if ($value === '') {
                continue;
            }
            $pairs[] = [(int) $field['id'], value_search_hash($value, (string) $field['field_type'])];
        }
        if ($pairs === []) {
            return ['status' => 'not_found'];
        }

        $clauses = [];
        $params = [$projectId];
        foreach ($pairs as [$fieldId, $hash]) {
            $clauses[] = '(v.field_id = ? AND v.search_hash = ?)';
            $params[] = $fieldId;
            $params[] = $hash;
        }
        $sql = 'SELECT r.id FROM query_records r
                INNER JOIN query_record_values v ON v.record_id = r.id
                WHERE r.project_id = ? AND r.deleted_at IS NULL AND (' . implode(' OR ', $clauses) . ')
                GROUP BY r.id
                HAVING COUNT(DISTINCT v.field_id) = ?
                ORDER BY r.id ASC
                LIMIT 3';
        $params[] = count($pairs);
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
        if (count($ids) === 0) {
            return ['status' => 'not_found'];
        }
        if (count($ids) > 1) {
            return ['status' => 'multiple'];
        }

        $values = self::recordValues($ids[0], false);
        $results = [];
        foreach (self::fields($projectId) as $field) {
            if (!in_array($field['field_role'], ['result', 'both'], true) || (int) $field['is_visible'] !== 1) {
                continue;
            }
            $raw = $values[$field['field_key']] ?? '';
            $results[] = ['label' => $field['field_label'], 'value' => self::formatValue((string) $raw, $field)];
        }
        return ['status' => 'success', 'record_id' => $ids[0], 'project' => $project, 'results' => $results];
    }

    public static function formatValue(string $value, array $field): string
    {
        if ($value === '') {
            return '。';
        }
        if ($field['field_type'] === 'number' && is_numeric($value)) {
            $places = min(8, max(0, (int) $field['decimal_places']));
            $formatted = number_format((float) $value, $places, '.', '');
            return $places > 0 ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
        }
        return $value;
    }

    public static function recordValues(int $recordId, bool $maskSensitive = true): array
    {
        $stmt = db()->prepare('SELECT f.field_key, f.field_type, f.is_sensitive, rv.value_text, rv.value_number, rv.value_date, rv.value_encrypted
            FROM query_record_values rv INNER JOIN query_fields f ON f.id = rv.field_id WHERE rv.record_id = ? AND f.deleted_at IS NULL ORDER BY f.sort_order, f.id');
        $stmt->execute([$recordId]);
        $values = [];
        foreach ($stmt->fetchAll() as $row) {
            if ((int) $row['is_sensitive'] === 1) {
                $value = decrypt_value($row['value_encrypted']);
                $values[$row['field_key']] = $maskSensitive ? mask_sensitive($value) : $value;
            } elseif ($row['field_type'] === 'number') {
                $values[$row['field_key']] = $row['value_number'] === null ? '' : (string) $row['value_number'];
            } elseif ($row['field_type'] === 'date') {
                $values[$row['field_key']] = $row['value_date'] === null ? '' : (string) $row['value_date'];
            } else {
                $values[$row['field_key']] = (string) ($row['value_text'] ?? '');
            }
        }
        return $values;
    }

    public static function saveRecord(int $projectId, array $input, ?int $recordId = null): int
    {
        $project = self::project($projectId, true);
        if ($project === null) {
            throw new InvalidArgumentException('查询项目不存在。');
        }
        $fields = self::fields($projectId);
        if ($fields === []) {
            throw new InvalidArgumentException('该项目尚未配置字段。');
        }
        $normalizedValues = [];
        foreach ($fields as $field) {
            $value = trim((string) ($input[$field['field_key']] ?? ''));
            $error = self::validateFieldValue($field, $value);
            if ($error !== null) {
                throw new InvalidArgumentException($error);
            }
            $normalizedValues[(int) $field['id']] = ['field' => $field, 'value' => $value];
        }
        $recordHash = self::recordHash($fields, $input);

        $pdo = db();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $duplicateSql = 'SELECT id FROM query_records WHERE project_id = ? AND record_hash = ? AND deleted_at IS NULL';
            $duplicateParams = [$projectId, $recordHash];
            if ($recordId !== null) {
                $duplicateSql .= ' AND id <> ?';
                $duplicateParams[] = $recordId;
            }
            $duplicate = $pdo->prepare($duplicateSql . ' LIMIT 1 FOR UPDATE');
            $duplicate->execute($duplicateParams);
            if ($duplicate->fetchColumn() !== false) {
                throw new InvalidArgumentException('相同查询条件的记录已经存在。');
            }

            if ($recordId === null) {
                $pdo->prepare('INSERT INTO query_records (project_id, record_hash) VALUES (?, ?)')->execute([$projectId, $recordHash]);
                $recordId = (int) $pdo->lastInsertId();
            } else {
                $check = $pdo->prepare('SELECT id FROM query_records WHERE id = ? AND project_id = ? AND deleted_at IS NULL FOR UPDATE');
                $check->execute([$recordId, $projectId]);
                if ($check->fetchColumn() === false) {
                    throw new InvalidArgumentException('记录不存在。');
                }
                $pdo->prepare('UPDATE query_records SET record_hash = ?, updated_at = NOW() WHERE id = ?')->execute([$recordHash, $recordId]);
                $pdo->prepare('DELETE FROM query_record_values WHERE record_id = ?')->execute([$recordId]);
            }

            $insert = $pdo->prepare('INSERT INTO query_record_values (record_id, field_id, value_text, value_number, value_date, value_encrypted, search_hash) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($normalizedValues as $fieldId => $item) {
                $field = $item['field'];
                $value = $item['value'];
                $text = null;
                $number = null;
                $date = null;
                $encrypted = null;
                if ((int) $field['is_sensitive'] === 1) {
                    $encrypted = encrypt_value($value);
                } elseif ($field['field_type'] === 'number' && $value !== '') {
                    $number = normalize_value($value, 'number');
                } elseif ($field['field_type'] === 'date' && $value !== '') {
                    $date = normalize_value($value, 'date');
                } else {
                    $text = $value;
                }
                $insert->execute([$recordId, $fieldId, $text, $number, $date, $encrypted, $value === '' ? null : value_search_hash($value, (string) $field['field_type'])]);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $recordId;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function recordHash(array $fields, array $input): string
    {
        $conditionParts = [];
        foreach ($fields as $field) {
            if (!in_array($field['field_role'], ['condition', 'both'], true)) {
                continue;
            }
            $value = trim((string) ($input[$field['field_key']] ?? ''));
            $conditionParts[] = $field['field_key'] . ':' . value_search_hash($value, (string) $field['field_type']);
        }
        if ($conditionParts === []) {
            throw new InvalidArgumentException('项目必须至少配置一个查询条件字段。');
        }
        sort($conditionParts);
        return hash_hmac('sha256', implode('|', $conditionParts), search_key());
    }

    public static function deleteRecords(int $projectId, array $recordIds): int
    {
        $recordIds = array_values(array_unique(array_filter(array_map('intval', $recordIds), static fn (int $id): bool => $id > 0)));
        if ($recordIds === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
        $params = array_merge([$projectId], $recordIds);
        $pdo = db();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare("UPDATE query_records SET deleted_at = NOW() WHERE project_id = ? AND deleted_at IS NULL AND id IN ($placeholders)");
            $stmt->execute($params);
            $count = $stmt->rowCount();
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $count;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function listRecords(int $projectId, string $search, int $page, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $where = 'r.project_id = ? AND r.deleted_at IS NULL';
        $params = [$projectId];
        if ($search !== '') {
            $fields = self::fields($projectId);
            $searchClauses = [];
            foreach ($fields as $field) {
                if ((int) $field['is_sensitive'] === 1) {
                    $searchClauses[] = '(rv.field_id = ? AND rv.search_hash = ?)';
                    $params[] = (int) $field['id'];
                    $params[] = value_search_hash($search, (string) $field['field_type']);
                } elseif ($field['field_type'] === 'text' || $field['field_type'] === 'select') {
                    $searchClauses[] = '(rv.field_id = ? AND rv.value_text LIKE ?)';
                    $params[] = (int) $field['id'];
                    $params[] = '%' . $search . '%';
                }
            }
            if ($searchClauses !== []) {
                $where .= ' AND EXISTS (SELECT 1 FROM query_record_values rv WHERE rv.record_id = r.id AND (' . implode(' OR ', $searchClauses) . '))';
            }
        }
        $count = db()->prepare("SELECT COUNT(*) FROM query_records r WHERE $where");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $sql = "SELECT r.id, r.created_at, r.updated_at,
                    (SELECT COUNT(*) FROM query_logs ql WHERE ql.record_id = r.id AND ql.is_success = 1) AS query_count,
                    (SELECT MAX(created_at) FROM query_logs ql WHERE ql.record_id = r.id AND ql.is_success = 1) AS last_query_at
                FROM query_records r WHERE $where ORDER BY r.id DESC LIMIT $perPage OFFSET $offset";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $records = $stmt->fetchAll();
        foreach ($records as &$record) {
            $record['values'] = self::recordValues((int) $record['id'], true);
        }
        unset($record);
        return ['records' => $records, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    public static function record(int $projectId, int $recordId, bool $maskSensitive = false): ?array
    {
        $stmt = db()->prepare('SELECT * FROM query_records WHERE id = ? AND project_id = ? AND deleted_at IS NULL');
        $stmt->execute([$recordId, $projectId]);
        $record = $stmt->fetch();
        if (!is_array($record)) {
            return null;
        }
        $record['values'] = self::recordValues($recordId, $maskSensitive);
        return $record;
    }

    public static function queryLogs(array $filters, int $page, int $perPage = 30): array
    {
        $page = max(1, $page);
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['project_id'])) {
            $where[] = 'l.project_id = ?';
            $params[] = (int) $filters['project_id'];
        }
        if (!empty($filters['record_id'])) {
            $where[] = 'l.record_id = ?';
            $params[] = (int) $filters['record_id'];
        }
        if (!empty($filters['record_search'])) {
            $recordSearch = trim((string) $filters['record_search']);
            $clauses = ['EXISTS (SELECT 1 FROM query_record_values qrv INNER JOIN query_fields qf ON qf.id = qrv.field_id WHERE qrv.record_id = l.record_id AND qf.deleted_at IS NULL AND qf.is_sensitive = 0 AND qrv.value_text LIKE ?)'];
            $searchParams = ['%' . $recordSearch . '%'];
            if (ctype_digit($recordSearch)) {
                $clauses[] = 'l.record_id = ?';
                $searchParams[] = (int) $recordSearch;
            }
            $where[] = '(' . implode(' OR ', $clauses) . ')';
            array_push($params, ...$searchParams);
        }
        if (($filters['success'] ?? '') !== '') {
            $where[] = 'l.is_success = ?';
            $params[] = (int) $filters['success'];
        }
        if (!empty($filters['device_type'])) {
            $where[] = 'l.device_type = ?';
            $params[] = (string) $filters['device_type'];
        }
        if (!empty($filters['start_date'])) {
            $where[] = 'l.created_at >= ?';
            $params[] = (string) $filters['start_date'] . ' 00:00:00';
        }
        if (!empty($filters['end_date'])) {
            $where[] = 'l.created_at <= ?';
            $params[] = (string) $filters['end_date'] . ' 23:59:59';
        }
        if (!empty($filters['ip'])) {
            $where[] = 'l.ip_hash = ?';
            $params[] = hash_hmac('sha256', trim((string) $filters['ip']), search_key());
        }
        $whereSql = implode(' AND ', $where);
        $count = db()->prepare("SELECT COUNT(*) FROM query_logs l WHERE $whereSql");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT l.*, p.name AS project_name FROM query_logs l LEFT JOIN query_projects p ON p.id = l.project_id WHERE $whereSql ORDER BY l.id DESC LIMIT $perPage OFFSET $offset";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll();
        foreach ($logs as &$log) {
            try {
                $log['ip_plain'] = decrypt_value((string) $log['ip_address']);
            } catch (Throwable) {
                $log['ip_plain'] = '无法解密';
            }
        }
        unset($log);
        return ['logs' => $logs, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $perPage))];
    }
}
