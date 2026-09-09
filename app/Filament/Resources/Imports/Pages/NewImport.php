<?php

namespace App\Filament\Resources\Imports\Pages;

use App\Enums\ImportType;
use App\Exceptions\CsvImportException;
use App\Exceptions\LockedMonthlyCycleException;
use App\Filament\Resources\Imports\ImportBatchResource;
use App\Models\ImportBatch;
use App\Models\ImportRowError;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\User;
use App\Services\Imports\ImporterRegistry;
use App\Services\Imports\ImportExecutionService;
use App\Services\Imports\ImportUploadService;
use App\Services\Imports\ImportValidationService;
use App\Support\Imports\ImportField;
use App\Support\Imports\ValidationReport;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Livewire\WithFileUploads;

/**
 * The staged import wizard. The page only holds wizard state and hands
 * every step to the import services: nothing is parsed, validated or
 * written here, and nothing is written to the domain before step 5.
 *
 *   1 context (type, project, month)  2 upload  3 map columns
 *   4 validate / preview              5 import  → result
 */
class NewImport extends Page
{
    use WithFileUploads;

    protected static string $resource = ImportBatchResource::class;

    protected static ?string $title = 'New import';

    protected string $view = 'filament.resources.imports.pages.new-import';

    public int $step = 1;

    public ?string $importType = null;

    public ?string $projectId = null;

    public ?string $monthlyCycleId = null;

    /** @var UploadedFile|null */
    public $file = null;

    public ?int $batchId = null;

    /** @var array<string, string> */
    public array $mapping = [];

    protected ?ValidationReport $report = null;

    public static function canAccess(array $parameters = []): bool
    {
        return static::getResource()::canCreate();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $type = request()->query('type');
        $project = request()->query('project');

        if (is_string($type) && ImportType::tryFrom($type) !== null) {
            $this->importType = $type;
        }

        if (is_string($project) && (int) $project > 0 && $this->accessibleProjects()->whereKey((int) $project)->exists()) {
            $this->projectId = (string) (int) $project;
        }
    }

    protected function currentUser(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }

    protected function accessibleProjects()
    {
        return Project::query()->accessibleBy($this->currentUser());
    }

    /**
     * @return array<int, string>
     */
    public function getProjectOptions(): array
    {
        return $this->accessibleProjects()->with('client')->orderBy('name')->get()
            ->mapWithKeys(fn (Project $p): array => [(int) $p->getKey() => $p->name.($p->client ? ' — '.$p->client->name : '')])
            ->all();
    }

