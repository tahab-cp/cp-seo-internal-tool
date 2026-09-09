<?php

namespace Tests\Support;

use App\Enums\ImportType;
use App\Models\ImportBatch;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\User;
use App\Services\Imports\ImportExecutionService;
use App\Services\Imports\ImportUploadService;
use App\Services\Imports\ImportValidationService;
use App\Support\Imports\ValidationReport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Drives the import services the way the wizard does: upload → (mapping)
 * → validate → execute. Files go to a faked private disk.
 */
trait RunsCsvImports
{
    protected function fakeImportDisk(): void
    {
        Storage::fake(config('imports.disk'));
    }

    protected function csvFile(string $content, string $name = 'import.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    /**
     * Uploads and validates without importing. Returns [batch, report].
     *
     * @return array{0: ImportBatch, 1: ValidationReport}
     */
    protected function validateCsv(User $user, ImportType $type, Project $project, ?MonthlyCycle $cycle, string $csv, ?array $mapping = null, string $name = 'import.csv'): array
    {
        $uploads = app(ImportUploadService::class);
        $batch = $uploads->upload($user, $type, $project, $cycle, $this->csvFile($csv, $name));

        if ($mapping !== null) {
            $batch = $uploads->saveMapping($batch, $mapping);
        }

        $report = app(ImportValidationService::class)->validate($batch, $user);

        return [$batch->refresh(), $report];
    }

    /**
     * Full run: upload, validate and execute. Returns the refreshed batch.
     */
    protected function importCsv(User $user, ImportType $type, Project $project, ?MonthlyCycle $cycle, string $csv, ?array $mapping = null, string $name = 'import.csv'): ImportBatch
    {
        [$batch] = $this->validateCsv($user, $type, $project, $cycle, $csv, $mapping, $name);

        return app(ImportExecutionService::class)->execute($batch, $user)->refresh();
    }
}
