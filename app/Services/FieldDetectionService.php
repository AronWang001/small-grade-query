<?php

declare(strict_types=1);

require_once __DIR__ . '/../Repositories/GradeRepository.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class FieldDetectionService
{
    public const MAX_ROWS = 5000;
    public const MAX_COLUMNS = 100;
    public const MAX_SHEETS = 10;

    private const FIELD_MAP = [
        '姓名' => 'name', 'name' => 'name',
        '身份证号' => 'id_card', '身份证号码' => 'id_card', '证件号码' => 'id_card',
        'id card' => 'id_card', 'id_card' => 'id_card',
        '学号' => 'student_number', 'student number' => 'student_number', 'student_number' => 'student_number',
        '班级' => 'class_name', 'class name' => 'class_name', 'class_name' => 'class_name',
        '手机号' => 'phone', '手机号码' => 'phone', 'phone' => 'phone',
        '邮箱' => 'email', '电子邮箱' => 'email', 'email' => 'email',
        '总学分' => 'total_credits', 'total credits' => 'total_credits',
        '总成绩' => 'total_score', 'total score' => 'total_score',
        '平均成绩' => 'average_score', 'average score' => 'average_score',
        '加权平均分' => 'weighted_average', 'weighted average' => 'weighted_average',
        '总学分绩点' => 'total_gpa', 'total gpa' => 'total_gpa',
        '平均学分绩点' => 'average_gpa', 'average gpa' => 'average_gpa',
        '专业排名' => 'major_rank', 'major rank' => 'major_rank',
        '排名' => 'rank', 'rank' => 'rank',
    ];

    public static function inspectWorkbook(Spreadsheet $spreadsheet, array $project, array $existingFields): array
    {
        if ($spreadsheet->getSheetCount() > self::MAX_SHEETS) {
            throw new InvalidArgumentException('Excel工作表数量不能超过10个。');
        }
        $sheet = $spreadsheet->getSheet(0);
        $highestRow = $sheet->getHighestDataRow();
        $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        if ($highestColumnIndex > self::MAX_COLUMNS) {
            throw new InvalidArgumentException('Excel列数不能超过100列。');
        }
        if ($highestRow < 1 || $highestRow - 1 > self::MAX_ROWS) {
            throw new InvalidArgumentException('Excel有效数据行数必须在0到5000行之间。');
        }

        $lastRelevantColumn = 0;
        $headers = [];
        $columnHasData = [];
        for ($columnIndex = 1; $columnIndex <= $highestColumnIndex; $columnIndex++) {
            $header = trim((string) ($sheet->getCell([$columnIndex, 1])->getValue() ?? ''));
            $headers[$columnIndex] = $header;
            $hasData = false;
            for ($row = 2; $row <= $highestRow; $row++) {
                $raw = $sheet->getCell([$columnIndex, $row])->getValue();
                if ($raw !== null && trim((string) $raw) !== '') {
                    $hasData = true;
                    break;
                }
            }
            $columnHasData[$columnIndex] = $hasData;
            if ($header !== '' || $hasData) {
                $lastRelevantColumn = $columnIndex;
            }
        }
        if ($lastRelevantColumn === 0) {
            throw new InvalidArgumentException('Excel没有任何有效表头。');
        }

        $seenHeaders = [];
        for ($columnIndex = 1; $columnIndex <= $lastRelevantColumn; $columnIndex++) {
            $header = $headers[$columnIndex];
            $letter = Coordinate::stringFromColumnIndex($columnIndex);
            if ($header === '') {
                if ($columnHasData[$columnIndex]) {
                    throw new InvalidArgumentException('第' . $letter . '列表头为空，但该列存在数据。');
                }
                throw new InvalidArgumentException('第' . $letter . '列表头为空，请填写字段名称。');
            }
            if (mb_strlen($header, 'UTF-8') > 100) {
                throw new InvalidArgumentException('第' . $letter . '列表头超过100个字符。');
            }
            $normalizedHeader = self::normalizeHeader($header);
            if (isset($seenHeaders[$normalizedHeader])) {
                throw new InvalidArgumentException('Excel表头存在重复字段“' . $header . '”，请修改后重新上传。');
            }
            $seenHeaders[$normalizedHeader] = $columnIndex;
        }

        $rawRows = [];
        $samples = array_fill(1, $lastRelevantColumn, []);
        $precisionErrors = [];
        for ($row = 2; $row <= $highestRow; $row++) {
            $cells = [];
            $rowEmpty = true;
            for ($columnIndex = 1; $columnIndex <= $lastRelevantColumn; $columnIndex++) {
                $cell = $sheet->getCell([$columnIndex, $row]);
                $raw = $cell->getValue();
                $formula = $cell->getDataType() === DataType::TYPE_FORMULA || (is_string($raw) && str_starts_with($raw, '='));
                if ($formula) {
                    $value = '';
                } elseif ($raw !== null && is_numeric($raw) && ExcelDate::isDateTime($cell)) {
                    $value = ExcelDate::excelToDateTimeObject((float) $raw)->format('Y-m-d');
                } else {
                    $value = trim((string) ($raw ?? ''));
                }
                if ($value !== '' || $formula) {
                    $rowEmpty = false;
                }
                if ($value !== '' && count($samples[$columnIndex]) < 50) {
                    $samples[$columnIndex][] = $value;
                }
                if ($value !== '' && self::identifierLike($headers[$columnIndex]) && $cell->getDataType() !== DataType::TYPE_STRING) {
                    $digits = preg_replace('/\D+/', '', $value) ?? '';
                    if (strlen($digits) > 15) {
                        $precisionErrors[] = '第' . $row . '行' . $headers[$columnIndex] . '可能已被Excel转换为数字并丢失精度，请将该列设置为文本后重新上传。';
                    }
                }
                $cells[(string) $columnIndex] = ['value' => $value, 'formula' => $formula];
            }
            if (!$rowEmpty) {
                $rawRows[] = ['source_row' => $row, 'cells' => $cells];
            }
        }

        if ($precisionErrors !== []) {
            throw new InvalidArgumentException(implode('；', array_values(array_unique($precisionErrors))));
        }

        $templateSchema = self::readTemplateSchema($spreadsheet, $project, $existingFields);
        $columns = self::matchExistingFields($headers, $samples, $existingFields, $templateSchema['column_map']);
        $existingIdsMapped = array_values(array_filter(array_map(
            static fn (array $column): int => (int) ($column['existing_field_id'] ?? 0),
            $columns
        )));
        $missingFields = array_values(array_filter($existingFields, static fn (array $field): bool => !in_array((int) $field['id'], $existingIdsMapped, true)));
        $newFields = array_values(array_filter($columns, static fn (array $column): bool => (int) ($column['existing_field_id'] ?? 0) === 0));

        $warnings = $templateSchema['warning'] !== '' ? [$templateSchema['warning']] : [];
        if ($newFields !== [] && $existingFields !== []) {
            $warnings[] = '检测到' . count($newFields) . '个新字段：' . implode('、', array_column($newFields, 'field_label')) . '。确认后将在正式导入时创建。';
        }
        if ($missingFields !== []) {
            $warnings[] = '本次Excel缺少' . count($missingFields) . '个已有字段：' . implode('、', array_column($missingFields, 'field_label')) . '。这些字段不会被删除。';
        }
        return [
            'headers' => array_values(array_slice($headers, 0, $lastRelevantColumn, true)),
            'columns' => $columns,
            'raw_rows' => $rawRows,
            'missing_fields' => array_map(static fn (array $field): array => [
                'id' => (int) $field['id'], 'field_key' => (string) $field['field_key'],
                'field_label' => (string) $field['field_label'], 'is_required' => (int) $field['is_required'],
                'field_role' => (string) $field['field_role'],
            ], $missingFields),
            'warnings' => $warnings,
            'schema_hash' => self::projectSchemaHash($project, $existingFields),
        ];
    }

    public static function matchExistingFields(array $headers, array $samples, array $existingFields, array $templateColumnMap = []): array
    {
        $byId = [];
        $byKey = [];
        $byLabel = [];
        $byNormalizedLabel = [];
        foreach ($existingFields as $field) {
            $byId[(int) $field['id']] = $field;
            $byKey[(string) $field['field_key']] = $field;
            $byLabel[(string) $field['field_label']] = $field;
            $byNormalizedLabel[self::normalizeHeader((string) $field['field_label'])] = $field;
        }
        $usedKeys = array_fill_keys(array_keys($byKey), true);
        $usedFieldIds = [];
        $columns = [];
        foreach ($headers as $columnIndex => $header) {
            $header = (string) $header;
            $candidateKey = self::baseFieldKey($header);
            $matched = null;
            $templateId = (int) ($templateColumnMap[(int) $columnIndex] ?? 0);
            if ($templateId > 0 && isset($byId[$templateId])) {
                $templateField = $byId[$templateId];
                if (self::normalizeHeader($header) === self::normalizeHeader((string) $templateField['field_label'])
                    || $candidateKey === (string) $templateField['field_key']) {
                    $matched = $templateField;
                }
            }
            $matched ??= $byKey[$candidateKey] ?? null;
            $matched ??= $byLabel[$header] ?? null;
            $matched ??= $byNormalizedLabel[self::normalizeHeader($header)] ?? null;
            if (is_array($matched) && isset($usedFieldIds[(int) $matched['id']])) {
                $matched = null;
            }

            if (is_array($matched)) {
                $usedFieldIds[(int) $matched['id']] = true;
                $columns[] = self::columnFromExisting((int) $columnIndex, $header, $matched);
                continue;
            }

            $fieldKey = self::generateFieldKey($header, $usedKeys);
            $usedKeys[$fieldKey] = true;
            $type = self::inferFieldType($header, $samples[$columnIndex] ?? []);
            $defaults = self::defaultFieldSettings($header, $fieldKey, $type);
            $columns[] = array_merge([
                'column_index' => (int) $columnIndex,
                'column_letter' => Coordinate::stringFromColumnIndex((int) $columnIndex),
                'excel_header' => $header,
                'field_label' => $header,
                'field_key' => $fieldKey,
                'field_type' => $type,
                'existing_field_id' => 0,
                'mapping' => 'new',
                'decimal_places' => $type === 'number' ? 2 : 0,
                'sort_order' => ((int) $columnIndex) * 10,
            ], $defaults);
        }

        $hasCondition = array_filter($columns, static fn (array $column): bool => in_array($column['field_role'], ['condition', 'both'], true));
        if ($hasCondition === [] && $columns !== []) {
            $columns[0]['field_role'] = 'both';
            $columns[0]['is_required'] = 1;
        }
        return $columns;
    }

    public static function generateFieldKey(string $header, array $usedKeys = []): string
    {
        $base = self::baseFieldKey($header);
        if ($base === '') {
            $base = 'field_' . substr(hash('sha256', self::normalizeHeader($header)), 0, 8);
        }
        if (!preg_match('/^[a-z]/', $base)) {
            $base = 'field_' . $base;
        }
        $base = substr($base, 0, 60);
        $candidate = $base;
        $suffix = 2;
        while (isset($usedKeys[$candidate])) {
            $ending = '_' . $suffix++;
            $candidate = substr($base, 0, 64 - strlen($ending)) . $ending;
        }
        return $candidate;
    }

    public static function inferFieldType(string $header, array $samples): string
    {
        if (self::identifierLike($header)) {
            return 'text';
        }
        foreach ($samples as $sample) {
            $sample = trim((string) $sample);
            if ((preg_match('/^0\d+$/', $sample) && strlen($sample) > 1) || preg_match('/^\d{16,}$/', $sample)) {
                return 'text';
            }
        }
        $dateName = preg_match('/(?:日期|时间|date|birthday|出生)/iu', $header) === 1;
        $allDates = $samples !== [];
        foreach ($samples as $sample) {
            $normalized = normalize_value((string) $sample, 'date');
            if ($normalized === '') {
                $allDates = false;
                break;
            }
        }
        if ($dateName || $allDates) {
            return 'date';
        }
        if ($samples !== [] && array_reduce($samples, static fn (bool $carry, mixed $value): bool => $carry && is_numeric((string) $value), true)) {
            return 'number';
        }
        return 'text';
    }

    public static function inferSensitive(string $header): bool
    {
        return preg_match('/(?:身份证|证件|护照|手机号|手机号码|电话|邮箱|email|phone|地址|住址|passport)/iu', $header) === 1;
    }

    public static function projectSchemaHash(array $project, array $fields): string
    {
        $schema = [
            'project_id' => (int) ($project['id'] ?? 0),
            'project_slug' => (string) ($project['slug'] ?? ''),
            'fields' => array_map(static fn (array $field): array => [
                'id' => (int) ($field['id'] ?? 0),
                'field_key' => (string) ($field['field_key'] ?? ''),
                'field_label' => (string) ($field['field_label'] ?? ''),
                'field_type' => (string) ($field['field_type'] ?? ''),
                'field_role' => (string) ($field['field_role'] ?? ''),
                'is_required' => (int) ($field['is_required'] ?? 0),
                'is_sensitive' => (int) ($field['is_sensitive'] ?? 0),
                'is_visible' => (int) ($field['is_visible'] ?? 0),
                'decimal_places' => (int) ($field['decimal_places'] ?? 0),
                'sort_order' => (int) ($field['sort_order'] ?? 0),
            ], $fields),
        ];
        return hash_hmac('sha256', json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', search_key());
    }

    private static function readTemplateSchema(Spreadsheet $spreadsheet, array $project, array $existingFields): array
    {
        $sheet = $spreadsheet->getSheetByName('_system_schema');
        if (!$sheet instanceof Worksheet || $existingFields === []) {
            return ['column_map' => [], 'warning' => ''];
        }
        try {
            $expectedHash = self::projectSchemaHash($project, $existingFields);
            $known = [];
            foreach ($existingFields as $field) {
                $known[(int) $field['id']] = (string) $field['field_key'];
            }
            $columnMap = [];
            for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
                $projectId = (int) $sheet->getCell('B' . $row)->getValue();
                $slug = (string) $sheet->getCell('C' . $row)->getValue();
                $fieldId = (int) $sheet->getCell('D' . $row)->getValue();
                $fieldKey = (string) $sheet->getCell('E' . $row)->getValue();
                $columnIndex = (int) $sheet->getCell('N' . $row)->getValue();
                $hash = (string) $sheet->getCell('O' . $row)->getValue();
                if ($projectId !== (int) $project['id'] || !hash_equals((string) $project['slug'], $slug)
                    || !isset($known[$fieldId]) || !hash_equals($known[$fieldId], $fieldKey)
                    || $columnIndex < 1 || !hash_equals($expectedHash, $hash)) {
                    throw new RuntimeException('invalid schema');
                }
                $columnMap[$columnIndex] = $fieldId;
            }
            if (count($columnMap) !== count($existingFields)) {
                throw new RuntimeException('incomplete schema');
            }
            return ['column_map' => $columnMap, 'warning' => ''];
        } catch (Throwable) {
            return ['column_map' => [], 'warning' => '模板隐藏字段配置未通过校验，系统已改用字段标识和字段名称匹配。'];
        }
    }

    private static function columnFromExisting(int $columnIndex, string $header, array $field): array
    {
        return [
            'column_index' => $columnIndex,
            'column_letter' => Coordinate::stringFromColumnIndex($columnIndex),
            'excel_header' => $header,
            'field_label' => (string) $field['field_label'],
            'field_key' => (string) $field['field_key'],
            'field_type' => (string) $field['field_type'],
            'field_role' => (string) $field['field_role'],
            'is_required' => (int) $field['is_required'],
            'is_sensitive' => (int) $field['is_sensitive'],
            'is_visible' => (int) $field['is_visible'],
            'decimal_places' => (int) $field['decimal_places'],
            'sort_order' => (int) $field['sort_order'],
            'existing_field_id' => (int) $field['id'],
            'mapping' => 'existing:' . (int) $field['id'],
        ];
    }

    private static function defaultFieldSettings(string $header, string $fieldKey, string $type): array
    {
        $sensitive = self::inferSensitive($header);
        if ($fieldKey === 'name') {
            return ['field_role' => 'both', 'is_required' => 1, 'is_sensitive' => 0, 'is_visible' => 1];
        }
        if ($fieldKey === 'id_card') {
            return ['field_role' => 'condition', 'is_required' => 1, 'is_sensitive' => 1, 'is_visible' => 0];
        }
        if ($fieldKey === 'student_number') {
            return ['field_role' => 'both', 'is_required' => 1, 'is_sensitive' => 0, 'is_visible' => 1];
        }
        if (in_array($fieldKey, ['phone', 'email'], true)) {
            return ['field_role' => 'condition', 'is_required' => 1, 'is_sensitive' => 1, 'is_visible' => 0];
        }
        $resultLike = $type === 'number' || preg_match('/(?:成绩|分数|绩点|排名|score|gpa|rank|学分)/iu', $header) === 1;
        return [
            'field_role' => $resultLike ? 'result' : 'result',
            'is_required' => 0,
            'is_sensitive' => $sensitive ? 1 : 0,
            'is_visible' => $sensitive ? 0 : 1,
        ];
    }

    private static function baseFieldKey(string $header): string
    {
        $normalized = self::normalizeHeader($header);
        if (isset(self::FIELD_MAP[$normalized])) {
            return self::FIELD_MAP[$normalized];
        }
        if (isset(self::FIELD_MAP[$header])) {
            return self::FIELD_MAP[$header];
        }
        if (preg_match('/^[\x20-\x7E]+$/', $header) === 1) {
            $key = mb_strtolower($header, 'UTF-8');
            $key = preg_replace('/[\s-]+/', '_', $key) ?? '';
            $key = preg_replace('/[^a-z0-9_]+/', '', $key) ?? '';
            $key = preg_replace('/_+/', '_', $key) ?? '';
            return trim($key, '_');
        }
        return '';
    }

    private static function normalizeHeader(string $header): string
    {
        $header = mb_strtolower(trim($header), 'UTF-8');
        $header = preg_replace('/[\s_-]+/u', ' ', $header) ?? $header;
        return trim($header);
    }

    private static function identifierLike(string $header): bool
    {
        return preg_match('/(?:身份证|证件|护照|手机号|手机号码|电话|学号|准考证|编号|卡号|id\s*card|student\s*number|phone|passport)/iu', $header) === 1;
    }
}
