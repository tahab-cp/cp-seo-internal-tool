<?php

namespace App\Actions\MonthlyCycles;

use App\Enums\MonthlyCycleStatus;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Services\MonthlyCycles\TargetResolver;
use App\Support\MonthlyCycles\CyclePeriod;
use App\Support\Targets\ResolvedTarget;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreateMonthlyCycleAction
{
    public function __construct(
        protected TargetResolver $targetResolver,
    ) {}

    /**
     * Create the cycle for a project/period and snapshot its targets.
     *
     * The snapshot is the project's *currently resolved* targets (package
     * defaults with project overrides applied). Later package or override
     * changes never touch these rows. A project without a package gets a
     * cycle with zero target rows.
     *
     * Uniqueness per project/year/month is guaranteed by the database; a
     * duplicate surfaces as UniqueConstraintViolationException, which
     * EnsureMonthlyCycleAction treats as "already exists".
     */
    public function handle(Project $project, CyclePeriod $period): MonthlyCycle
    {
        if (! $project->exists || $project->trashed()) {
            throw new InvalidArgumentException('Monthly cycles can only be created for existing, non-archived projects.');
        }

        return DB::transaction(function () use ($project, $period): MonthlyCycle {
            $cycle = $project->monthlyCycles()->create([
                'year' => $period->year,
                'month' => $period->month,
                'status' => MonthlyCycleStatus::Open,
                'started_at' => now(),
            ]);

            $this->targetResolver
                ->resolve($project)
                ->each(fn (ResolvedTarget $target) => $cycle->targets()->create([
                    'target_key' => $target->targetKey,
                    'label' => $target->label,
                    'target_value' => $target->resolvedValue(),
                ]));

            return $cycle->load('targets');
        });
    }
}
