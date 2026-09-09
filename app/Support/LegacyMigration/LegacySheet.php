<?php

namespace App\Support\LegacyMigration;

/**
 * One worksheet (or CSV file) of a legacy workbook: raw header cells and
 * data rows keyed by their physical row number. Every cell is a trimmed
 * string; dates read from XLSX cells are rendered as YYYY-MM-DD[ HH:MM:SS].
 */
final class LegacySheet
{
    /**
     * @param  list<string>  $headers
     * @param  array<int, array<string, string>>  $rows  row number => header => value
     */
    public function __construct(
        public readonly string $name,
        public readonly array $headers,
        public readonly array $rows,
    ) {}

    public function rowCount(): int
    {
        return count($this->rows);
    }

    public static function normaliseName(string $name): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $name) ?? $name, '_'));
    }

    public function normalisedName(): string
    {
        return self::normaliseName($this->name);
    }

    /**
     * The header whose normalised form matches one of the candidates.
     *
     * @param  list<string>  $candidates
     */
    public function header(array $candidates): ?string
    {
        $wanted = array_map(fn (string $c): string => self::normaliseName($c), $candidates);

        foreach ($this->headers as $header) {
            if (in_array(self::normaliseName($header), $wanted, true)) {
                return $header;
            }
        }

        return null;
    }
}
