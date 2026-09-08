<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a ranking observation came from. Only manual entry exists in V1;
 * the other values are reserved so imports and integrations can record
 * their observations later without a schema change.
 */
enum RankingSource: string implements HasColor, HasLabel
{
    case Manual = 'manual';
    case CsvImport = 'csv_import';
    case Semrush = 'semrush';
    case Ahrefs = 'ahrefs';
    case DataForSeo = 'dataforseo';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::CsvImport => 'CSV import',
            self::Semrush => 'Semrush',
            self::Ahrefs => 'Ahrefs',
            self::DataForSeo => 'DataForSEO',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Manual => 'gray',
            self::CsvImport => 'info',
            default => 'primary',
        };
    }
}
