<?php

namespace Tests\Feature\Imports;

use App\Actions\Analytics\SaveGscQueryMetricsAction;
use App\Actions\Keywords\CreateKeywordAction;
use App\Actions\Keywords\UpdateKeywordAction;
use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Enums\MonthlyCycleStatus;
use App\Exceptions\ImportRowException;
use App\Models\GscQueryMetric;
use App\Models\ImportRowError;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\RankingSnapshot;
use App\Models\User;
use App\Services\Analytics\AnalyticsIntegrityGuard;
use App\Services\Imports\Importers\GscQueryCsvImporter;
use App\Services\Imports\Importers\KeywordCsvImporter;
use App\Services\Imports\ImportExecutionService;
use App\Support\Imports\ImportContext;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\LockSpy;
use Tests\Support\RunsCsvImports;
use Tests\TestCase;

class ImportAtomicityTest extends TestCase
{
    use RefreshDatabase;
    use RunsCsvImports;

    protected User $manager;

    protected Project $project;

    protected MonthlyCycle $september;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImportDisk();
        $this->manager = User::factory()->seoManager()->create();
        $this->project = Project::factory()->create(['name' => 'Casa']);
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
    }

    public function test_a_fully_valid_batch_commits_every_row(): void
    {
        $batch = $this->importCsv($this->manager, ImportType::Keywords, $this->project, null, "keyword\none\ntwo\nthree\n");

        $this->assertSame(ImportStatus::Completed, $batch->status);
        $this->assertSame([3, 3, 3, 0], [$batch->total_rows, $batch->valid_rows, $batch->imported_rows, $batch->failed_rows]);
        $this->assertNotNull($batch->started_at);
        $this->assertNotNull($batch->completed_at);
        $this->assertSame(3, Keyword::query()->count());
    }

    public function test_one_row_failing_during_the_final_write_rolls_the_entire_batch_back(): void
    {
        // A row that passes validation but fails when written (simulated at the third row).
        app()->bind(KeywordCsvImporter::class, fn () => new class(app(CreateKeywordAction::class), app(UpdateKeywordAction::class)) extends KeywordCsvImporter
        {
            public function import(ImportContext $context, Collection $rows): int
            {
                $written = 0;

                foreach ($rows as $row) {
                    if ($row->rowNumber === 4) {
                        throw ImportRowException::forRow(4, new \InvalidArgumentException('Simulated domain failure on "three".'));
                    }

                    $this->create->handle($context->project, $row->data['attributes']);
                    $written++;
                }

                return $written;
            }
        });

        [$batch, $report] = $this->validateCsv($this->manager, ImportType::Keywords, $this->project, null, "keyword\none\ntwo\nthree\nfour\n");
        $this->assertTrue($report->isImportable());

        $batch = app(ImportExecutionService::class)->execute($batch, $this->manager);

        $this->assertSame(ImportStatus::Failed, $batch->status);
        $this->assertSame(0, Keyword::query()->count(), 'rows one and two were rolled back with the failure');
        $this->assertSame([4, 4, 0, 1], [$batch->total_rows, $batch->valid_rows, $batch->imported_rows, $batch->failed_rows]);

        $error = $batch->rowErrors()->sole();
        $this->assertSame(4, $error->row_number);
        $this->assertSame(ImportRowError::SEVERITY_ERROR, $error->severity);
        $this->assertSame('Simulated domain failure on "three".', $error->message);
        $this->assertNotNull($batch->completed_at);
    }

    public function test_no_half_imported_monthly_dataset_remains_after_a_failure(): void
    {
        GscQueryMetric::factory()->forCycle($this->september)->create(['query' => 'existing', 'clicks' => 7, 'impressions' => 70]);

        app()->bind(GscQueryCsvImporter::class, fn () => new class(app(SaveGscQueryMetricsAction::class), app(AnalyticsIntegrityGuard::class)) extends GscQueryCsvImporter
        {
            public function import(ImportContext $context, Collection $rows): int
            {
                parent::import($context, $rows); // the month's dataset IS replaced here...

                throw new RuntimeException('boom after the dataset was written');
            }
        });

        [$batch] = $this->validateCsv($this->manager, ImportType::GscQueries, $this->project, $this->september, "query,clicks,impressions,ctr\nnew one,1,10,10\nnew two,2,20,10\n");
        $batch = app(ImportExecutionService::class)->execute($batch, $this->manager);

        $this->assertSame(ImportStatus::Failed, $batch->status);
        $rows = GscQueryMetric::query()->where('monthly_cycle_id', $this->september->id)->get();
        $this->assertSame(['existing'], $rows->pluck('query')->all(), '... and rolled back entirely');
        $this->assertSame(7, $rows->first()->clicks);
        $this->assertSame(0, $batch->imported_rows);
        $this->assertStringContainsString('could not be completed and nothing was written', $batch->rowErrors()->sole()->message, 'unexpected exceptions never leak stack traces');
    }

    public function test_a_cycle_locked_between_preview_and_execution_causes_a_clean_failure(): void
    {
        Keyword::factory()->forProject($this->project)->create(['keyword' => 'roses']);
        [$batch, $report] = $this->validateCsv($this->manager, ImportType::RankingSnapshots, $this->project, $this->september, "keyword,checked_at,position\nroses,2026-09-10,3\n");
        $this->assertTrue($report->isImportable());

        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();

        $batch = app(ImportExecutionService::class)->execute($batch, $this->manager);

        $this->assertSame(ImportStatus::Failed, $batch->status);
        $this->assertSame(0, RankingSnapshot::query()->count());
        $this->assertSame(0, $batch->rowErrors()->sole()->row_number, 'batch-level failure');
        $this->assertStringContainsString('September 2026 is locked (finalized); cannot import CSV data into it', $batch->rowErrors()->sole()->message);
    }

    public function test_execution_uses_the_monthly_cycle_row_lock_convention_and_validation_does_not(): void
    {
        Keyword::factory()->forProject($this->project)->create(['keyword' => 'roses']);
        $spy = LockSpy::install();
        $baseline = DB::transactionLevel();

        [$batch] = $this->validateCsv($this->manager, ImportType::RankingSnapshots, $this->project, $this->september, "keyword,checked_at,position\nroses,2026-09-10,3\n");
        $this->assertSame([], $spy->locks, 'parsing and validating never take the cycle lock');

        app(ImportExecutionService::class)->execute($batch, $this->manager);

        $this->assertNotEmpty($spy->locks);
        $this->assertSame([$this->september->id], $spy->locks[0]['ids'], 'the import transaction locks the selected cycle first');
        $this->assertGreaterThan($baseline, $spy->locks[0]['transaction_level'], 'FOR UPDATE inside the import transaction');

        foreach ($spy->lockedIdSets() as $ids) {
            $this->assertSame([$this->september->id], $ids, 'every nested domain action re-locks the same cycle only');
        }

        $this->assertSame(1, RankingSnapshot::query()->count());
    }
}
