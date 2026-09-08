<?php

namespace Tests\Feature\MonthlyCycles;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\MonthlyCycles\EnsureMonthlyCycleAction;
use App\Actions\Packages\SyncPackageTargetsAction;
use App\Actions\Projects\ChangeProjectPackageAction;
use App\Actions\Projects\SyncProjectTargetOverridesAction;
use App\Enums\MonthlyCycleStatus;
use App\Models\MonthlyCycle;
use App\Models\Package;
use App\Models\Project;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Target snapshots: what is captured at creation, and that it never changes.
 */
class MonthlyCycleSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected Package $growth;

    protected function setUp(): void
    {
        parent::setUp();

        $this->growth = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 50],
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 8],
            ['target_key' => 'guest_posts', 'label' => 'Guest Posts', 'target_value' => 8],
            ['target_key' => 'pages_optimized', 'label' => 'Pages Optimised', 'target_value' => 8],
        ])->create(['name' => 'Growth+']);
    }

    public function test_package_targets_snapshot_correctly_and_the_cycle_starts_open(): void
    {
        $project = Project::factory()->withPackage($this->growth)->create();

        $cycle = app(CreateMonthlyCycleAction::class)->handle($project, new CyclePeriod(2026, 9));

        $this->assertSame(MonthlyCycleStatus::Open, $cycle->status);
        $this->assertNotNull($cycle->started_at);
        $this->assertSame(2026, $cycle->year);
        $this->assertSame(9, $cycle->month);
        $this->assertSame(
            ['backlinks' => 50, 'blogs' => 8, 'guest_posts' => 8, 'pages_optimized' => 8],
            $this->snapshot($cycle),
        );
        $this->assertSame('Pages Optimised', $cycle->targets->firstWhere('target_key', 'pages_optimized')->label);
    }

    public function test_project_override_wins_in_the_snapshot(): void
    {
        $casa = Project::factory()->withPackage($this->growth)->create(['name' => 'Casa Botanica']);
        app(SyncProjectTargetOverridesAction::class)->handle($casa, ['backlinks' => 40, 'guest_posts' => 6]);

        $cycle = app(CreateMonthlyCycleAction::class)->handle($casa, new CyclePeriod(2026, 9));

        $this->assertSame(
            ['backlinks' => 40, 'blogs' => 8, 'guest_posts' => 6, 'pages_optimized' => 8],
            $this->snapshot($cycle),
        );
    }

    public function test_a_project_without_a_package_gets_a_cycle_with_zero_targets(): void
    {
        $project = Project::factory()->create();

        $cycle = app(CreateMonthlyCycleAction::class)->handle($project, new CyclePeriod(2026, 9));

        $this->assertTrue($cycle->exists);
        $this->assertSame(0, $cycle->targets()->count());
        $this->assertSame([], $this->snapshot($cycle));
    }

    public function test_archived_projects_cannot_receive_cycles(): void
    {
        $project = Project::factory()->create();
        $project->delete();

        $this->expectException(InvalidArgumentException::class);

        app(CreateMonthlyCycleAction::class)->handle($project, new CyclePeriod(2026, 9));
    }

    public function test_package_target_changes_do_not_mutate_an_existing_cycle(): void
    {
        $project = Project::factory()->withPackage($this->growth)->create();
        $september = app(CreateMonthlyCycleAction::class)->handle($project, new CyclePeriod(2026, 9));

        app(SyncPackageTargetsAction::class)->handle($this->growth, [
            ['target_key' => 'backlinks', 'label' => 'Backlinks (renamed)', 'target_value' => 99],
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 1],
            // guest_posts and pages_optimized removed entirely.
        ]);

        $this->assertSame(
            ['backlinks' => 50, 'blogs' => 8, 'guest_posts' => 8, 'pages_optimized' => 8],
            $this->snapshot($september->fresh()),
        );
        $this->assertSame('Backlinks', $september->fresh()->targets->firstWhere('target_key', 'backlinks')->label);

        // The next period picks up the new configuration.
        $october = app(CreateMonthlyCycleAction::class)->handle($project, new CyclePeriod(2026, 10));

        $this->assertSame(['backlinks' => 99, 'blogs' => 1], $this->snapshot($october));
    }

    public function test_project_override_changes_do_not_mutate_an_existing_cycle(): void
    {
        $project = Project::factory()->withPackage($this->growth)->create();
        app(SyncProjectTargetOverridesAction::class)->handle($project, ['backlinks' => 40]);
        $september = app(CreateMonthlyCycleAction::class)->handle($project, new CyclePeriod(2026, 9));

        app(SyncProjectTargetOverridesAction::class)->handle($project, ['backlinks' => 45, 'blogs' => 3]);

        $this->assertSame(
            ['backlinks' => 40, 'blogs' => 8, 'guest_posts' => 8, 'pages_optimized' => 8],
            $this->snapshot($september->fresh()),
        );

        $october = app(CreateMonthlyCycleAction::class)->handle($project, new CyclePeriod(2026, 10));

        $this->assertSame(
            ['backlinks' => 45, 'blogs' => 3, 'guest_posts' => 8, 'pages_optimized' => 8],
            $this->snapshot($october),
        );
    }

    public function test_project_package_changes_do_not_mutate_an_existing_cycle(): void
    {
        $starter = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 20],
        ])->create();
        $project = Project::factory()->withPackage($this->growth)->create();
        $september = app(CreateMonthlyCycleAction::class)->handle($project, new CyclePeriod(2026, 9));

        app(ChangeProjectPackageAction::class)->handle($project, $starter->id);

        $this->assertSame(
            ['backlinks' => 50, 'blogs' => 8, 'guest_posts' => 8, 'pages_optimized' => 8],
            $this->snapshot($september->fresh()),
        );

        $october = app(CreateMonthlyCycleAction::class)->handle($project->fresh(), new CyclePeriod(2026, 10));

        $this->assertSame(['backlinks' => 20], $this->snapshot($october));
    }

    public function test_ensure_never_re_snapshots_an_existing_cycle(): void
    {
        $project = Project::factory()->withPackage($this->growth)->create();
        $period = new CyclePeriod(2026, 9);

        $first = app(EnsureMonthlyCycleAction::class)->handle($project, $period);
        $targetIds = $first->targets->pluck('id')->all();

        app(SyncProjectTargetOverridesAction::class)->handle($project, ['backlinks' => 1]);

        $second = app(EnsureMonthlyCycleAction::class)->handle($project, $period);

        $this->assertTrue($second->is($first));
        $this->assertSame($targetIds, $second->targets->pluck('id')->all());
        $this->assertSame(50, $second->targets->firstWhere('target_key', 'backlinks')->target_value);
        $this->assertSame(1, $project->monthlyCycles()->count());
        $this->assertDatabaseCount('monthly_cycle_targets', 4);
    }

    /**
     * @return array<string, int>
     */
    protected function snapshot(MonthlyCycle $cycle): array
    {
        return $cycle->targets()->orderBy('id')->pluck('target_value', 'target_key')->all();
    }
}
