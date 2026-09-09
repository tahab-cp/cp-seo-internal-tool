<?php

namespace Tests\Feature\Imports;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Rankings\RecordRankingSnapshotAction;
use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Enums\MonthlyCycleStatus;
use App\Enums\RankingSource;
use App\Exceptions\CsvImportException;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\RankingSnapshot;
use App\Models\User;
use App\Services\Imports\ImporterRegistry;
use App\Services\Imports\ImportExecutionService;
use App\Services\Imports\ImportUploadService;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RunsCsvImports;
use Tests\TestCase;

class RankingImportTest extends TestCase
{
    use RefreshDatabase;
    use RunsCsvImports;

    protected User $manager;

    protected Project $project;

    protected MonthlyCycle $september;

    protected MonthlyCycle $august;

    protected Keyword $roses;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImportDisk();
        $this->manager = User::factory()->seoManager()->create();
        $this->project = Project::factory()->create(['name' => 'Casa']);
        $this->august = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 8));
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
        $this->roses = Keyword::factory()->forProject($this->project)->create(['keyword' => 'Red Roses']);
        Keyword::factory()->forProject($this->project)->create(['keyword' => 'garden tools']);
    }

    public function test_ranking_csv_creates_snapshots_with_source_csv_import_and_blank_position_as_not_ranking(): void
    {
        $csv = "Keyword,Date,Rank,URL\n"
            ."red   roses,2026-09-10,4,https://casa.test/roses\n"
            ."garden tools,2026-09-10 09:30:00,,\n";

        $batch = $this->importCsv($this->manager, ImportType::RankingSnapshots, $this->project, $this->september, $csv);

        $this->assertSame(ImportStatus::Completed, $batch->status);
        $this->assertSame(2, $batch->imported_rows);

        $snapshots = RankingSnapshot::query()->orderBy('id')->get();
        $this->assertCount(2, $snapshots);
        $this->assertTrue($snapshots->every(fn (RankingSnapshot $s): bool => $s->source === RankingSource::CsvImport));
        $this->assertTrue($snapshots->every(fn (RankingSnapshot $s): bool => $s->monthly_cycle_id === $this->september->id));

        $this->assertSame(4, $snapshots[0]->position);
        $this->assertSame('https://casa.test/roses', $snapshots[0]->ranking_url);
        $this->assertSame('2026-09-10 00:00:00', $snapshots[0]->checked_at->format('Y-m-d H:i:s'));

        $this->assertNull($snapshots[1]->position, 'blank = Not Ranking, never 0');
        $this->assertSame('Not Ranking', $snapshots[1]->positionLabel());
        $this->assertSame('2026-09-10 09:30:00', $snapshots[1]->checked_at->format('Y-m-d H:i:s'));
    }

    public function test_the_csv_cannot_choose_a_source_and_zero_or_negative_positions_are_rejected(): void
    {
        // A "source" column is simply not a mappable field.
        $fields = collect(app(ImporterRegistry::class)->for(ImportType::RankingSnapshots)->fields())->pluck('key')->all();
        $this->assertNotContains('source', $fields);

        [, $report] = $this->validateCsv($this->manager, ImportType::RankingSnapshots, $this->project, $this->september, "keyword,checked_at,position\nred roses,2026-09-10,0\ngarden tools,2026-09-10,-2\nred roses,2026-09-11,1.5\n");

        $this->assertSame(0, $report->validCount());
        $this->assertCount(3, $report->issues());
        $this->assertStringContainsString('positive integer or empty', $report->issues()[0]->message);
        $this->assertSame('position', $report->issues()[0]->field);
        $this->assertSame(0, RankingSnapshot::query()->count());
    }

    public function test_unknown_keywords_are_rejected_and_never_created(): void
    {
        [$batch, $report] = $this->validateCsv($this->manager, ImportType::RankingSnapshots, $this->project, $this->september, "keyword,checked_at,position\nseo london,2026-09-10,3\nred roses,2026-09-10,5\n");

        $this->assertSame(1, $report->validCount());
        $this->assertSame(2, $report->issues()[0]->rowNumber);
        $this->assertSame('Unknown keyword "seo london" in this project; keywords are not created by a ranking import.', $report->issues()[0]->message);
        $this->assertSame(2, Keyword::query()->count());
        $this->assertFalse($report->isImportable());
    }

    public function test_duplicate_identity_follows_the_existing_deterministic_rule(): void
    {
        // Existing csv_import observation in the same month is updated in place.
        $existing = app(RecordRankingSnapshotAction::class)->handle($this->project, [
            'keyword_id' => $this->roses->id, 'monthly_cycle_id' => $this->september->id,
            'checked_at' => '2026-09-10 00:00:00', 'position' => 9, 'source' => RankingSource::CsvImport,
        ]);

        // A manual observation at the same moment is a different identity and stays.
        app(RecordRankingSnapshotAction::class)->handle($this->project, [
            'keyword_id' => $this->roses->id, 'monthly_cycle_id' => $this->september->id,
            'checked_at' => '2026-09-10 00:00:00', 'position' => 12,
        ]);

        [$batch, $report] = $this->validateCsv($this->manager, ImportType::RankingSnapshots, $this->project, $this->september, "keyword,checked_at,position\nred roses,2026-09-10,2\n");

        $this->assertTrue($report->isImportable());
        $this->assertSame(1, $report->warningCount());
        $this->assertStringContainsString('will be updated in place', $report->issues()[0]->message);

        app(ImportExecutionService::class)->execute($batch, $this->manager);

        $this->assertSame(2, RankingSnapshot::query()->count());
        $this->assertSame(2, $existing->refresh()->position);
        $this->assertSame(12, RankingSnapshot::query()->where('source', RankingSource::Manual->value)->value('position'));

        // The same identity twice inside one CSV is rejected deterministically.
        [, $report] = $this->validateCsv($this->manager, ImportType::RankingSnapshots, $this->project, $this->september, "keyword,checked_at,position\nred roses,2026-09-12,2\nRed Roses,2026-09-12 00:00,3\n");
        $this->assertSame('Duplicates row 2 (same keyword and checked-at moment).', $report->issues()[0]->message);
    }

    public function test_cross_cycle_historical_integrity_is_preserved(): void
    {
        app(RecordRankingSnapshotAction::class)->handle($this->project, [
            'keyword_id' => $this->roses->id, 'monthly_cycle_id' => $this->august->id,
            'checked_at' => '2026-08-20 00:00:00', 'position' => 9, 'source' => RankingSource::CsvImport,
        ]);

        [$batch, $report] = $this->validateCsv($this->manager, ImportType::RankingSnapshots, $this->project, $this->september, "keyword,checked_at,position\nred roses,2026-08-20,2\n");

        $this->assertFalse($report->isImportable());
        $this->assertStringContainsString('already exists in August 2026 and cannot be re-recorded against September 2026', $report->issues()[0]->message);
        $this->assertSame(9, RankingSnapshot::query()->sole()->position);
    }

    public function test_locked_cycle_imports_are_rejected_server_side_for_everyone(): void
    {
        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();
        $admin = User::factory()->superAdmin()->create();

        foreach ([$admin, $this->manager] as $user) {
            try {
                app(ImportUploadService::class)->resolveCycle($this->project, ImportType::RankingSnapshots, $this->september->id);
                $this->fail('locked cycle must be refused');
            } catch (CsvImportException $exception) {
                $this->assertStringContainsString('September 2026 is locked (finalized); imports into it are not allowed', $exception->getMessage());
            }
        }

        // Even a batch created against the month (before it was locked) cannot execute.
        $this->september->forceFill(['status' => MonthlyCycleStatus::Reporting, 'locked_at' => null])->save();
        [$batch] = $this->validateCsv($admin, ImportType::RankingSnapshots, $this->project, $this->september, "keyword,checked_at,position\nred roses,2026-09-10,2\n");
        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();

        $batch = app(ImportExecutionService::class)->execute($batch, $admin);

        $this->assertSame(ImportStatus::Failed, $batch->status);
        $this->assertSame(0, RankingSnapshot::query()->count());
        $this->assertStringContainsString('September 2026 is locked (finalized); cannot import CSV data into it', $batch->rowErrors()->first()->message);
    }

    public function test_keywords_of_another_project_are_never_matched(): void
    {
        $other = Project::factory()->create();
        Keyword::factory()->forProject($other)->create(['keyword' => 'seo london']);

        [, $report] = $this->validateCsv($this->manager, ImportType::RankingSnapshots, $this->project, $this->september, "keyword,checked_at,position\nseo london,2026-09-10,3\n");

        $this->assertSame(0, $report->validCount());
        $this->assertStringContainsString('Unknown keyword "seo london"', $report->issues()[0]->message);
        $this->assertSame(0, RankingSnapshot::query()->count());
    }

    public function test_a_location_column_disambiguates_keywords_tracked_in_several_locations(): void
    {
        $london = Keyword::factory()->forProject($this->project)->create(['keyword' => 'plumber', 'location' => 'London']);
        $leeds = Keyword::factory()->forProject($this->project)->create(['keyword' => 'plumber', 'location' => 'Leeds']);

        [, $report] = $this->validateCsv($this->manager, ImportType::RankingSnapshots, $this->project, $this->september, "keyword,checked_at,position,location\nplumber,2026-09-10,3,\nplumber,2026-09-10,4,leeds\n");

        $this->assertSame(1, $report->validCount());
        $this->assertStringContainsString('tracked in several locations (London, Leeds); add a location column', $report->issues()[0]->message);
        $this->assertSame($leeds->id, $report->validRows()[0]->data['keyword_id']);
        $this->assertNotSame($london->id, $report->validRows()[0]->data['keyword_id']);
    }
}