    /**
     * Unlocked months of the chosen project; locked months are not offered
     * (and are refused server-side anyway).
     *
     * @return array<int, string>
     */
    public function getCycleOptions(): array
    {
        $project = filled($this->projectId) ? $this->accessibleProjects()->whereKey((int) $this->projectId)->first() : null;

        if ($project === null) {
            return [];
        }

        return $project->monthlyCycles()->latestPeriodFirst()->get()
            ->reject(fn (MonthlyCycle $cycle): bool => $cycle->isLocked())
            ->mapWithKeys(fn (MonthlyCycle $cycle): array => [(int) $cycle->getKey() => $cycle->periodLabel()])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function getTypeOptions(): array
    {
        return collect(ImportType::cases())->mapWithKeys(fn (ImportType $t): array => [$t->value => $t->getLabel()])->all();
    }

    public function getSelectedType(): ?ImportType
    {
        return $this->importType === null ? null : ImportType::tryFrom($this->importType);
    }

    public function requiresCycle(): bool
    {
        return $this->getSelectedType()?->requiresCycle() ?? false;
    }

    public function updatedProjectId(): void
    {
        $this->monthlyCycleId = null;
    }

    public function updatedImportType(): void
    {
        if (! $this->requiresCycle()) {
            $this->monthlyCycleId = null;
        }
    }

    public function getBatch(): ?ImportBatch
    {
        if ($this->batchId === null) {
            return null;
        }

        return ImportBatchResource::getEloquentQuery()->whereKey($this->batchId)->first();
    }

    /**
     * @return list<ImportField>
     */
    public function getFields(): array
    {
        $type = $this->getSelectedType();

        return $type === null ? [] : app(ImporterRegistry::class)->for($type)->fields();
    }

    /**
     * @return list<string>
     */
    public function getHeaders(): array
    {
        $batch = $this->getBatch();

        if ($batch === null) {
            return [];
        }

        try {
            return app(ImportUploadService::class)->document($batch)->headers;
        } catch (CsvImportException) {
            return [];
        }
    }

    public function getReport(): ?ValidationReport
    {
        return $this->report;
    }

    /**
     * @return Collection<int, ImportRowError>
     */
    public function getStoredIssues(): Collection
    {
        return $this->getBatch()?->rowErrors()->limit(200)->get() ?? new Collection;
    }

    // ---- Step 1 -----------------------------------------------------------

    public function chooseContext(): void
    {
        $this->run(function (): void {
            $type = $this->getSelectedType() ?? throw new CsvImportException('Choose what the CSV contains.');
            $uploads = app(ImportUploadService::class);
            $project = $uploads->resolveProject($this->currentUser(), $this->projectId);
            $uploads->resolveCycle($project, $type, $this->monthlyCycleId);

            $this->step = 2;
        });
    }

    // ---- Step 2 -----------------------------------------------------------

    public function upload(): void
    {
        $this->run(function (): void {
            if (! $this->file instanceof UploadedFile) {
                throw new CsvImportException('Choose a CSV file to upload.');
            }

            $type = $this->getSelectedType() ?? throw new CsvImportException('Choose what the CSV contains.');
            $uploads = app(ImportUploadService::class);
            $project = $uploads->resolveProject($this->currentUser(), $this->projectId);
            $cycle = $uploads->resolveCycle($project, $type, $this->monthlyCycleId);

            $batch = $uploads->upload($this->currentUser(), $type, $project, $cycle, $this->file);

            $this->batchId = (int) $batch->getKey();
            $this->mapping = $batch->mapping();
            $this->file = null;
            $this->step = 3;
        });
    }

    // ---- Step 3 -----------------------------------------------------------

    public function saveMapping(): void
    {
        $this->run(function (): void {
            $batch = $this->getBatch() ?? throw new CsvImportException('Upload a file first.');

            $batch = app(ImportUploadService::class)->saveMapping($batch, $this->mapping);

            $this->mapping = $batch->mapping();
            $this->report = app(ImportValidationService::class)->validate($batch, $this->currentUser());
            $this->step = 4;
        });
    }

    public function backToMapping(): void
    {
        $this->report = null;
        $this->step = 3;
    }

    // ---- Step 4 → 5 ---------------------------------------------------------

    public function import(): void
    {
        $this->run(function (): void {
            $batch = $this->getBatch() ?? throw new CsvImportException('Upload a file first.');

            $batch = app(ImportExecutionService::class)->execute($batch, $this->currentUser());

            $this->report = null;
            $this->step = 5;

            if ($batch->isCompleted()) {
                Notification::make()->title(sprintf('%s row(s) imported', number_format($batch->imported_rows)))->success()->send();
            } else {
                Notification::make()->title('The import failed and nothing was written')->danger()->send();
            }
        });
    }

    public function startOver(): void
    {
        $this->reset('step', 'file', 'batchId', 'mapping', 'monthlyCycleId');
        $this->report = null;
        $this->step = 1;
    }

    protected function run(callable $callback): void
    {
        try {
            $callback();
        } catch (CsvImportException|InvalidArgumentException|LockedMonthlyCycleException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->persistent()->send();
        }
    }

    /**
     * Re-evaluate the stored batch for step 4 after a Livewire round trip
     * (the report itself is not a Livewire property).
     */
    public function hydrate(): void
    {
        if ($this->step === 4 && $this->report === null && $this->batchId !== null) {
            $batch = $this->getBatch();

            if ($batch !== null && $batch->isValidated()) {
                try {
                    $this->report = app(ImportValidationService::class)->check($batch, $this->currentUser());
                } catch (CsvImportException|InvalidArgumentException) {
                    $this->report = null;
                }
            }
        }
    }
}
