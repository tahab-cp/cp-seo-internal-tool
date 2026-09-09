<?php

namespace App\Services\Imports;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Exceptions\CsvImportException;
use App\Models\ImportBatch;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\User;
use App\Support\Imports\CsvDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Steps 1-3 of the wizard: resolve the target through project visibility,
 * store the CSV, create the batch and record the column mapping. Nothing
 * here touches domain data.
 */
class ImportUploadService
{
    public function __construct(
        protected CsvReader $reader,
        protected ImportFileStore $files,
        protected CsvColumnMapper $mapper,
        protected ImporterRegistry $importers,
    ) {}

    /**
     * The project the user may import into, or an exception. A crafted id
     * for a project outside the user's visibility is treated as unknown.
     */
    public function resolveProject(User $user, int|string|null $projectId): Project
    {
        $project = filled($projectId)
            ? Project::query()->accessibleBy($user)->whereKey((int) $projectId)->first()
            : null;

        if ($project === null || ! Gate::forUser($user)->allows('view', $project)) {
            throw new CsvImportException('Choose a project you have access to.');
        }

        return $project;
    }

    /**
     * The cycle to import into: must belong to the resolved project and
     * must not be locked (server-side, whoever the user is). Returns null
     * for import types that are not month-scoped.
     */
    public function resolveCycle(Project $project, ImportType $type, int|string|null $cycleId): ?MonthlyCycle
    {
        if (! $type->requiresCycle()) {
            return null;
        }

        $cycle = filled($cycleId) ? $project->monthlyCycles()->whereKey((int) $cycleId)->first() : null;

        if ($cycle === null) {
            throw new CsvImportException('Choose a reporting month of the selected project.');
        }

        if ($cycle->isLocked()) {
            throw new CsvImportException(sprintf('%s is locked (finalized); imports into it are not allowed. A Super Admin must unlock the month first.', $cycle->periodLabel()));
        }

        return $cycle;
    }

    /**
     * Validates and stores the upload and opens the batch (status uploaded).
     */
    public function upload(User $user, ImportType $type, Project $project, ?MonthlyCycle $cycle, UploadedFile $file): ImportBatch
    {
        if (! Gate::forUser($user)->allows('create', ImportBatch::class) || ! Gate::forUser($user)->allows('view', $project)) {
            throw new CsvImportException('You may not import into this project.');
        }

        if ($type->requiresCycle() && ($cycle === null || (int) $cycle->project_id !== (int) $project->getKey())) {
            throw new CsvImportException('Choose a reporting month of the selected project.');
        }

        $document = $this->reader->inspectUpload($file);

        return DB::transaction(function () use ($user, $type, $project, $cycle, $file, $document): ImportBatch {
            $batch = new ImportBatch([
                'import_type' => $type,
                'original_filename' => $this->files->safeOriginalName($file),
                'status' => ImportStatus::Uploaded,
                'total_rows' => $document->rowCount(),
                'mapping_json' => $this->mapper->suggest($document->headers, $this->importers->for($type)->fields()),
            ]);

            $batch->project_id = $project->getKey();
            $batch->monthly_cycle_id = $cycle?->getKey();
            $batch->created_by = $user->getKey();
            $batch->save();

            $batch->stored_file_path = $this->files->store($file, $batch);
            $batch->save();

            return $batch;
        });
    }

    public function document(ImportBatch $batch): CsvDocument
    {
        return $this->reader->readPath($this->files->localPath($batch));
    }

    /**
     * Records the user's column mapping after checking it against the
     * file's headers and the type's required fields. Resets a previous
     * validation because the mapping changed.
     *
     * @param  array<string, mixed>  $mapping
     */
    public function saveMapping(ImportBatch $batch, array $mapping): ImportBatch
    {
        if ($batch->isCompleted()) {
            throw new CsvImportException('This import has already been completed.');
        }

        $document = $this->document($batch);
        $clean = $this->mapper->validate($mapping, $document->headers, $this->importers->for($batch->import_type)->fields());

        $batch->mapping_json = $clean;
        $batch->status = ImportStatus::Uploaded;
        $batch->valid_rows = 0;
        $batch->failed_rows = 0;
        $batch->save();

        $batch->rowErrors()->delete();

        return $batch;
    }
}
