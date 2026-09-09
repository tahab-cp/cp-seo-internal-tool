<?php

namespace App\Services\LegacyMigration;

use App\Actions\MonthlyCycles\CreateHistoricalMonthlyCycleAction;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Support\LegacyMigration\MigrationContext;
use App\Support\LegacyMigration\MigrationOutcome;
use App\Support\MonthlyCycles\CyclePeriod;

/**
 * Finds or creates the HISTORICAL reporting month a legacy row belongs to.
 *
 *   - an existing cycle is reused as-is (targets are never re-snapshotted)
 *   - an existing LOCKED cycle is a conflict: nothing may be written into it
 *     and it is never unlocked here
 *   - a missing cycle is created through CreateHistoricalMonthlyCycleAction
 *     with the legacy targets registered from the Targets sheet, or with
 *     NO targets plus a warning when the source has none. Today's package
 *     targets are never used for a historical month.
 */
class HistoricalCycleResolver
{
    /**
     * @var array<string, array<string, array{label?: string, target_value: int}>> "projectId|YYYY-MM" => targets
     */
    protected array $legacyTargets = [];

    /**
     * @var array<string, MonthlyCycle|false> "projectId|YYYY-MM" => cycle, false = locked conflict
     */
    protected array $cache = [];

    /**
     * @var list<string> cache keys created inside the current transaction group
     */
    protected array $createdInGroup = [];

    public function __construct(
        protected CreateHistoricalMonthlyCycleAction $createHistoricalCycle,
    ) {}

    /**
     * @param  array<string, array{label?: string, target_value: int}>  $targets
     */
    public function registerTargets(Project $project, CyclePeriod $period, array $targets): void
    {
        $this->legacyTargets[$this->key($project, $period)] = $targets;
    }

    /**
     * @return array<string, array{label?: string, target_value: int}>
     */
    public function targetsFor(Project $project, CyclePeriod $period): array
    {
        return $this->legacyTargets[$this->key($project, $period)] ?? [];
    }

    /**
     * The cycle for the period, or null when it is locked (conflict
     * already reported once per project/month).
     */
    public function resolve(MigrationContext $context, Project $project, CyclePeriod $period, string $sheet, ?int $row): ?MonthlyCycle
    {
        $key = $this->key($project, $period);

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key] ?: null;
        }

        $existing = $project->monthlyCycles()->forPeriod($period)->first();

        if ($existing !== null) {
            if ($existing->isLocked()) {
                $context->tally('cycles', MigrationOutcome::CONFLICT);
                $context->error($sheet, $row, 'cycle', sprintf('%s of "%s" is locked (finalized); rows for that month are skipped. Unlock it through the report correction workflow first.', $period->label(), $project->name));

                return ($this->cache[$key] = false) ?: null;
            }

            $context->tally('cycles', MigrationOutcome::SKIP);
            $context->info($sheet, $row, 'cycle', sprintf('%s of "%s" already exists; reused without touching its targets.', $period->label(), $project->name));

            return $this->cache[$key] = $existing;
        }

        $targets = $this->targetsFor($project, $period);
        $cycle = $this->createHistoricalCycle->handle($project, $period, $targets);

        $context->tally('cycles', MigrationOutcome::CREATE);

        if ($targets === []) {
            $context->warning($sheet, $row, 'cycle', sprintf('%s of "%s" created without target snapshot: no historical targets in the source (today\'s package targets are not applied to past months).', $period->label(), $project->name));
        } else {
            foreach ($targets as $target) {
                $context->tally('targets', MigrationOutcome::CREATE);
            }

            $context->info($sheet, $row, 'cycle', sprintf('%s of "%s" created with %d historical target(s) from the Targets sheet.', $period->label(), $project->name, count($targets)));
        }

        $this->createdInGroup[] = $key;

        return $this->cache[$key] = $cycle;
    }

    public function groupCommitted(): void
    {
        $this->createdInGroup = [];
    }

    public function groupRolledBack(): void
    {
        foreach ($this->createdInGroup as $key) {
            unset($this->cache[$key]);
        }

        $this->createdInGroup = [];
    }

    protected function key(Project $project, CyclePeriod $period): string
    {
        return $project->getKey().'|'.sprintf('%04d-%02d', $period->year, $period->month);
    }
}
