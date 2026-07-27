<?php

declare(strict_types=1);

/**
 * Unified Excel security inspection service.
 * Both import paths (existing project import and new query wizard) must use this.
 */
final class ExcelSecurityInspector
{
    public const MAX_FILE_SIZE = 5242880;
    public const MAX_ZIP_ENTRIES = 1000;
    public const MAX_UNCOMPRESSED_TOTAL = 52428800;
    public const MAX_UNCOMPRESSED_ENTRY = 20971520;
    public const MAX_COMPRESSION_RATIO = 100;
    public const MAX_SHEETS = 10;
    public const MAX_COLS = 100;
    public const MAX_DATA_ROWS = 5000;

    public static function assertUploadPresent(array $upload): string
    {
        $tmpName = (string) ($upload['tmp_name'] ?? '');
        $uploaded = PHP_SAPI === 'cli' ? is_file($tmpName) : is_uploaded_file($tmpName);
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !$uploaded) {
            throw new InvalidArgumentException('文件上传失败，请重新选择文件。');
        }
        return $tmpName;
    }

    public static function validateBeforeLoad(array $upload): string
    {
        $tmpName = self::assertUploadPresent($upload);

        $size = (int) ($upload['size'] ?? (is_file($tmpName) ? filesize($tmpName) : 0));
        if ($size <= 0 || $size > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException('Excel文件必须小于或等于5MB。');
        }

        $extension = mb_strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION), 'UTF-8');
        if (!in_array($extension, ['xlsx', 'xls'], true)) {
            throw new InvalidArgumentException('只允许上传 .xlsx 或 .xls 文件。');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpName);
        $allowedMimes = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'application/zip',
            'application/octet-stream',
            'application/CDFV2',
        ];
        if (!is_string($mime) || !in_array($mime, $allowedMimes, true)) {
            throw new InvalidArgumentException('文件MIME类型不符合Excel格式。');
        }

        if ($extension === 'xlsm') {
            throw new InvalidArgumentException('不支持宏启用的Excel文件(.xlsm)。');
        }

        if ($extension === 'xlsx') {
            self::validateZipArchive($tmpName);
        }

        return $tmpName;
    }

    public static function createReader(string $extension): \PhpOffice\PhpSpreadsheet\Reader\BaseReader
    {
        if ($extension === 'xls') {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xls();
        } else {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        }
        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }
        return $reader;
    }

    public static function validateSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
    {
        $highestRow = $sheet->getHighestDataRow();
        $highestCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString(
            $sheet->getHighestDataColumn()
        );

        if ($highestCol > self::MAX_COLS) {
            throw new InvalidArgumentException('Excel表格列数超过限制(' . self::MAX_COLS . '列)，请精简后重新上传。');
        }
        if ($highestRow > self::MAX_DATA_ROWS + 1) {
            throw new InvalidArgumentException('Excel表格行数超过限制(' . self::MAX_DATA_ROWS . '行)，请精简后重新上传。');
        }

        if ($sheet->getParent() && $sheet->getParent()->getSheetCount() > self::MAX_SHEETS) {
            throw new InvalidArgumentException('Excel文件工作表数量超过限制(' . self::MAX_SHEETS . '个)。');
        }

        self::checkExternalLinks($sheet);
    }

    private static function validateZipArchive(string $filePath): void
    {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new InvalidArgumentException('无法打开Excel文件。');
        }

        try {
            $entryCount = $zip->numFiles;
            if ($entryCount > self::MAX_ZIP_ENTRIES) {
                throw new InvalidArgumentException('Excel文件包含过多条目，可能存在安全风险。');
            }

            $totalUncompressed = 0;

            for ($i = 0; $i < $entryCount; $i++) {
                $entryName = $zip->getNameIndex($i);
                if ($entryName === false) continue;

                if (str_contains($entryName, '..') || str_starts_with($entryName, '/') || str_starts_with($entryName, '\\')) {
                    throw new InvalidArgumentException('Excel文件包含非法路径。');
                }

                $lowerName = strtolower($entryName);
                if (str_contains($lowerName, 'externallinks') || str_contains($lowerName, 'externallink')) {
                    throw new InvalidArgumentException('Excel文件包含外部链接引用，已拒绝。');
                }

                $stats = $zip->statIndex($i);
                if (!is_array($stats)) continue;

                $compressed = (int) ($stats['comp_size'] ?? 0);
                $uncompressed = (int) ($stats['size'] ?? 0);

                if ($uncompressed > self::MAX_UNCOMPRESSED_ENTRY) {
                    throw new InvalidArgumentException('Excel文件包含过大的条目。');
                }

                if ($compressed > 1024 && $uncompressed > 0 && ($uncompressed / $compressed) > self::MAX_COMPRESSION_RATIO) {
                    throw new InvalidArgumentException('Excel文件压缩比异常，可能存在安全风险。');
                }

                $totalUncompressed += $uncompressed;
                if ($totalUncompressed > self::MAX_UNCOMPRESSED_TOTAL) {
                    throw new InvalidArgumentException('Excel文件解压后超过大小限制。');
                }
            }
        } finally {
            $zip->close();
        }
    }

    private static function checkExternalLinks(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
    {
        try {
            $parent = $sheet->getParent();
            if ($parent === null) return;

            if (method_exists($parent, 'getExternalLinks')) {
                $externalLinks = $parent->getExternalLinks();
                if (!empty($externalLinks)) {
                    throw new InvalidArgumentException('Excel文件包含外部链接，已拒绝。');
                }
            }
        } catch (Throwable $e) {
            if ($e instanceof InvalidArgumentException) throw $e;
            PublicErrorHandler::logException($e, [
                'component' => 'excel_security',
                'operation' => 'external_link_check',
            ]);
        }
    }
}
