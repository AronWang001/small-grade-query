<?php

declare(strict_types=1);

require_once __DIR__ . '/ExcelImportService.php';
require_once __DIR__ . '/ExcelTemplateService.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Backward-compatible facade used by the existing admin entry points.
 */
final class ExcelService
{
    private const MAX_ROWS = 5000;

    public static function outputTemplate(int $projectId): never
    {
        ExcelTemplateService::outputTemplate($projectId);
    }

    public static function createPreview(int $adminId, int $projectId, string $mode, array $upload): array
    {
        $preview = ExcelImportService::createSchemaPreview($adminId, $projectId, $mode, $upload);
        // Keep the original facade contract used by admin.php while exposing
        // the richer batch/payload structure returned by the import service.
        $preview['token'] = (string) ($preview['batch']['token'] ?? '');
        return $preview;
    }

    public static function confirmSchema(string $token, int $adminId, array $columns): array
    {
        return ExcelImportService::confirmSchema($token, $adminId, $columns);
    }

    public static function preview(string $token, int $adminId): ?array
    {
        return ExcelImportService::preview($token, $adminId);
    }

    public static function commit(string $token, int $adminId): array
    {
        return ExcelImportService::commitImport($token, $adminId);
    }

    public static function outputExport(int $projectId, string $search, bool $includeSensitive): never
    {
        $project = GradeRepository::project($projectId, true);
        $fields = GradeRepository::fields($projectId);
        if ($project === null || $fields === []) {
            throw new PublicSafeException('查询项目不存在或尚未配置字段。');
        }
        $records = [];
        $page = 1;
        do {
            $batch = GradeRepository::listRecords($projectId, $search, $page, 100);
            array_push($records, ...$batch['records']);
            $page++;
        } while ($page <= $batch['pages'] && count($records) < self::MAX_ROWS);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('导出数据');
        foreach ($fields as $index => $field) {
            $column = self::columnName($index + 1);
            $sheet->setCellValueExplicit($column . '1', (string) $field['field_label'], DataType::TYPE_STRING);
            $sheet->getColumnDimension($column)->setWidth(18);
            if ((int) $field['is_sensitive'] === 1) {
                $sheet->getStyle($column . '2:' . $column . (count($records) + 1))->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            }
        }
        foreach ($records as $rowIndex => $record) {
            $values = GradeRepository::recordValues((int) $record['id'], !$includeSensitive);
            foreach ($fields as $columnIndex => $field) {
                $value = (string) ($values[$field['field_key']] ?? '');
                $sheet->setCellValueExplicit(self::columnName($columnIndex + 1) . ($rowIndex + 2), $value, DataType::TYPE_STRING);
            }
        }
        $sheet->getStyle('A1:' . self::columnName(count($fields)) . '1')->getFont()->setBold(true);
        $sheet->freezePane('A2');
        audit_log('excel_export', 'query_project', $projectId, true, [
            'rows' => count($records), 'included_sensitive' => $includeSensitive, 'filtered' => $search !== '',
        ]);
        $filename = preg_replace('/[^\p{L}\p{N}_-]+/u', '_', (string) $project['name']) . '_导出_' . date('Ymd_His') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($filename));
        header('Cache-Control: no-store');
        (new Xlsx($spreadsheet))->save('php://output');
        $spreadsheet->disconnectWorksheets();
        exit;
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
