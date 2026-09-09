<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The V1 CSV import types. Each one maps rows onto EXISTING domain
 * actions; the enum is also the key under which the importer is resolved.
 */
enum ImportType: string implements HasLabel
{
    case Keywords = 'keywords';
    case RankingSnapshots = 'ranking_snapshots';
    case Backlinks = 'backlinks';
    case GscQueries = 'gsc_queries';
    case GscPages = 'gsc_pages';
    case Ga4Countries = 'ga4_countries';

    public function getLabel(): string
    {
        return match ($this) {
            self::Keywords => 'Keywords',
            self::RankingSnapshots => 'Ranking snapshots',
            self::Backlinks => 'Backlinks',
            self::GscQueries => 'Search Console queries',
            self::GscPages => 'Search Console landing pages',
            self::Ga4Countries => 'GA4 audience by country',
        };
    }

    /**
     * Whether the import writes into one reporting month (and therefore
     * needs a MonthlyCycle, lock checks and row locking).
     */
    public function requiresCycle(): bool
    {
        return $this !== self::Keywords;
    }

    /**
     * Whether the import REPLACES the month's dataset of that type, exactly
     * like the manual bulk entry screens do.
     */
    public function replacesMonthlyDataset(): bool
    {
        return in_array($this, [self::GscQueries, self::GscPages, self::Ga4Countries], true);
    }
}
