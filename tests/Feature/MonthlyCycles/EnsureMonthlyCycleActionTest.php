<?php

namespace Tests\Feature\MonthlyCycles;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\MonthlyCycles\EnsureMonthlyCycleAction;
use App\Models\MonthlyCycle;
use App\Models\Package;
use App\Models\Project;
use App\Services\MonthlyCycles\TargetResolver;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EnsureMonthlyCycleActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_ensure_creates_a_missing_cycle_with_its_snapshot(): void
    {
        $project = Project::factory()->withPackage(Package::factory()->withTargets()->create())->create();

        $cycle = app(EnsureMonthlyCycleAction::class)->handle($project, new CyclePeriod(2026, 9));

        $this->assertTrue($cycle->wasRecentlyCreated);
        $this->assertSame(1, $project->monthlyCycles()->count());
        $this->assertSame(4, $cycle->targets()->count());
    }

    public function test_ensure_defaults_to_the_current_period(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $project = Project::factory()->create();

        $cycle = app(EnsureMonthlyCycleAction::class)->handle($project);

        $this->assertSame(2026, $cycle->year);
        $this->assertSame(9, $cycle->month);
    }

    public function test_ensure_twice_returns_the_same_cycle_without_duplicating_targets(): void
    {
        $project = Project::factory()->withPackage(Package::factory()->withTargets()->create())->create();
        $period = new CyclePeriod(2026, 9);

        $first = app(EnsureMonthlyCycleAction::class)->handle($project, $period);
        $second = app(EnsureMonthlyCycleAction::class)->handle($project, $period);

        $this->assertTrue($first->wasRecentlyCreated);
        $this->assertFalse($second->wasRecentlyCreated);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, MonthlyCycle::query()->count());
        $this->assertSame(4, $second->targets()->count());
        $this->assertDatabaseCount('monthly_cycle_targets', 4);
    }

    public function test_ensure_returns_the_existing_row_when_it_loses_a_creation_race(): void
    {
        $project = Project::factory()->create();
        $period = new CyclePeriod(2026, 9);

        // Simulate another process inserting the cycle between the existence
        // check and the insert: the create action collides on the unique key.
        $creator = new class($project, $period) extends CreateMonthlyCycleAction
        {
            public function __construct(private Project $project, private CyclePeriod $period)
            {
                parent::__construct(app(TargetResolver::class));
            }

            public function handle(Project $project, CyclePeriod $period): MonthlyCycle
            {
                MonthlyCycle::factory()->forProject($this->project)->forPeriod($this->period)->create();

                return parent::handle($project, $period);
            }
        };

        $cycle = (new EnsureMonthlyCycleAction($creator))->handle($project, $period);

        $this->assertFalse($cycle->wasRecentlyCreated);
        $this->assertSame(1, $project->monthlyCycles()->count());
    }

    public function test_the_database_rejects_a_duplicate_period_outside_the_action(): void
    {
        $project = Project::factory()->create();
        app(EnsureMonthlyCycleAction::class)->handle($project, new CyclePeriod(2026, 9));

        $this->expectException(UniqueConstraintViolationException::class);

        app(CreateMonthlyCycleAction::class)->handle($project, new CyclePeriod(2026, 9));
    }
}
