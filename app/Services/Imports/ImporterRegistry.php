<?php

namespace App\Services\Imports;

use App\Enums\ImportType;
use App\Services\Imports\Importers\BacklinkCsvImporter;
use App\Services\Imports\Importers\CsvImporter;
use App\Services\Imports\Importers\Ga4CountryCsvImporter;
use App\Services\Imports\Importers\GscPageCsvImporter;
use App\Services\Imports\Importers\GscQueryCsvImporter;
use App\Services\Imports\Importers\KeywordCsvImporter;
use App\Services\Imports\Importers\RankingCsvImporter;

/**
 * Resolves the importer for an import type. The V1 list is fixed here.
 */
class ImporterRegistry
{
    /**
     * @var array<string, class-string<CsvImporter>>
     */
    protected const IMPORTERS = [
        ImportType::Keywords->value => KeywordCsvImporter::class,
        ImportType::RankingSnapshots->value => RankingCsvImporter::class,
        ImportType::Backlinks->value => BacklinkCsvImporter::class,
        ImportType::GscQueries->value => GscQueryCsvImporter::class,
        ImportType::GscPages->value => GscPageCsvImporter::class,
        ImportType::Ga4Countries->value => Ga4CountryCsvImporter::class,
    ];

    public function for(ImportType $type): CsvImporter
    {
        return app(self::IMPORTERS[$type->value]);
    }
}
