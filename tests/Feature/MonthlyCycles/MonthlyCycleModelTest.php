<?php

namespace Tests\Feature\MonthlyCycles;

use App\Enums\MonthlyCycleStatus;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class MonthlyCycleModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_project_has_many_monthly_cycles_and_a_cycle_belongs_to_its_project(): void
    {
        $project = Project::factory()->create();

        $september = MonthlyCycle::factory()->forProject($project)->forPeriod(new CyclePeriod(2026, 9))->create();
        $october = MonthlyCycle::factory()->forProject($project)->forPeriod(new CyclePeriod(2026, 10))->create();

        $this->assertSame(2, $project->monthlyCycles()->count());
        $this->assertTrue($project->monthlyCycles->contains($september));
        $this->assertTrue($september->project->is($project));
        $this->assertTrue($october->project->is($project));
        $this->assertSame('September 2026', $september->periodLabel());
    }

    public function test_only_one_cycle_may_exist_per_project_year_and_month(): void
    {
        $project = Project::factory()->create();
        MonthlyCycle::factory()->forProject($project)->forPeriod(new CyclePeriod(2026, 9))->create();

        // Same period on another project is fine.
        MonthlyCycle::factory()->forPeriod(new CyclePeriod(2026, 9))->create();

        $this->expectException(UniqueConstraintViolationException::class);

        MonthlyCycle::factory()->forProject($project)->forPeriod(new CyclePeriod(2026, 9))->create();
    }

    public function test_invalid_month_and_year_values_are_rejected(): void
    {
        foreach ([[2026, 0], [2026, 13], [2026, -1], [1999, 1], [2101, 1]] as [$year, $month]) {
            try {
                new CyclePeriod($year, $month);
                $this->fail("Expected [{$year}-{$month}] to be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);

        CyclePeriod::fromString('2026/09');
    }

    public function test_periods_parse_and_label_correctly(): void
    {
        $period = CyclePeriod::fromString('2026-09');

        $this->assertSame(2026, $period->year);
        $this->assertSame(9, $period->month);
        $this->assertSame('September 2026', $period->label());
        $this->assertTrue($period->equals(new CyclePeriod(2026, 9)));
        $this->assertFalse($period->equals(new CyclePeriod(2026, 10)));
    }

    public function test_new_cycles_start_open_with_an_enum_status_and_locked_by_relation(): void
    {
        $manager = User::factory()->seoManager()->create();
        $cycle = MonthlyCycle::factory()->create();

        $this->assertSame(MonthlyCycleStatus::Open, $cycle->fresh()->status);
        $this->assertFalse($cycle->isLocked());
        $this->assertNull($cycle->lockedBy);
        $this->assertDatabaseHas('monthly_cycles', ['id' => $cycle->id, 'status' => 'open']);

        $cycle->forceFill(['locked_by' => $manager->id])->save();

        $this->assertTrue($cycle->fresh()->lockedBy->is($manager));
    }
}
