<?php

namespace App\Services\Imports\Importers;

use App\Enums\ImportType;
use App\Support\Imports\IdentityTracker;
use App\Support\Imports\ImportContext;
use App\Support\Imports\ImportField;
use App\Support\Imports\PreparedRow;
use Illuminate\Support\Collection;

/**
 * One import type. An importer translates mapped CSV values into the
 * payload an EXISTING domain action expects; it never writes to the
 * database itself and never re-implements domain validation beyond
 * CSV-specific normalisation.
 */
interface CsvImporter
{
    public function type(): ImportType;

    /**
     * @return list<ImportField>
     */
    public function fields(): array;

    /**
     * Normalises and checks one row WITHOUT writing. Read-only lookups
     * (existing keywords, pages, observations) are allowed. Every problem
     * is attached to the row; nothing throws for data errors.
     *
     * @param  array<string, string>  $values  mapped raw values by field key ('' when unmapped or blank)
     */
    public function prepare(ImportContext $context, array $values, int $rowNumber, IdentityTracker $identities): PreparedRow;

    /**
     * Writes every prepared row through the domain actions. Runs inside the
     * execution transaction with the cycle row already locked. Returns the
     * number of records written. Throws on any failure (the caller rolls
     * the whole batch back).
     *
     * @param  Collection<int, PreparedRow>  $rows
     */
    public function import(ImportContext $context, Collection $rows): int;
}
