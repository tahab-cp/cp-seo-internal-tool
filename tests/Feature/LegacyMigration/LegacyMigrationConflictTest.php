<?php

namespace Tests\Feature\LegacyMigration;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Tasks\CreateTaskAction;
use App\Enums\MonthlyCycleStatus;
use App\Models\Client;
use App\Models\GscMonthlyMetric;
use App\Models\Project;
use App\Models\RankingSnapshot;
use App\Models\Task;
use App\Services\LegacyMigration\Mappers\LegacyTaskMapper;
use App\Support\LegacyMigration\LegacySheet;
use App\Support\LegacyMigration\MigrationContext;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\RunsLegacyMigrations;
use Tests\TestCase;

class LegacyMigrationConflictTest extends TestCase
{
    use RefreshDatabase;
    use RunsLegacyMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLegacyFixtureUsers();
    }

    public function test_an_existing_client_with_the_same_name_is_a_conflict_unless_explicitly_mapped(): void
    {
        $existing = Client::factory()->create(['name' => 'Acme Holdings']);

        $run = $this->migrate(dryRun: false);

        $this->assertSame(1, Client::query()->where('name', 'Acme Holdings')->count(), 'never merged on a name, never duplicated');
        $this->assertSame(0, $existing->projects()->count(), 'nothing attached to the unmapped client');
        $this->assertSame(0, Project::query()->where('name', 'Acme Garden Centre')->count());
        $this->assertSame(1, $this->tallyOf($run, 'clients', 'conflict'));
        $this->assertSame(2, $this->tallyOf($run, 'projects', 'conflict'));
        $this->assertHasIssue($run, 'A client named "Acme Holdings" already exists', 'error');

        // Bright Dental (no conflict) still migrated: groups are independent.
        $this->assertSame(1, Project::query()->where('name', 'Bright Dental Clinic')->count());
    }

    public function test_existing_client_and_project_are_reused_through_explicit_mapping(): void
    {
        $client = Client::factory()->create(['name' => 'Afzal Group']);
        $project = Project::factory()->forClient($client)->create(['name' => 'Old Garden Site', 'website_url' => 'https://legacy-garden.example']);

        $run = $this->migrate(dryRun: false, mappingOverride: [
            'clients' => ['acme holdings' => $client->id],
            'projects' => ['Acme Garden Centre' => $project->id],
        ]);

        $this->assertSame(0, Client::query()->where('name', 'Acme Holdings')->count(), 'reused the mapped client instead of creating one');
        $this->assertSame(2, Client::query()->count(), 'Afzal Group (mapped) + Bright Dental (created)');
        $this->assertSame(2, $client->projects()->count(), 'Old Garden Site (reused) + Acme Plumbing (created) under the mapped client');
        $this->assertSame('Old Garden Site', $project->refresh()->name, 'existing project attributes are never overwritten');
        $this->assertSame('https://legacy-garden.example', $project->website_url);
        $this->assertSame(1, $this->tallyOf($run, 'clients', 'skip'));
        $this->assertSame(1, $this->tallyOf($run, 'projects', 'skip'));
        $this->assertSame(2, $this->tallyOf($run, 'projects', 'create'));

        // Data sheets referencing "Acme Garden Centre" landed on the reused project.
        $this->assertSame(2, $project->keywords()->count());
        $this->assertSame(3, $project->backlinks()->count());
        $this->assertHasIssue($run, 'reused as existing client "Afzal Group"', 'info');
    }

    public function test_an_existing_project_is_reused_by_normalised_website_url_within_its_client_and_conflicts_across_clients(): void
    {
        $acme = Client::factory()->create(['name' => 'Acme Holdings Ltd']);
        $same = Project::factory()->forClient($acme)->create(['name' => 'Garden (old)', 'website_url' => 'http://www.ACME-GARDEN.example/']);
        $other = Client::factory()->create(['name' => 'Someone Else']);
        Project::factory()->forClient($other)->create(['name' => 'Plumbing elsewhere', 'website_url' => 'https://acme-plumbing.example']);

        $run = $this->migrate(dryRun: false, mappingOverride: ['clients' => ['Acme Holdings' => $acme->id]]);

        $this->assertSame(1, $this->tallyOf($run, 'projects', 'skip'), 'same URL under the mapped client → reused');
        $this->assertSame($same->id, Project::query()->whereRaw('LOWER(website_url) LIKE ?', ['%acme-garden%'])->sole()->id);
        $this->assertSame(1, $this->tallyOf($run, 'projects', 'conflict'), 'same URL under another client → conflict');
        $this->assertSame(0, Project::query()->where('name', 'Acme Plumbing')->count());
        $this->assertHasIssue($run, 'already belongs to project "Plumbing elsewhere"', 'error');
    }

    public function test_a_locked_existing_cycle_is_a_conflict_that_is_never_unlocked(): void
    {
        $client = Client::factory()->create(['name' => 'Acme Holdings Ltd']);
        $garden = Project::factory()->forClient($client)->withPackage($this->growth)->create(['name' => 'Garden', 'website_url' => 'https://acme-garden.example']);
        $july = app(CreateMonthlyCycleAction::class)->handle($garden, new CyclePeriod(2026, 7));
        $july->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now(), 'locked_by' => $this->admin->id])->save();
        $snapshotTargets = $july->targets->pluck('target_value', 'target_key')->all();

        $run = $this->migrate(dryRun: false, mappingOverride: ['clients' => ['Acme Holdings' => $client->id]]);

        $july->refresh();
        $this->assertTrue($july->isLocked(), 'still locked');
        $this->assertSame($snapshotTargets, $july->targets()->pluck('target_value', 'target_key')->all(), 'targets untouched');
        $this->assertSame(0, $july->rankingSnapshots()->count());
        $this->assertSame(0, $july->backlinks()->count());
        $this->assertSame(0, $july->monthlyNotes()->count());
        $this->assertNull($july->gscMonthlyMetric()->first());
        $this->assertSame(0, $july->pageOptimizations()->count());
        $this->assertSame(1, $this->tallyOf($run, 'cycles', 'conflict'));
        $this->assertHasIssue($run, 'July 2026 of "Garden" is locked (finalized); rows for that month are skipped', 'error');
        $this->assertGreaterThan(0, $this->tallyOf($run, 'targets', 'conflict'), 'legacy July targets differ from the locked snapshot');

        // August (unlocked, absent) still migrated normally.
        $august = $garden->monthlyCycles()->forPeriod(new CyclePeriod(2026, 8))->firstOrFail();
        $this->assertSame(2, $august->rankingSnapshots()->count(), 'the two August observations of the garden keywords');
        $this->assertSame(0, $august->targets()->count(), 'created by the historical path without today\'s targets');
    }

    public function test_existing_analytics_and_observations_are_never_overwritten(): void
    {
        $first = $this->migrate(dryRun: false);
        $garden = Project::query()->where('name', 'Acme Garden Centre')->firstOrFail();
        $july = $garden->monthlyCycles()->forPeriod(new CyclePeriod(2026, 7))->firstOrFail();

        GscMonthlyMetric::query()->where('monthly_cycle_id', $july->id)->update(['clicks' => 999]);
        RankingSnapshot::query()->where('checked_at', '2026-07-01 00:00:00')->where('position', 24)->update(['position' => 5]);

        $again = $this->migrate(dryRun: false);

        $this->assertSame(999, GscMonthlyMetric::query()->where('monthly_cycle_id', $july->id)->sole()->clicks);
        $this->assertSame(5, RankingSnapshot::query()->where('checked_at', '2026-07-01 00:00:00')->where('keyword_id', $garden->keywords()->orderBy('id')->first()->id)->sole()->position);
        $this->assertGreaterThanOrEqual(1, $this->tallyOf($again, 'analytics', 'conflict'));
        $this->assertSame(2, $this->tallyOf($again, 'ranking_snapshots', 'conflict'), 'the changed observation, once per row that maps to it');
        $this->assertHasIssue($again, 'already has Search Console summary with different values; the legacy figures were NOT applied', 'warning');
        $this->assertHasIssue($again, 'already exists with position 5 (legacy 24); left unchanged', 'warning');
    }

    public function test_a_failed_group_is_rolled_back_and_reported_without_corrupting_other_groups(): void
    {
        app()->bind(LegacyTaskMapper::class, fn () => new class(app(CreateTaskAction::class)) extends LegacyTaskMapper
        {
            public function migrateGroup(MigrationContext $context, LegacySheet $sheet, string $group, array $rows): void
            {
                parent::migrateGroup($context, $sheet, $group, $rows);

                if ($group === 'Acme Garden Centre') {
                    throw new RuntimeException('Simulated failure after the group wrote its tasks.');
                }
            }
        });

        $run = $this->migrate(dryRun: false);

        $this->assertSame(0, Task::query()->where('title', 'Fix broken internal links')->count(), 'the failed group left no half-written tasks');
        $this->assertSame(0, $this->tallyOf($run, 'tasks', 'create'), 'rolled-back tallies never reach the report');
        $this->assertHasIssue($run, 'Sheet "Tasks", group "Acme Garden Centre" (2 row(s)) failed and was rolled back entirely: Simulated failure', 'error');

        // Everything else in the run is intact.
        $garden = Project::query()->where('name', 'Acme Garden Centre')->firstOrFail();
        $this->assertSame(3, $garden->backlinks()->count());
        $this->assertSame(6, $garden->keywords()->get()->sum(fn ($k) => $k->rankingSnapshots()->count()));
        $this->assertSame(2, $garden->monthlyCycles()->forPeriod(new CyclePeriod(2026, 7))->firstOrFail()->monthlyNotes()->count());
    }

    public function test_ranking_date_columns_without_a_year_are_rejected_instead_of_guessed(): void
    {
        $sheets = $this->fixtureSheets();
        $sheets['Rankings'][0] = ['project', 'keyword', 'location', 'target page', 'Jul 01', 'Jul 15', 'Aug 01', 'Keyword Group'];
        $path = $this->writeXlsx(['Projects' => $sheets['Projects'], 'Rankings' => $sheets['Rankings']]);

        $run = $this->migrate(dryRun: true, source: $path);
        $this->assertSame(0, $this->tallyOf($run, 'ranking_snapshots', 'create'));
        $this->assertHasIssue($run, 'Ranking column "Jul 01" has no year; set "ranking_year" in the mapping', 'error');

        $run = $this->migrate(dryRun: true, source: $path, mappingOverride: ['ranking_year' => 2026]);
        $this->assertSame(9, $this->tallyOf($run, 'ranking_snapshots', 'create'));

        @unlink($path);
    }
}
