<?php

namespace Tests\Feature\MonthlyCycles;

use App\Models\MonthlyCycle;
use App\Models\Package;
use App\Models\Project;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class EnsureMonthlyCyclesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 08:00:00');
    }

    public function test_active_projects_receive_the_current_cycle_and_other_statuses_are_excluded(): void
    {
        $package = Package::factory()->withTargets()->create();

        $active = Project::factory()->withPackage($package)->create(['name' => 'Active']);
        $activeWithoutPackage = Project::factory()->create(['name' => 'Active, no package']);
        $onboarding = Project::factory()->onboarding()->withPackage($package)->create();
        $paused = Project::factory()->paused()->withPackage($package)->create();
        $completed = Project::factory()->withPackage($package)->create(['status' => 'completed']);
        $cancelled = Project::factory()->withPackage($package)->create(['status' => 'cancelled']);
        $archived = Project::factory()->withPackage($package)->create();
        $archived->delete();

        $this->artisan('seo:ensure-monthly-cycles')
            ->expectsOutputToContain('September 2026: 2 cycle(s) created, 0 already existed.')
            ->assertSuccessful();

        $this->assertSame(1, $active->monthlyCycles()->forPeriod(new CyclePeriod(2026, 9))->count());
        $this->assertSame(4, $active->monthlyCycles()->first()->targets()->count());
        $this->assertSame(1, $activeWithoutPackage->monthlyCycles()->count());
        $this->assertSame(0, $activeWithoutPackage->monthlyCycles()->first()->targets()->count());

        foreach ([$onboarding, $paused, $completed, $cancelled, $archived] as $excluded) {
            $this->assertSame(0, $excluded->monthlyCycles()->count(), "Project [{$excluded->status->value}] must not receive a cycle.");
        }

        $this->assertSame(2, MonthlyCycle::query()->count());
    }

    public function test_the_command_is_idempotent(): void
    {
        Project::factory()->count(3)->withPackage(Package::factory()->withTargets()->create())->create();

        $this->artisan('seo:ensure-monthly-cycles')
            ->expectsOutputToContain('3 cycle(s) created, 0 already existed')
            ->assertSuccessful();

        $this->artisan('seo:ensure-monthly-cycles')
            ->expectsOutputToContain('0 cycle(s) created, 3 already existed')
            ->assertSuccessful();

        $this->assertSame(3, MonthlyCycle::query()->count());
        $this->assertDatabaseCount('monthly_cycle_targets', 12);
    }

    public function test_the_command_processes_projects_in_chunks(): void
    {
        Project::factory()->count(5)->create();

        $this->artisan('seo:ensure-monthly-cycles', ['--chunk' => 2])
            ->expectsOutputToContain('5 cycle(s) created')
            ->assertSuccessful();

        $this->assertSame(5, MonthlyCycle::query()->count());
    }

    public function test_an_explicit_period_can_be_given_and_invalid_periods_are_rejected(): void
    {
        $project = Project::factory()->create();

        $this->artisan('seo:ensure-monthly-cycles', ['--period' => '2026-10'])
            ->expectsOutputToContain('October 2026: 1 cycle(s) created')
            ->assertSuccessful();

        $this->assertSame(1, $project->monthlyCycles()->forPeriod(new CyclePeriod(2026, 10))->count());

        $this->artisan('seo:ensure-monthly-cycles', ['--period' => '2026-13'])->assertFailed();
        $this->artisan('seo:ensure-monthly-cycles', ['--period' => 'next month'])->assertFailed();

        $this->assertSame(1, MonthlyCycle::query()->count());
    }

    public function test_the_command_is_scheduled_monthly(): void
    {
        $event = collect(Schedule::events())
            ->first(fn ($event): bool => str_contains($event->command ?? '', 'seo:ensure-monthly-cycles'));

        $this->assertNotNull($event, 'seo:ensure-monthly-cycles is not scheduled.');
        $this->assertSame('5 0 1 * *', $event->expression);

        $this->assertSame(0, Artisan::call('schedule:list'));
        $this->assertStringContainsString('seo:ensure-monthly-cycles', Artisan::output());
    }
}
