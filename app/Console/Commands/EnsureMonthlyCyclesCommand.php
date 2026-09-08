<?php

namespace App\Console\Commands;

use App\Actions\MonthlyCycles\EnsureMonthlyCycleAction;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class EnsureMonthlyCyclesCommand extends Command
{
    protected $signature = 'seo:ensure-monthly-cycles
        {--period= : Period as YYYY-MM (defaults to the current month)}
        {--chunk=100 : Projects processed per database chunk}';

    protected $description = 'Ensure every active project has a monthly cycle (with target snapshot) for the period';

    public function handle(EnsureMonthlyCycleAction $ensureMonthlyCycle): int
    {
        try {
            $period = filled($this->option('period'))
                ? CyclePeriod::fromString((string) $this->option('period'))
                : CyclePeriod::current();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $created = 0;
        $existing = 0;

        // Only active projects receive automatic cycles. Onboarding, paused,
        // completed and cancelled projects are skipped; archived projects are
        // excluded by the SoftDeletes global scope.
        Project::query()
            ->where('status', ProjectStatus::Active->value)
            ->orderBy('id')
            ->chunkById(max(1, (int) $this->option('chunk')), function (Collection $projects) use ($ensureMonthlyCycle, $period, &$created, &$existing): void {
                foreach ($projects as $project) {
                    $cycle = $ensureMonthlyCycle->handle($project, $period);

                    $cycle->wasRecentlyCreated ? $created++ : $existing++;
                }
            });

        $this->info(sprintf(
            '%s: %d cycle(s) created, %d already existed.',
            $period->label(),
            $created,
            $existing,
        ));

        return self::SUCCESS;
    }
}
