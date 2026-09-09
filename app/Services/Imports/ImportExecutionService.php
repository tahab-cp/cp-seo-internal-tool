<?php

namespace App\Services\Imports;

use App\Enums\ImportStatus;
use App\Exceptions\CsvImportException;
use App\Exceptions\ImportRowException;
use App\Exceptions\LockedMonthlyCycleException;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\MonthlyCycles\Concerns\LocksMonthlyCycles;
use App\Support\Imports\ImportContext;
use App\Support\Imports\RowIssue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Throwable;

/**
 * Step 5: the explicit, ATOMIC import.
 *
 *   1. the batch must have been validated with zero invalid rows
 *   2. rows are re-checked fresh (no lock held while parsing)
 *   3. ONE transaction: the reporting month is row-locked and re-checked
 *      through MonthlyCycleMutationGuard, every row is written through the
 *      domain actions, and the batch is marked completed
 *   4. any failure rolls the whole batch back; the batch is marked failed
 *      with the reason attributed to the row that caused it
 *
 * Nothing is ever half-imported.
 */
class ImportExecutionService
{
    use LocksMonthlyCycles;

    public function __construct(
        protected ImportValidationService $validation,
        protected ImporterRegistry $importers,
    ) {}

    public function execute(ImportBatch $batch, User $user): ImportBatch
    {
        if (! Gate::forUser($user)->allows('view', $batch)) {
            throw new CsvImportException('You may not run this import.');
        }

        if ($batch->isCompleted()) {
            throw new CsvImportException('This import has already been completed.');
        }

        if (! $batch->isValidated()) {
            throw new CsvImportException('Validate the file before importing it.');
        }

        if ($batch->failed_rows > 0) {
            throw new CsvImportException('The file still has invalid rows; fix the CSV and validate it again.');
        }

        // Parse and check again WITHOUT any lock: data may have changed since the preview.
        $report = $this->validation->check($batch, $user);

        if (! $report->isImportable()) {
            $this->fail($batch, $report->issues(), $report->totalRows(), $report->validCount());

            return $batch;
        }

        $batch->started_at = now();
        $batch->save();

        $importer = $this->importers->for($batch->import_type);
        $context = new ImportContext(batch: $batch, project: $batch->project, cycle: $batch->monthlyCycle, user: $user);

        try {
            DB::transaction(function () use ($batch, $importer, $context, $report): void {
                if ($context->cycle !== null) {
                    // Existing convention: FOR UPDATE on the cycle row, fresh lock-state check.
                    $this->lockCycle($context->cycle, 'import CSV data into it');
                }

                $written = $importer->import($context, $report->validRows());

                $batch->rowErrors()->delete();
                $this->validation->storeIssues($batch, $report->issues()->filter(fn (RowIssue $i): bool => $i->isWarning())->values(), $report);

                $batch->status = ImportStatus::Completed;
                $batch->total_rows = $report->totalRows();
                $batch->valid_rows = $report->validCount();
                $batch->imported_rows = $written;
                $batch->failed_rows = 0;
                $batch->completed_at = now();
                $batch->save();
            });
        } catch (ImportRowException $exception) {
            $this->fail($batch, collect([RowIssue::error($exception->rowNumber, null, $exception->getMessage())]), $report->totalRows(), $report->validCount());
        } catch (LockedMonthlyCycleException|InvalidArgumentException $exception) {
            $this->fail($batch, collect([RowIssue::error(0, null, $exception->getMessage())]), $report->totalRows(), $report->validCount());
        } catch (Throwable $exception) {
            report($exception);
            $this->fail($batch, collect([RowIssue::error(0, null, 'The import could not be completed and nothing was written. Please try again or contact an administrator.')]), $report->totalRows(), $report->validCount());
        }

        return $batch->refresh();
    }

    /**
     * Records a failed attempt. Row 0 means the failure is batch-level.
     */
    protected function fail(ImportBatch $batch, Collection $issues, int $total, int $valid): void
    {
        DB::transaction(function () use ($batch, $issues, $total, $valid): void {
            $this->validation->storeIssues($batch, $issues);

            $batch->status = ImportStatus::Failed;
            $batch->total_rows = $total;
            $batch->valid_rows = $valid;
            $batch->imported_rows = 0;
            $batch->failed_rows = max(1, $issues->filter(fn (RowIssue $i): bool => $i->isError())->pluck('rowNumber')->unique()->count());
            $batch->completed_at = now();
            $batch->save();
        });
    }
}
