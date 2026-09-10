<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Projects\Actions\ArchiveAction;
use App\Filament\Resources\Projects\Concerns\HasProjectWorkspace;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Models\User;
use App\Services\Dashboard\ProjectOperationsService;
use App\Services\MonthlyCycles\TargetProgressService;
use App\Services\MonthlyCycles\TargetResolver;
use App\Support\Dashboard\ProjectOperationsRow;
use App\Support\MonthlyCycles\CyclePeriod;
use App\Support\Targets\ResolvedTarget;
use App\Support\Targets\TargetCompletionRow;
use App\Support\Targets\TargetProgress;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The project workspace overview. Presentation only: every figure comes
 * from the existing services (ProjectOperationsService, TargetProgress-
 * Service, TargetResolver); nothing is calculated here or in the view.
 * Administrative actions stay in the page header; the operational
 * modules are a navigation grid in the body.
 */
class ViewProject extends ViewRecord
{
    use HasProjectWorkspace;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.pages.view-project';

    protected ?ProjectOperationsRow $operations = null;

    public function getTitle(): string
    {
        return $this->getProject()->name;
    }

    public function getSubheading(): ?string
    {
        return $this->getWorkspaceSubheading();
    }

    public function getProject(): Project
    {
        /** @var Project $project */
        $project = $this->getRecord();

        return $project;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->hidden(fn (Project $record): bool => $record->trashed()),
            ArchiveAction::make(),
            RestoreAction::make()
                ->successNotificationTitle('Project restored'),
        ];
    }

    public function getPeriod(): CyclePeriod
    {
        return CyclePeriod::current();
    }

    public function getOperations(): ProjectOperationsRow
    {
        return $this->operations ??= app(ProjectOperationsService::class)->rowFor($this->getProject(), $this->getPeriod());
    }

    /**
     * Deliverable progress for the current cycle: the existing
     * TargetProgress figures per supported target, paired with the matching
     * Monthly Target Completion row (for its capped contribution).
     *
     * @return list<array{progress: TargetProgress, completion: ?TargetCompletionRow}>
     */
    public function getDeliverables(): array
    {
        $cycle = $this->getOperations()->cycle;

        if ($cycle === null) {
            return [];
        }

        $service = app(TargetProgressService::class);
        $rows = $this->getOperations()->completion?->rows ?? new Collection;

        return array_map(fn (string $key): array => [
            'progress' => $service->progressFor($cycle, $key),
            'completion' => $rows->first(fn (TargetCompletionRow $row): bool => $row->targetKey === $key),
        ], TargetProgressService::supportedTargetKeys());
    }

    /**
     * @return Collection<int, ResolvedTarget>
     */
    public function getResolvedTargets(): Collection
    {
        return app(TargetResolver::class)->resolve($this->getProject());
    }

    public function getClientUrl(): ?string
    {
        $project = $this->getProject();

        return $project->client && Filament::auth()->user()?->can('view', $project->client)
            ? ClientResource::getUrl('view', ['record' => $project->client])
            : null;
    }

    public function canEnsureCycle(): bool
    {
        return Gate::allows('ensureMonthlyCycle', $this->getProject());
    }

    /**
     * @return Collection<int, User>
     */
    public function getTeamMembers(): Collection
    {
        return $this->getProject()->teamMembers()->with('roles')->orderBy('name')->get();
    }

    public function moduleUrl(string $page, array $extra = []): string
    {
        return ProjectResource::getUrl($page, ['record' => $this->getProject()] + $extra);
    }
}
