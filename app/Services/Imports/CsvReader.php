<?php

namespace App\Services\Imports;

use App\Exceptions\CsvImportException;
use App\Support\Imports\CsvDocument;
use Illuminate\Http\UploadedFile;

/**
 * Reads CSV files with PHP's native parser: quoted fields, commas inside
 * quotes, CRLF/LF line endings, a UTF-8 BOM and blank lines are all
 * handled. The first non-blank line is the header. Nothing here knows
 * what the columns mean.
 *
 * File-level rules (documented V1 limits, config/imports.php):
 *   - extension .csv or .txt, text MIME type, never executable content
 *   - at most max_file_bytes
 *   - at most max_rows data rows
 */
class CsvReader
{
    /**
     * Validates an upload before it is stored: type, size and that a
     * header can be read. Throws CsvImportException with a user-safe message.
     */
    public function inspectUpload(UploadedFile $file): CsvDocument
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (! in_array($extension, config('imports.allowed_extensions'), true)) {
            throw new CsvImportException('Only CSV files (.csv) can be imported; spreadsheets must be exported to CSV first.');
        }

        $this->ensureSize((int) $file->getSize());

        // Sniff the CONTENT (not the client-declared type): a spreadsheet or
        // binary renamed to .csv is refused here.
        $mime = strtolower((string) $this->detectMimeType($file));

        if (! in_array($mime, config('imports.allowed_mime_types'), true)
            && ! (in_array($mime, ['application/octet-stream', 'inode/x-empty', ''], true) && $this->looksLikeText($file->getRealPath()))) {
            throw new CsvImportException("The file does not look like a CSV text file (detected type {$mime}).");
        }

        return $this->readPath($file->getRealPath());
    }

    /**
     * Very short files are sometimes reported as octet-stream; accept them
     * only when the leading bytes contain no binary (NUL) characters.
     */
    protected function looksLikeText(string $path): bool
    {
        $sample = (string) file_get_contents($path, false, null, 0, 8192);

        return ! str_contains($sample, "\0");
    }

    protected function detectMimeType(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();

        if (is_string($path) && $path !== '' && is_file($path) && class_exists(\finfo::class)) {
            $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }

        return $file->getMimeType();
    }

    public function readPath(string $path): CsvDocument
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new CsvImportException('The CSV file could not be read.');
        }

        $this->ensureSize((int) filesize($path));

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new CsvImportException('The CSV file could not be opened.');
        }

        try {
            return $this->parse($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     */
    protected function parse($handle): CsvDocument
    {
        // Strip a UTF-8 byte-order mark so the first header is clean.
        $bom = fread($handle, 3);

        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $headers = null;
        $rows = [];
        $line = 0;
        $maxRows = (int) config('imports.max_rows');

        while (($cells = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $line++;

            if ($this->isBlank($cells)) {
                continue;
            }

            if ($headers === null) {
                $headers = $this->normaliseHeaders($cells);

                continue;
            }

            $rows[$line] = array_map(fn ($cell): string => $this->cleanCell((string) $cell), $cells);

            if (count($rows) > $maxRows) {
                throw new CsvImportException(sprintf('The CSV has more than %s data rows; split it into smaller files.', number_format($maxRows)));
            }
        }

        if ($headers === null) {
            throw new CsvImportException('The CSV has no header row. The first line must name the columns.');
        }

        return new CsvDocument($headers, $rows);
    }

    /**
     * @param  list<string|null>  $cells
     * @return list<string>
     */
    protected function normaliseHeaders(array $cells): array
    {
        $headers = array_map(fn ($cell): string => trim($this->cleanCell((string) $cell)), $cells);

        // Drop trailing empty header cells (a trailing comma on the header line).
        while ($headers !== [] && end($headers) === '') {
            array_pop($headers);
        }

        if ($headers === [] || array_filter($headers, fn (string $h): bool => $h !== '') === []) {
            throw new CsvImportException('The CSV has no header row. The first line must name the columns.');
        }

        foreach ($headers as $index => $header) {
            if ($header === '') {
                throw new CsvImportException(sprintf('Header column %d is blank; every column needs a name.', $index + 1));
            }
        }

        $duplicates = array_unique(array_diff_assoc($headers, array_unique($headers)));

        if ($duplicates !== []) {
            throw new CsvImportException('Duplicate header names: '.implode(', ', $duplicates).'. Each column needs a distinct name.');
        }

        return array_values($headers);
    }

    protected function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    protected function cleanCell(string $cell): string
    {
        // Invalid UTF-8 would break JSON storage and HTML escaping downstream.
        if (! mb_check_encoding($cell, 'UTF-8')) {
            $cell = mb_convert_encoding($cell, 'UTF-8', 'ISO-8859-1');
        }

        return $cell;
    }

    protected function ensureSize(int $bytes): void
    {
        $max = (int) config('imports.max_file_bytes');

        if ($bytes > $max) {
            throw new CsvImportException(sprintf('The file is larger than the %s MB import limit.', round($max / 1048576, 1)));
        }

        if ($bytes === 0) {
            throw new CsvImportException('The file is empty.');
        }
    }
}
