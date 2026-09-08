<?php

namespace App\Actions\MonthlyCycles;

use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Database\UniqueConstraintViolationException;

class EnsureMonthlyCycleAction
{
    public function __construct(
        protected CreateMonthlyCycleAction $createMonthlyCycle,
    ) {}

    /**
     * Return the project's cycle for the period, creating it only if missing.
     *
     * Idempotent: an existing cycle is returned as-is; it is never recreated,
     * its targets are never re-snapshotted and its historical rows are never
     * mutated. A concurrent creator losing the unique-key race simply
     * receives the row the other creator inserted.
     */
    public function handle(Project $project, ?CyclePeriod $period = null): MonthlyCycle
    {
        $period ??= CyclePeriod::current();

        $existing = $this->find($project, $period);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->createMonthlyCycle->handle($project, $period);
        } catch (UniqueConstraintViolationException) {
            return $this->find($project, $period)
                ?? throw new \RuntimeException('Monthly cycle creation collided but the existing cycle could not be loaded.');
        }
    }

    protected function find(Project $project, CyclePeriod $period): ?MonthlyCycle
    {
        return $project->monthlyCycles()
            ->forPeriod($period)
            ->with('targets')
            ->first();
    }
}
