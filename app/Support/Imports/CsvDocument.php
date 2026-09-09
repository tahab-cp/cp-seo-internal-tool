<?php

namespace App\Support\Imports;

/**
 * A parsed CSV: trimmed headers and the data rows keyed by their physical
 * line number in the file (the header is line 1, blank lines still count),
 * so "Row 14" matches what the user sees in a spreadsheet.
 */
final readonly class CsvDocument
{
    /**
     * @param  list<string>  $headers
     * @param  array<int, list<string>>  $rows  line number => raw cells
     */
    public function __construct(
        public array $headers,
        public array $rows,
    ) {}

    public function rowCount(): int
    {
        return count($this->rows);
    }

    /**
     * @return array<string, string> header => raw cell for the given row (missing cells are '')
     */
    public function assoc(array $cells): array
    {
        $assoc = [];

        foreach ($this->headers as $index => $header) {
            $assoc[$header] = (string) ($cells[$index] ?? '');
        }

        return $assoc;
    }

    /**
     * Non-blank cells beyond the header width (a malformed row).
     */
    public function overflow(array $cells): int
    {
        $extra = array_slice($cells, count($this->headers));

        return count(array_filter($extra, fn ($cell): bool => trim((string) $cell) !== ''));
    }
}
