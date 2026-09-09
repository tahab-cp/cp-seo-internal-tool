<?php

namespace Tests\Feature\LegacyMigration;

use App\Actions\Projects\CreateMigratedProjectAction;
use App\Actions\Projects\CreateProjectAction;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\MonthlyCycle;
use App\Models\MonthlyCycleTarget;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RunsLegacyMigrations;
use Tests\TestCase;

/**
 * Legacy migration creates the Project and its master configuration, but
 * only the months the source explicitly represents: no current-cycle or
 * onboarding side effects of the product's CreateProjectAction.
 */
class LegacyProjectCreationTest extends TestCase
{
    use RefreshDatabase;
    use RunsLegacyMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLegacyFixtureUsers();
    }

    public function test_importing_an_active_historical_project_does_not_create_todays_monthly_cycle(): void
    {
        $this->migrate(dryRun: false);

        $current = CyclePeriod::current();
        $this->assertSame('2026-09', sprintf('%04d-%02d', $current->year, $current->month));

        foreach (Project::query()->get() as $project) {
            $this->assertSame(ProjectStatus::Active, $project->status, $project->name);
            $this->assertNull($project->monthlyCycles()->forPeriod($current)->first(), "{$project->name} must not get today's cycle as a side effect");
        }

        $this->assertSame(0, MonthlyCycle::query()->where('year', 2026)->where('month', 9)->count());
    }

    public function test_only_explicitly_represented_historical_periods_are_created(): void
    {
        $this->migrate(dryRun: false);

        $periods = MonthlyCycle::query()->with('project')->get()
            ->map(fn (MonthlyCycle $c): string => $c->project->name.' '.sprintf('%04d-%02d', $c->year, $c->month))
            ->sort()->values()->all();

        // Garden: July (targets, rankings, backlinks, analytics, notes…) and August (rankings, content).
        // Plumbing: July and August ranking observations only. Bright Dental: no monthly data at all.
        $this->assertSame([
            'Acme Garden Centre 2026-07', 'Acme Garden Centre 2026-08',
            'Acme Plumbing 2026-07', 'Acme Plumbing 2026-08',
        ], $periods);
        $this->assertSame(0, Project::query()->where('name', 'Bright Dental Clinic')->firstOrFail()->monthlyCycles()->count());
    }

    public function test_todays_package_targets_are_never_snapshotted_as_a_migration_side_effect(): void
    {
        $this->migrate(dryRun: false);

        // The only snapshot is the explicit legacy July snapshot of the garden project (8 / 2 / 4 / 3).
        $this->assertSame(4, MonthlyCycleTarget::query()->count());
        $this->assertSame([2, 3, 4, 8], MonthlyCycleTarget::query()->pluck('target_value')->map(fn ($v) => (int) $v)->sort()->values()->all());
        $this->assertSame(0, MonthlyCycleTarget::query()->whereIn('target_value', [10, 6, 5])->count(), 'none of today\'s Growth package values (10/3/6/5) appear');
        $this->assertSame(0, MonthlyCycleTarget::query()->where('target_key', 'backlinks')->where('target_value', 10)->count());

        $garden = Project::query()->where('name', 'Acme Garden Centre')->firstOrFail();
        $this->assertSame($this->growth->id, $garden->package_id, 'the package itself IS assigned (master configuration)');
        $this->assertSame(0, $garden->monthlyCycles()->forPeriod(new CyclePeriod(2026, 8))->firstOrFail()->targets()->count());
    }

    public function test_normal_create_project_action_still_creates_the_current_cycle_with_todays_targets(): void
    {
        $client = Client::factory()->create();

        $project = app(CreateProjectAction::class)->handle([
            'client_id' => $client->id, 'name' => 'Product-created', 'website_url' => 'https://product.example', 'status' => ProjectStatus::Active, 'package_id' => $this->growth->id,
        ]);

        $current = $project->monthlyCycles()->forPeriod(CyclePeriod::current())->first();
        $this->assertNotNull($current, 'the product path is unchanged');
        $this->assertSame(['backlinks' => 10, 'guest_posts' => 3, 'blogs' => 6, 'pages_optimized' => 5], $current->targets->pluck('target_value', 'target_key')->map(fn ($v) => (int) $v)->all());

        // The migration path with the same input: same master data, no cycle.
        $migrated = app(CreateMigratedProjectAction::class)->handle([
            'client_id' => $client->id, 'name' => 'Migration-created', 'website_url' => 'https://migrated.example', 'status' => ProjectStatus::Active, 'package_id' => $this->growth->id, 'primary_seo_user_id' => $this->manager->id,
        ], [$this->executive->id]);

        $this->assertSame($this->growth->id, $migrated->package_id);
        $this->assertTrue($migrated->hasTeamMember($this->executive));
        $this->assertSame($this->manager->id, $migrated->primary_seo_user_id);
        $this->assertGreaterThan(0, $migrated->reportSections()->count(), 'report-section configuration is master data and is ensured');
        $this->assertSame(0, $migrated->monthlyCycles()->count());
        $this->assertSame(0, $migrated->tasks()->count());
    }

    public function test_no_onboarding_tasks_are_generated_unless_the_source_represents_them(): void
    {
        // An active onboarding template exists in the system; migration must not apply it.
        TaskTemplate::factory()->withItems()->create(['is_active' => true]);

        $this->migrate(dryRun: false);

        $this->assertSame(2, Task::query()->count(), 'exactly the two valid rows of the Tasks sheet');
        $this->assertSame(0, Task::query()->whereNotNull('task_template_item_id')->count());
        $this->assertSame(0, Project::query()->where('name', 'Bright Dental Clinic')->firstOrFail()->tasks()->count());
        $this->assertSame(['Fix broken internal links', 'Write meta descriptions'], Task::query()->orderBy('id')->pluck('title')->all());
    }
}
