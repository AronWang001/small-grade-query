<?php

declare(strict_types=1);

require_once __DIR__ . '/FieldDetectionService.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class ExcelTemplateService
{
    public static function createWorkbook(int $projectId): Spreadsheet
    {
        $project = GradeRepository::project($projectId, true);
        if ($project === null) {
            throw new InvalidArgumentException('查询项目不存在。');
        }
        $fields = GradeRepository::fields($projectId);
        return $fields === []
            ? self::starterWorkbook($project)
            : self::projectWorkbook($project, $fields);
    }

    public static function outputTemplate(int $projectId): never
    {
        $project = GradeRepository::project($projectId, true);
        if ($project === null) {
            throw new InvalidArgumentException('查询项目不存在。');
        }
        $fields = GradeRepository::fields($projectId);
        $spreadsheet = $fields === [] ? self::starterWorkbook($project) : self::projectWorkbook($project, $fields);
        $suffix = $fields === [] ? '_Excel填写示例.xlsx' : '_当前项目模板.xlsx';
        $filename = preg_replace('/[^\p{L}\p{N}_-]+/u', '_', (string) $project['name']) . $suffix;
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($filename));
        header('Cache-Control: no-store');
        (new Xlsx($spreadsheet))->save('php://output');
        $spreadsheet->disconnectWorksheets();
        exit;
    }

    private static function starterWorkbook(array $project): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('数据导入');
        $headers = ['姓名', '身份证号', '班级', '成绩'];
        $example = ['示例（请删除本行）', '11010119900101XXXX', '示例班级', '95.50'];
        foreach ($headers as $index => $header) {
            $column = self::columnName($index + 1);
            $sheet->setCellValueExplicit($column . '1', $header, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit($column . '2', $example[$index], DataType::TYPE_STRING);
            $sheet->getColumnDimension($column)->setWidth($index === 1 ? 24 : 20);
        }
        $sheet->getStyle('B2:B' . (FieldDetectionService::MAX_ROWS + 1))->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $sheet->getStyle('A2:D2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF3CD');
        $sheet->getStyle('A2:D2')->getFont()->getColor()->setARGB('FF8A5A00');
        self::styleHeader($sheet, 'D');

        $instructions = $spreadsheet->createSheet();
        $instructions->setTitle('填写说明');
        $instructions->fromArray([
            ['成绩查询系统Excel填写说明'],
            ['1. 第一行必须填写字段名称。'],
            ['2. 字段名称不得为空。'],
            ['3. 字段名称不得重复。'],
            ['4. 第二行开始填写实际数据，请先删除“示例（请删除本行）”。'],
            ['5. 身份证号、手机号和学号必须设置为文本，避免科学计数法和前导0丢失。'],
            ['6. 系统会根据第一行自动创建字段。'],
            ['7. 上传后会先预览，不会立即写入数据库。'],
            ['8. 管理员可在上传后选择哪些字段作为查询条件。'],
            ['9. 可以修改、删除示例字段，也可以新增自己的字段。'],
            ['字段类型说明：文本、数字、日期。字段用途：查询条件、结果展示、条件和结果。'],
            ['敏感字段（身份证号、手机号、邮箱等）会由系统加密保存。'],
            ['当前项目：' . (string) $project['name']],
        ], null, 'A1');
        $instructions->getColumnDimension('A')->setWidth(90);
        $instructions->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $instructions->getStyle('A1:A20')->getAlignment()->setWrapText(true);
        $spreadsheet->setActiveSheetIndex(0);
        return $spreadsheet;
    }

    private static function projectWorkbook(array $project, array $fields): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('数据导入');
        foreach ($fields as $index => $field) {
            $column = self::columnName($index + 1);
            $sheet->setCellValueExplicit($column . '1', (string) $field['field_label'], DataType::TYPE_STRING);
            $sheet->getColumnDimension($column)->setWidth(max(16, min(32, mb_strlen((string) $field['field_label'], 'UTF-8') * 3)));
            $range = $column . '2:' . $column . (FieldDetectionService::MAX_ROWS + 1);
            if (self::mustBeText($field)) {
                $sheet->getStyle($range)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            } elseif ((string) $field['field_type'] === 'number') {
                $places = min(8, max(0, (int) $field['decimal_places']));
                $sheet->getStyle($range)->getNumberFormat()->setFormatCode($places > 0 ? '0.' . str_repeat('0', $places) : '0');
            } elseif ((string) $field['field_type'] === 'date') {
                $sheet->getStyle($range)->getNumberFormat()->setFormatCode('yyyy-mm-dd');
            }
            if ((string) $field['field_type'] === 'select' && $field['options'] !== []) {
                $optionString = implode(',', array_slice(array_map('strval', $field['options']), 0, 50));
                if (mb_strlen($optionString, 'UTF-8') <= 240) {
                    $validation = new DataValidation();
                    $validation->setType(DataValidation::TYPE_LIST);
                    $validation->setAllowBlank((int) $field['is_required'] !== 1);
                    $validation->setShowDropDown(true);
                    $validation->setShowErrorMessage(true);
                    $validation->setErrorTitle('选项无效');
                    $validation->setError('请从下拉列表中选择有效值。');
                    $validation->setFormula1('"' . str_replace('"', '""', $optionString) . '"');
                    $sheet->setDataValidation($range, $validation);
                }
            }
        }
        self::styleHeader($sheet, self::columnName(count($fields)));

        $instructions = $spreadsheet->createSheet();
        $instructions->setTitle('填写说明');
        $instructions->fromArray(['字段名称', '字段标识', '是否必填', '字段类型', '字段用途', '敏感字段', '下拉选项', '填写提示'], null, 'A1');
        $typeLabels = ['text' => '文本', 'password' => '文本', 'number' => '数字', 'date' => '日期', 'select' => '下拉选择'];
        $roleLabels = ['condition' => '查询条件', 'result' => '结果展示', 'both' => '条件和结果'];
        foreach ($fields as $index => $field) {
            $instructions->fromArray([
                $field['field_label'], $field['field_key'], (int) $field['is_required'] === 1 ? '是' : '否',
                $typeLabels[$field['field_type']] ?? '文本', $roleLabels[$field['field_role']] ?? '结果展示',
                (int) $field['is_sensitive'] === 1 ? '是' : '否', implode('、', $field['options']), $field['placeholder'] ?? '',
            ], null, 'A' . ($index + 2));
        }
        foreach (range('A', 'H') as $column) {
            $instructions->getColumnDimension($column)->setWidth(in_array($column, ['G', 'H'], true) ? 32 : 18);
        }
        $instructions->getStyle('A1:H1')->getFont()->setBold(true);
        $instructions->freezePane('A2');

        $schema = $spreadsheet->createSheet();
        $schema->setTitle('_system_schema');
        $schema->fromArray([
            'schema_version', 'project_id', 'project_slug', 'field_id', 'field_key', 'field_label', 'field_type',
            'field_role', 'is_required', 'is_sensitive', 'is_visible', 'decimal_places', 'sort_order', 'column_index', 'schema_hash',
        ], null, 'A1');
        $schemaHash = FieldDetectionService::projectSchemaHash($project, $fields);
        foreach ($fields as $index => $field) {
            $schema->fromArray([
                '1', (int) $project['id'], (string) $project['slug'], (int) $field['id'], (string) $field['field_key'],
                (string) $field['field_label'], (string) $field['field_type'], (string) $field['field_role'],
                (int) $field['is_required'], (int) $field['is_sensitive'], (int) $field['is_visible'],
                (int) $field['decimal_places'], (int) $field['sort_order'], $index + 1, $schemaHash,
            ], null, 'A' . ($index + 2));
        }
        $schema->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);
        $spreadsheet->setActiveSheetIndex(0);
        return $spreadsheet;
    }

    private static function styleHeader(Worksheet $sheet, string $lastColumn): void
    {
        $range = 'A1:' . $lastColumn . '1';
        $sheet->getStyle($range)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1677FF');
        $sheet->getStyle($range)->getAlignment()->setHorizontal('center');
        $sheet->freezePane('A2');
        $sheet->setAutoFilter($range);
        $sheet->getRowDimension(1)->setRowHeight(24);
    }

    private static function mustBeText(array $field): bool
    {
        return (string) $field['field_type'] === 'text'
            || (int) $field['is_sensitive'] === 1
            || preg_match('/(?:身份证|证件|护照|手机号|手机号码|学号|准考证|编号|卡号|id|card|phone|number)/iu',
                (string) $field['field_key'] . ' ' . (string) $field['field_label']) === 1;
    }

    private static function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)) . $name;
            $number = intdiv($number, 26);
        }
        return $name;
    }
}
