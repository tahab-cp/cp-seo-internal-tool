<?php

namespace Tests\Feature\MonthlyCycles;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Projects\UpdateProjectAction;
use App\Enums\ProjectStatus;
use App\Exceptions\UnknownTargetKeyException;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Models\MonthlyCycle;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Lifecycle invariant: a project transitioning into ACTIVE gets its current
 * monthly cycle, ensured as the last step of the atomic update workflow.
 */
class ProjectStatusTransitionCycleTest extends TestCase
{
    use RefreshDatabase;

    protected Package $growth;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 08:00:00');

        $this->growth = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 50],
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 8],
        ])->create();
    }

    public function test_onboarding_to_active_creates_the_current_cycle(): void
    {
        $project = Project::factory()->onboarding()->withPackage($this->growth)->create();

        app(UpdateProjectAction::class)->handle($project, ['status' => ProjectStatus::Active->value]);

        $cycle = $project->monthlyCycles()->first();

        $this->assertNotNull($cycle);
        $this->assertSame(2026, $cycle->year);
        $this->assertSame(9, $cycle->month);
        $this->assertSame(['backlinks' => 50, 'blogs' => 8], $this->snapshot($cycle));
    }

    public function test_paused_to_active_creates_the_current_cycle(): void
    {
        $project = Project::factory()->paused()->withPackage($this->growth)->create();

        app(UpdateProjectAction::class)->handle($project, ['status' => ProjectStatus::Active->value]);

        $this->assertSame(1, $project->monthlyCycles()->forPeriod(new CyclePeriod(2026, 9))->count());
    }

    public function test_the_snapshot_uses_the_final_package_and_override_configuration_of_the_same_update(): void
    {
        $starter = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 20],
        ])->create();
        $project = Project::factory()->onboarding()->withPackage($starter)->create();
        $project->targetOverrides()->create(['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 15]);

        // One save: switch package, set overrides for the new package, go active.
        app(UpdateProjectAction::class)->handle(
            $project,
            ['status' => ProjectStatus::Active->value, 'package_id' => $this->growth->id],
            null,
            ['backlinks' => 40],
        );

        $cycle = $project->monthlyCycles()->first();

        $this->assertNotNull($cycle);
        $this->assertSame(['backlinks' => 40, 'blogs' => 8], $this->snapshot($cycle));
    }

    public function test_the_same_transition_works_through_the_filament_edit_form(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());
        $project = Project::factory()->onboarding()->withPackage($this->growth)->create();

        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
            ->fillForm([
                'status' => ProjectStatus::Active->value,
                'target_overrides' => [
                    ['target_key' => 'blogs', 'target_value' => 6],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['backlinks' => 50, 'blogs' => 6], $this->snapshot($project->monthlyCycles()->firstOrFail()));
    }

    public function test_active_to_active_does_not_duplicate_or_re_snapshot_the_existing_cycle(): void
    {
        $project = Project::factory()->withPackage($this->growth)->create();
        $cycle = app(CreateMonthlyCycleAction::class)->handle($project, CyclePeriod::current());
        $targetIds = $cycle->targets->pluck('id')->all();

        app(UpdateProjectAction::class)->handle(
            $project,
            ['status' => ProjectStatus::Active->value, 'name' => 'Renamed'],
            null,
            ['backlinks' => 1],
        );

        $this->assertSame('Renamed', $project->fresh()->name);
        $this->assertSame(1, $project->monthlyCycles()->count());
        $this->assertSame($targetIds, $cycle->fresh()->targets->pluck('id')->all());
        $this->assertSame(['backlinks' => 50, 'blogs' => 8], $this->snapshot($cycle->fresh()));
    }

    public function test_active_to_active_does_not_create_a_missing_cycle_either(): void
    {
        $project = Project::factory()->withPackage($this->growth)->create();

        app(UpdateProjectAction::class)->handle($project, ['status' => ProjectStatus::Active->value, 'name' => 'Renamed']);

        $this->assertSame(0, $project->monthlyCycles()->count());
    }

    public function test_leaving_active_does_not_create_another_cycle(): void
    {
        foreach ([ProjectStatus::Paused, ProjectStatus::Completed, ProjectStatus::Cancelled, ProjectStatus::Onboarding] as $status) {
            $project = Project::factory()->withPackage($this->growth)->create();
            app(CreateMonthlyCycleAction::class)->handle($project, new CyclePeriod(2026, 8));

            app(UpdateProjectAction::class)->handle($project, ['status' => $status->value]);

            $this->assertSame($status, $project->fresh()->status);
            $this->assertSame(1, $project->monthlyCycles()->count(), "Transition to {$status->value} must not create a cycle.");
            $this->assertSame(0, $project->monthlyCycles()->forPeriod(new CyclePeriod(2026, 9))->count());
        }
    }

    public function test_a_failing_update_rolls_back_the_status_change_and_the_cycle(): void
    {
        $project = Project::factory()->onboarding()->withPackage($this->growth)->create(['name' => 'Original']);

        try {
            app(UpdateProjectAction::class)->handle(
                $project,
                ['status' => ProjectStatus::Active->value, 'name' => 'Renamed'],
                null,
                ['not_a_key' => 1],
            );
            $this->fail('Expected UnknownTargetKeyException.');
        } catch (UnknownTargetKeyException) {
            $this->addToAssertionCount(1);
        }

        $fresh = $project->fresh();

        $this->assertSame('Original', $fresh->name);
        $this->assertSame(ProjectStatus::Onboarding, $fresh->status);
        $this->assertSame(0, MonthlyCycle::query()->count());
        $this->assertDatabaseCount('monthly_cycle_targets', 0);
    }

    /**
     * @return array<string, int>
     */
    protected function snapshot(MonthlyCycle $cycle): array
    {
        return $cycle->targets()->orderBy('id')->pluck('target_value', 'target_key')->all();
    }
}
