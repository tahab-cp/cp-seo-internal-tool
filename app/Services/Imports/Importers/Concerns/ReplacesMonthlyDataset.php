<?php

namespace App\Services\Imports\Importers\Concerns;

use App\Exceptions\ImportRowException;
use App\Support\Imports\ImportContext;
use App\Support\Imports\PreparedRow;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Analytics detail imports hand the whole row set to the existing
 * Save*MetricsAction, which REPLACES the month's dataset of that type
 * exactly like the manual bulk-entry screen: rows in the CSV are written
 * (matched by their normalised identity), rows absent from the CSV are
 * removed for that month. This is documented import behaviour.
 */
trait ReplacesMonthlyDataset
{
    /**
     * @param  Collection<int, PreparedRow>  $rows
     */
    protected function saveDataset(ImportContext $context, Collection $rows, callable $save): int
    {
        $payload = $rows->map(fn (PreparedRow $row): array => $row->data)->values()->all();

        try {
            $saved = $save($context->cycle, $payload, $context->user);
        } catch (Throwable $exception) {
            throw ImportRowException::forRow($rows->first()?->rowNumber ?? 0, $exception);
        }

        return $saved->count();
    }
}
