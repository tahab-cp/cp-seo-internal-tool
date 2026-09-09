<?php

namespace App\Support\LegacyMigration;

/**
 * A parsed legacy source (XLSX workbook or a directory of per-sheet CSVs),
 * read-only, with its checksum.
 */
final class LegacyWorkbook
{
    /**
     * @param  list<LegacySheet>  $sheets
     */
    public function __construct(
        public readonly string $path,
        public readonly string $filename,
        public readonly string $checksum,
        public readonly array $sheets,
    ) {}

    public function sheet(string $name): ?LegacySheet
    {
        $wanted = LegacySheet::normaliseName($name);

        foreach ($this->sheets as $sheet) {
            if ($sheet->normalisedName() === $wanted) {
                return $sheet;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function sheetNames(): array
    {
        return array_map(fn (LegacySheet $sheet): string => $sheet->name, $this->sheets);
    }
}
