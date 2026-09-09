<?php

namespace App\Services\Pages;

use App\Exceptions\UnauthorizedProjectUserException;
use App\Models\MonthlyCycle;
use App\Models\Page;
use App\Models\PageOptimization;
use App\Models\Project;
use App\Models\User;
use App\Services\ActiveUserGuard;
use App\Services\MonthlyCycles\Concerns\LocksMonthlyCycles;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Domain rules every page-optimisation workflow must respect, independent
 * of any form:
 *
 * - the page and the cycle must belong to the same project as the event;
 * - locked cycles are immutable;
 * - the recording user must be active and able to access the project;
 * - an event must record at least one real change.
 */
class PageOptimizationGuard
{
    use LocksMonthlyCycles;

    public function __construct(
        protected ActiveUserGuard $activeUsers,
    ) {}

    /**
     * Resolve a page id to a live page of *this* project.
     */
    public function resolvePage(Project $project, int|string|null $pageId): Page
    {
        $page = filled($pageId) ? Page::query()->find((int) $pageId) : null;

        if ($page === null || (int) $page->project_id !== (int) $project->getKey()) {
            throw new InvalidArgumentException(sprintf(
                'Page [%s] does not belong to project "%s".',
                $pageId ?? 'none',
                $project->name,
            ));
        }

        return $page;
    }

    /**
     * Resolve a cycle id to a cycle of *this* project. Required: every
     * optimisation event is monthly data.
     */
    public function resolveCycle(Project $project, int|string|null $cycleId): MonthlyCycle
    {
        $cycle = filled($cycleId) ? MonthlyCycle::query()->find((int) $cycleId) : null;

        if ($cycle === null || (int) $cycle->project_id !== (int) $project->getKey()) {
            throw new InvalidArgumentException(sprintf(
                'Monthly cycle [%s] does not belong to project "%s".',
                $cycleId ?? 'none',
                $project->name,
            ));
        }

        return $cycle;
    }

    /**
     * Row-lock the cycle (inside the caller's transaction) and refuse if it
     * is locked.
     */
    public function ensureCycleNotLocked(MonthlyCycle $cycle, string $operation): void
    {
        $this->lockCycle($cycle, $operation);
    }

    public function ensureOptimizationNotLocked(PageOptimization $optimization, string $operation): void
    {
        $this->lockCycle($optimization->monthly_cycle_id, $operation);
    }

    /**
     * Removed pages are not casually chosen for new work; reactivate first.
     */
    public function ensurePageOptimisable(Page $page): void
    {
        if ($page->isRemoved()) {
            throw new InvalidArgumentException("Page \"{$page->url}\" is marked removed; reactivate it before recording new optimisation work.");
        }
    }

    /**
     * The recording user must be an active user who can access the project.
     * Project access is the single source of truth (ProjectPolicy::view).
     */
    public function ensureRecordedBy(Project $project, int|string|null $userId): void
    {
        if ($userId === null || $userId === '') {
            return;
        }

        $this->activeUsers->ensureActive([$userId], 'the optimising user');

        $user = User::query()->findOrFail((int) $userId);

        if (! Gate::forUser($user)->allows('view', $project)) {
            throw UnauthorizedProjectUserException::for($user, $project, 'the optimising user');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, bool> the five flags, normalised
     */
    public function normaliseChanges(array $attributes, ?PageOptimization $existing = null): array
    {
        $flags = [];

        foreach (array_keys(PageOptimization::CHANGE_FLAGS) as $flag) {
            $flags[$flag] = array_key_exists($flag, $attributes)
                ? filter_var($attributes[$flag], FILTER_VALIDATE_BOOLEAN)
                : (bool) ($existing?->{$flag} ?? false);
        }

        if (! in_array(true, $flags, true)) {
            throw new InvalidArgumentException('A page optimisation must record at least one change (meta title, meta description, content, internal links or schema). Notes alone do not count.');
        }

        return $flags;
    }
}
