<?php

namespace App\Services\LegacyMigration;

use App\Services\Imports\CsvReader;
use App\Support\LegacyMigration\LegacySheet;
use App\Support\LegacyMigration\LegacyWorkbook;
use DateTimeInterface;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Reads a legacy source READ-ONLY:
 *   - an .xlsx workbook (OpenSpout, streaming, no formulas evaluated by us:
 *     the cached computed value is used, never the formula text)
 *   - or a directory of .csv files, one per sheet, named after the sheet
 *
 * The source is opened for reading only and never written, renamed or
 * deleted. The checksum (SHA-256) identifies the source across reruns.
 */
class LegacyWorkbookReader
{
    public function __construct(
        protected CsvReader $csv,
    ) {}

    public function read(string $path): LegacyWorkbook
    {
        if (is_dir($path)) {
            return $this->readCsvDirectory(rtrim($path, '/\\'));
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("Source [{$path}] does not exist or is not readable.");
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'xlsx' => $this->readXlsx($path),
            'csv' => new LegacyWorkbook($path, basename($path), hash_file('sha256', $path), [$this->readCsvFile($path)]),
            default => throw new InvalidArgumentException("Unsupported legacy source [{$extension}]: use an .xlsx workbook or a directory of .csv files."),
        };
    }

    protected function readXlsx(string $path): LegacyWorkbook
    {
        $reader = new XlsxReader;
        $reader->open($path);

        $sheets = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $headers = null;
                $rows = [];

                foreach ($sheet->getRowIterator() as $rowNumber => $row) {
                    $cells = $this->cells($row);

                    if ($this->blank($cells)) {
                        continue;
                    }

                    if ($headers === null) {
                        $headers = $this->headers($cells, $sheet->getName());

                        continue;
                    }

                    $rows[(int) $rowNumber] = $this->assoc($headers, $cells);
                }

                if ($headers !== null) {
                    $sheets[] = new LegacySheet($sheet->getName(), $headers, $rows);
                }
            }
        } finally {
            $reader->close();
        }

        return new LegacyWorkbook($path, basename($path), hash_file('sha256', $path), $sheets);
    }

    protected function readCsvDirectory(string $directory): LegacyWorkbook
    {
        $files = glob($directory.DIRECTORY_SEPARATOR.'*.csv') ?: [];
        sort($files);

        if ($files === []) {
            throw new InvalidArgumentException("Directory [{$directory}] contains no .csv files.");
        }

        $sheets = [];
        $hashes = [];

        foreach ($files as $file) {
            $sheets[] = $this->readCsvFile($file);
            $hashes[] = basename($file).':'.hash_file('sha256', $file);
        }

        return new LegacyWorkbook($directory, basename($directory), hash('sha256', implode("\n", $hashes)), $sheets);
    }

    protected function readCsvFile(string $file): LegacySheet
    {
        $document = $this->csv->readPath($file);
        $rows = [];

        foreach ($document->rows as $rowNumber => $cells) {
            $rows[$rowNumber] = array_map('trim', $document->assoc($cells));
        }

        return new LegacySheet(pathinfo($file, PATHINFO_FILENAME), $document->headers, $rows);
    }

    /**
     * @return list<string>
     */
    protected function cells(Row $row): array
    {
        $values = [];

        foreach ($row->getCells() as $cell) {
            $value = $cell instanceof FormulaCell ? $cell->getComputedValue() : $cell->getValue();
            $values[] = $this->stringify($value);
        }

        return $values;
    }

    protected function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('H:i:s') === '00:00:00' ? $value->format('Y-m-d') : $value->format('Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_float($value)) {
            return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        }

        return trim((string) $value);
    }

    /**
     * @param  list<string>  $cells
     * @return list<string>
     */
    protected function headers(array $cells, string $sheet): array
    {
        while ($cells !== [] && end($cells) === '') {
            array_pop($cells);
        }

        foreach ($cells as $index => $header) {
            if ($header === '') {
                throw new InvalidArgumentException(sprintf('Sheet "%s": header column %d is blank; every column needs a name.', $sheet, $index + 1));
            }
        }

        $duplicates = array_unique(array_diff_assoc($cells, array_unique($cells)));

        if ($duplicates !== []) {
            throw new InvalidArgumentException(sprintf('Sheet "%s": duplicate header names: %s.', $sheet, implode(', ', $duplicates)));
        }

        return array_values($cells);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $cells
     * @return array<string, string>
     */
    protected function assoc(array $headers, array $cells): array
    {
        $assoc = [];

        foreach ($headers as $index => $header) {
            $assoc[$header] = (string) ($cells[$index] ?? '');
        }

        return $assoc;
    }

    protected function blank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== '') {
                return false;
            }
        }

        return true;
    }
}
