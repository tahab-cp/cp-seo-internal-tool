<?php

namespace Tests\Feature\LegacyMigration;

use App\Enums\LegacyMigrationStatus;
use App\Models\Client;
use App\Models\LegacyMigrationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Support\RunsLegacyMigrations;
use Tests\TestCase;

class LegacyMigrationDryRunTest extends TestCase
{
    use RefreshDatabase;
    use RunsLegacyMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLegacyFixtureUsers();
    }

    public function test_dry_run_reports_every_intended_change_but_mutates_no_domain_data(): void
    {
        $before = $this->domainSnapshot();

        $run = $this->migrate(dryRun: true);

        $this->assertSame($before, $this->domainSnapshot(), 'a dry run writes nothing (records ledger included)');

        $this->assertSame(LegacyMigrationRun::MODE_DRY_RUN, $run->mode);
        $this->assertSame(LegacyMigrationStatus::DryRun, $run->status);
        $this->assertSame($this->admin->id, $run->created_by);
        $this->assertNotNull($run->completed_at);

        // The report is what apply WOULD do.
        $this->assertSame(2, $this->tallyOf($run, 'clients', 'create'));
        $this->assertSame(3, $this->tallyOf($run, 'projects', 'create'));
        $this->assertGreaterThan(0, $this->tallyOf($run, 'ranking_snapshots', 'create'));
        $this->assertGreaterThan(0, $this->tallyOf($run, 'backlinks', 'create'));
        $this->assertSame($run->total_items, (int) data_get($this->reportOf($run), 'totals.items'));
        $this->assertGreaterThan(0, $run->warning_count);
        $this->assertGreaterThan(0, $run->error_count);

        // Issues are persisted for review.
        $this->assertSame($run->warning_count + $run->error_count, $run->issues()->whereIn('severity', ['warning', 'error'])->count());
        $this->assertHasIssue($run, 'Unmapped source column "Keyword Group"', 'warning');
        $this->assertHasIssue($run, 'Sheet "Misc" is not a known legacy sheet', 'warning');
    }

    public function test_source_checksum_is_the_sha256_of_the_source_and_the_original_is_never_modified(): void
    {
        $files = collect(File::files($this->fixtureDirectory()))->mapWithKeys(fn ($f) => [$f->getFilename() => [hash_file('sha256', $f->getPathname()), $f->getMTime(), $f->getSize()]])->all();

        $run = $this->migrate(dryRun: true);
        $applied = $this->migrate(dryRun: false);

        $hashes = collect(File::files($this->fixtureDirectory()))->sortBy(fn ($f) => $f->getPathname())->map(fn ($f) => $f->getFilename().':'.hash_file('sha256', $f->getPathname()))->values()->all();
        $this->assertSame(hash('sha256', implode("\n", $hashes)), $run->source_checksum);
        $this->assertSame($run->source_checksum, $applied->source_checksum, 'same source, same checksum');
        $this->assertSame('sample-agency', $run->source_identifier);
        $this->assertSame('sample-agency', $run->source_filename);
        $this->assertSame('1.0', $run->mapper_version);

        $after = collect(File::files($this->fixtureDirectory()))->mapWithKeys(fn ($f) => [$f->getFilename() => [hash_file('sha256', $f->getPathname()), $f->getMTime(), $f->getSize()]])->all();
        $this->assertSame($files, $after, 'source files untouched: same hashes, mtimes and sizes');
        $this->assertSame(array_keys($files), array_keys($after), 'nothing renamed, added or deleted next to the source');
    }

    public function test_the_command_requires_an_explicit_mode_and_a_super_admin_actor(): void
    {
        $source = $this->fixtureDirectory();

        $this->artisan('seo:migrate-legacy', ['source' => $source, '--actor' => 'admin@sample.test'])
            ->expectsOutputToContain('Choose exactly one of --dry-run or --apply')
            ->assertExitCode(2);

        $this->artisan('seo:migrate-legacy', ['source' => $source, '--dry-run' => true, '--apply' => true, '--actor' => 'admin@sample.test'])
            ->assertExitCode(2);

        $this->artisan('seo:migrate-legacy', ['source' => $source, '--apply' => true])
            ->expectsOutputToContain('--actor=<email> is required')
            ->assertExitCode(2);

        foreach ([$this->manager, $this->executive] as $user) {
            $this->artisan('seo:migrate-legacy', ['source' => $source, '--apply' => true, '--actor' => $user->email])
                ->expectsOutputToContain('must be run by an active Super Admin')
                ->assertExitCode(2);
        }

        $inactive = User::factory()->superAdmin()->inactive()->create();
        $this->artisan('seo:migrate-legacy', ['source' => $source, '--apply' => true, '--actor' => $inactive->email])->assertExitCode(2);

        $this->assertSame(0, LegacyMigrationRun::query()->count(), 'refused runs are not recorded');
        $this->assertSame(0, Client::query()->count());
    }

    public function test_the_command_dry_run_writes_nothing_and_can_emit_a_valid_json_report(): void
    {
        $before = $this->domainSnapshot();
        $json = tempnam(sys_get_temp_dir(), 'legacy-report-').'.json';

        $this->artisan('seo:migrate-legacy', ['source' => $this->fixtureDirectory(), '--dry-run' => true, '--actor' => 'admin@sample.test', '--json-report' => $json])
            ->expectsOutputToContain('DRY RUN (nothing written)')
            ->expectsOutputToContain('Unmapped source columns')
            ->expectsOutputToContain('Keyword Group')
            ->assertExitCode(1); // the fixture deliberately carries row errors

        $this->assertSame($before, $this->domainSnapshot());

        $report = json_decode((string) file_get_contents($json), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('dry-run', $report['mode']);
        $this->assertSame(LegacyMigrationRun::query()->sole()->source_checksum, $report['checksum']);
        $this->assertSame($this->reportEntities(), array_keys($report['entities']));
        $this->assertContains('Rankings: "Keyword Group"', $report['unmapped_columns']);
        $this->assertSame($report['totals']['errors'], count(array_filter($report['issues'], fn ($i) => $i['severity'] === 'error')));
        $this->assertIsInt($report['run_id']);

        @unlink($json);
    }

    public function test_dry_run_and_apply_report_the_same_deterministic_totals(): void
    {
        $dry = $this->reportOf($this->migrate(dryRun: true));
        $applied = $this->reportOf($this->migrate(dryRun: false));

        $this->assertSame($dry['entities'], $applied['entities']);
        $this->assertSame($dry['totals'], $applied['totals']);
        $this->assertSame($dry['unmapped_columns'], $applied['unmapped_columns']);
    }
}
