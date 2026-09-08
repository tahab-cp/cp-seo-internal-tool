<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where an analytics record came from. Only manual entry is implemented;
 * csv_import arrives with Milestone 16 and the API values are reserved so
 * connected sources can be added without a schema change.
 */
enum DataSource: string implements HasColor, HasLabel
{
    case Manual = 'manual';
    case CsvImport = 'csv_import';
    case GscApi = 'gsc_api';
    case Ga4Api = 'ga4_api';
    case AhrefsApi = 'ahrefs_api';
    case SemrushApi = 'semrush_api';
    case DataForSeo = 'dataforseo';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => 'Manual Entry',
            self::CsvImport => 'CSV Import',
            self::GscApi => 'Google Search Console (API)',
            self::Ga4Api => 'Google Analytics 4 (API)',
            self::AhrefsApi => 'Ahrefs (API)',
            self::SemrushApi => 'Semrush (API)',
            self::DataForSeo => 'DataForSEO',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Manual => 'gray',
            self::CsvImport => 'info',
            default => 'success',
        };
    }

    public function isManual(): bool
    {
        return $this === self::Manual;
    }

    /**
     * Sources a person can record through the application today.
     *
     * @return list<self>
     */
    public static function implemented(): array
    {
        return [self::Manual];
    }
}
