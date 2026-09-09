<?php

namespace App\Console\Commands;

use App\Enums\ImportStatus;
use App\Models\ImportBatch;
use App\Services\Imports\ImportFileStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Retention for uploaded CSV files (which may contain client data): the
 * raw file of an import batch older than the retention window is removed
 * from the private import disk. The batch, its counts and its row
 * issues are KEPT for audit; only stored_file_path is cleared. Batches
 * still waiting for their import step are pruned as well once they are
 * older than the window (the wizard asks for a fresh upload).
 */
class PruneImportFilesCommand extends Command
{
    protected $signature = 'seo:prune-import-files
        {--days= : Retention window in days (default: config imports.file_retention_days)}
        {--dry-run : List what would be removed without touching any file}';

    protected $description = 'Remove uploaded CSV files older than the retention window while keeping import history and row issues';

    public function handle(ImportFileStore $files): int
    {
        $days = filled($this->option('days')) ? (int) $this->option('days') : (int) config('imports.file_retention_days', 90);

        if ($days < 1) {
            $this->error('Retention must be at least 1 day.');

            return self::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days);

        $batches = ImportBatch::query()
            ->whereNotNull('stored_file_path')
            ->where('created_at', '<', $cutoff)
            ->whereIn('status', array_map(fn (ImportStatus $s): string => $s->value, ImportStatus::cases()))
            ->orderBy('id')
            ->get();

        $removed = 0;
        $missing = 0;

        foreach ($batches as $batch) {
            $path = (string) $batch->stored_file_path;

            if ($dryRun) {
                $this->line(sprintf('would remove batch #%d (%s, %s): %s', $batch->getKey(), $batch->status->value, $batch->created_at?->toDateString(), $path));

                continue;
            }

            try {
                if ($files->disk()->exists($path)) {
                    $files->disk()->delete($path);
                    $removed++;
                } else {
                    $missing++;
                }

                $batch->forceFill(['stored_file_path' => null])->save();
            } catch (Throwable $exception) {
                Log::warning('Import file could not be pruned', ['import_batch_id' => $batch->getKey(), 'error' => $exception->getMessage()]);
                $this->warn(sprintf('batch #%d: %s', $batch->getKey(), $exception->getMessage()));
            }
        }

        $summary = sprintf('%s: %d batch(es) older than %d day(s)%s', $dryRun ? 'Dry run' : 'Pruned', $batches->count(), $days, $dryRun ? '' : " — {$removed} file(s) removed, {$missing} already absent; history and row issues kept");
        $this->info($summary);

        if (! $dryRun && $batches->isNotEmpty()) {
            Log::info('Import files pruned', ['batches' => $batches->count(), 'removed' => $removed, 'retention_days' => $days]);
        }

        return self::SUCCESS;
    }
}
