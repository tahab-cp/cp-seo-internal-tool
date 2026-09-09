<?php

namespace App\Services\Imports;

use App\Enums\ImportStatus;
use App\Exceptions\CsvImportException;
use App\Models\ImportBatch;
use App\Models\ImportRowError;
use App\Models\User;
use App\Support\Imports\IdentityTracker;
use App\Support\Imports\ImportContext;
use App\Support\Imports\PreparedRow;
use App\Support\Imports\RowIssue;
use App\Support\Imports\ValidationReport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Step 4: parse → map → normalise → check every row through the importer,
 * WITHOUT writing any domain data. Problems are stored per row so they
 * stay reviewable; the batch becomes "validated" with its counts.
 */
class ImportValidationService
{
    public function __construct(
        protected ImportUploadService $uploads,
        protected CsvColumnMapper $mapper,
        protected ImporterRegistry $importers,
    ) {}

    /**
     * Validates in memory only (no batch/row-error writes). Used by the
     * preview and re-used by execution for a fresh check.
     */
    public function check(ImportBatch $batch, User $user): ValidationReport
    {
        if ($batch->isCompleted()) {
            throw new CsvImportException('This import has already been completed.');
        }

        $importer = $this->importers->for($batch->import_type);
        $document = $this->uploads->document($batch);
        $mapping = $this->mapper->validate($batch->mapping(), $document->headers, $importer->fields());

        $context = new ImportContext(
            batch: $batch,
            project: $batch->project,
            cycle: $batch->monthlyCycle,
            user: $user,
        );

        $identities = new IdentityTracker;
        $rows = new Collection;

        foreach ($document->rows as $rowNumber => $cells) {
            $assoc = $document->assoc($cells);
            $values = [];

            foreach ($importer->fields() as $field) {
                $header = $mapping[$field->key] ?? null;
                $values[$field->key] = $header === null ? '' : trim($assoc[$header] ?? '');
            }

            $overflow = $document->overflow($cells);

            if ($overflow > 0) {
                $row = new PreparedRow($rowNumber, $values, null);
                $row->addError(null, sprintf('The row has %d more value(s) than the header has columns; check for unquoted commas.', $overflow));
                $rows->push($row);

                continue;
            }

            $rows->push($importer->prepare($context, $values, $rowNumber, $identities));
        }

        return new ValidationReport($rows);
    }

    /**
     * Validates and records the outcome on the batch (status validated,
     * counts, reviewable row errors and warnings).
     */
    public function validate(ImportBatch $batch, User $user): ValidationReport
    {
        $report = $this->check($batch, $user);

        DB::transaction(function () use ($batch, $report): void {
            $this->storeIssues($batch, $report->issues(), $report);

            $batch->status = ImportStatus::Validated;
            $batch->total_rows = $report->totalRows();
            $batch->valid_rows = $report->validCount();
            $batch->failed_rows = $report->invalidCount();
            $batch->imported_rows = 0;
            $batch->save();
        });

        return $report;
    }

    /**
     * @param  Collection<int, RowIssue>  $issues
     */
    public function storeIssues(ImportBatch $batch, Collection $issues, ?ValidationReport $report = null): void
    {
        $batch->rowErrors()->delete();

        $rawByRow = $report?->rows->keyBy(fn (PreparedRow $row): int => $row->rowNumber);
        $now = now();

        $records = $issues->map(fn (RowIssue $issue): array => [
            'import_batch_id' => $batch->getKey(),
            'row_number' => $issue->rowNumber,
            'field' => $issue->field,
            'severity' => $issue->severity,
            'message' => mb_substr($issue->message, 0, 1000),
            'raw_row_json' => json_encode($rawByRow?->get($issue->rowNumber)?->values ?? null),
            'created_at' => $now,
        ])->all();

        foreach (array_chunk($records, 500) as $chunk) {
            ImportRowError::query()->insert($chunk);
        }
    }
}
