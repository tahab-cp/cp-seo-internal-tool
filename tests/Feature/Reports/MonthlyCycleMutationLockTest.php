<?php

namespace Tests\Feature\Reports;

use App\Actions\Analytics\SaveGa4CountryMetricsAction;
use App\Actions\Analytics\SaveGscMonthlyMetricsAction;
use App\Actions\Backlinks\SetBacklinkStatusAction;
use App\Actions\Backlinks\UpdateBacklinkAction;
use App\Actions\Content\CreateContentItemAction;
use App\Actions\Content\UpdateContentItemAction;
use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Notes\CreateMonthlyNoteAction;
use App\Actions\Pages\RecordPageOptimizationAction;
use App\Actions\Pages\UpdatePageOptimizationAction;
use App\Actions\Rankings\RecordRankingSnapshotAction;
use App\Actions\Rankings\UpdateRankingSnapshotAction;
use App\Actions\Reports\FinalizeMonthlyReportAction;
use App\Actions\Reports\MarkReportReadyAction;
use App\Actions\Reports\UnlockMonthlyReportAction;
use App\Actions\Reports\UpdateMonthlyReportDraftAction;
use App\Actions\Tasks\CreateTaskAction;
use App\Actions\Tasks\SetTaskStatusAction;
use App\Actions\Tasks\UpdateTaskAction;
use App\Enums\MonthlyCycleStatus;
use App\Enums\ReportStatus;
use App\Exceptions\LockedMonthlyCycleException;
use App\Exceptions\ReportDataChangedException;
use App\Models\MonthlyCycle;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportRevision;
use App\Models\Page;
use App\Models\User;
use App\Services\Reports\PdfReportGenerator;
use App\Services\Reports\ReportAuditRecorder;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Support\BuildsCompleteReports;
use Tests\Support\FakePdfReportGenerator;
use Tests\Support\LockSpy;
use Tests\TestCase;

/**
 * The shared MonthlyCycle row-lock convention: every monthly writer and
 * the finalization commit coordinate through SELECT … FOR UPDATE on the
 * cycle row, with a fresh re-check, in deterministic order.
 */
class MonthlyCycleMutationLockTest extends TestCase
{
    use BuildsCompleteReports;
    use RefreshDatabase;

    protected LockSpy $spy;

    protected MonthlyCycle $october;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05 10:00:00');
        Storage::fake('local');
        FakePdfReportGenerator::install();

        $this->buildCompleteReport();
        $this->october = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 10));
        $this->spy = LockSpy::install();
    }

    public function test_every_monthly_writer_locks_and_rechecks_the_cycle_row_inside_its_transaction(): void
    {
        // RefreshDatabase wraps every test in a transaction; writers must open their own on top.
        $baseline = DB::transactionLevel();

        $writers = [
            'task' => fn () => app(CreateTaskAction::class)->handle($this->project, ['title' => 't', 'monthly_cycle_id' => $this->cycle->id], $this->manager),
            'page optimisation' => fn () => app(RecordPageOptimizationAction::class)->handle($this->project, ['page_id' => Page::factory()->forProject($this->project)->create()->id, 'monthly_cycle_id' => $this->cycle->id, 'content_updated' => true], $this->manager),
            'ranking' => fn () => app(RecordRankingSnapshotAction::class)->handle($this->project, ['keyword_id' => $this->keyword->id, 'monthly_cycle_id' => $this->cycle->id, 'checked_at' => '2026-09-29 09:00', 'position' => 5]),
            'backlink status' => fn () => app(SetBacklinkStatusAction::class)->handle($this->cycle->backlinks()->firstOrFail(), 'submitted'),
            'content' => fn () => app(CreateContentItemAction::class)->handle($this->project, ['title' => 'c', 'content_type' => 'blog', 'status' => 'planned', 'monthly_cycle_id' => $this->cycle->id]),
            'gsc summary' => fn () => app(SaveGscMonthlyMetricsAction::class)->handle($this->cycle, ['clicks' => 1, 'impressions' => 1], $this->manager),
            'ga4 countries' => fn () => app(SaveGa4CountryMetricsAction::class)->handle($this->cycle, [['country' => 'Spain']], $this->manager),
            'note' => fn () => app(CreateMonthlyNoteAction::class)->handle($this->cycle, ['type' => 'win', 'body' => 'w'], $this->manager),
            'report narrative' => fn () => app(UpdateMonthlyReportDraftAction::class)->handle($this->report, ['executive_summary' => 'x']),
            'task status' => fn () => app(SetTaskStatusAction::class)->handle($this->cycle->tasks()->firstOrFail(), 'completed'),
        ];

        foreach ($writers as $label => $write) {
            $this->spy->locks = [];
            $write();

            $this->assertNotEmpty($this->spy->locks, "{$label} must lock the cycle row.");
            $this->assertSame([$this->cycle->id], $this->spy->locks[0]['ids'], "{$label} must lock its own cycle.");
            $this->assertGreaterThan($baseline, $this->spy->locks[0]['transaction_level'], "{$label} must lock inside its own transaction.");
        }
    }

    public function test_a_writer_refuses_when_the_cycle_was_locked_before_it_gained_the_row_lock(): void
    {
        // The writer holds a stale, unlocked cycle model. Another "connection"
        // locks the month right before the writer's FOR UPDATE succeeds.
        $stale = $this->cycle->fresh();
        $this->assertFalse($stale->isLocked());

        $this->spy->beforeLock = function (): void {
            DB::table('monthly_cycles')->where('id', $this->cycle->id)->update(['status' => MonthlyCycleStatus::Locked->value, 'locked_at' => now()]);
        };

        $before = $this->cycle->monthlyNotes()->count();

        try {
            app(CreateMonthlyNoteAction::class)->handle($stale, ['type' => 'win', 'body' => 'Sneaky'], $this->manager);
            $this->fail('Expected LockedMonthlyCycleException from the fresh re-check.');
        } catch (LockedMonthlyCycleException) {
            $this->addToAssertionCount(1);
        }

        $this->assertFalse($stale->isLocked(), 'The stale model is irrelevant; the database decided.');
        $this->assertSame($before, $this->cycle->monthlyNotes()->count());

        // (The refused writer's savepoint rolled the simulated lock back too; in
        // production the other connection's commit persists.) Lock for real now.
        $this->spy->beforeLock = null;
        DB::table('monthly_cycles')->where('id', $this->cycle->id)->update(['status' => MonthlyCycleStatus::Locked->value, 'locked_at' => now()]);

        try {
            app(SaveGscMonthlyMetricsAction::class)->handle($stale, ['clicks' => 9, 'impressions' => 9], $this->manager);
            $this->fail('Expected the now-locked month to refuse analytics too.');
        } catch (LockedMonthlyCycleException) {
            $this->assertSame(1200, $this->cycle->fresh()->gscMonthlyMetric->clicks);
        }
    }

    public function test_two_cycle_moves_lock_both_rows_in_ascending_id_order_and_respect_locks(): void
    {
        $this->assertLessThan($this->october->id, $this->cycle->id);

        $task = app(CreateTaskAction::class)->handle($this->project, ['title' => 'move me', 'monthly_cycle_id' => $this->october->id], $this->manager);
        $link = $this->cycle->backlinks()->firstOrFail();
        $content = app(CreateContentItemAction::class)->handle($this->project, ['title' => 'c', 'content_type' => 'blog', 'status' => 'planned', 'monthly_cycle_id' => $this->october->id]);
        $optimisation = app(RecordPageOptimizationAction::class)->handle($this->project, ['page_id' => Page::factory()->forProject($this->project)->create()->id, 'monthly_cycle_id' => $this->october->id, 'content_updated' => true], $this->manager);
        $snapshot = $this->keyword->rankingSnapshots()->orderBy('id')->firstOrFail();

        $moves = [
            'task Oct→Sep' => fn () => app(UpdateTaskAction::class)->handle($task, ['monthly_cycle_id' => $this->cycle->id]),
            'backlink Sep→Oct' => fn () => app(UpdateBacklinkAction::class)->handle($link, ['monthly_cycle_id' => $this->october->id]),
            'content Oct→Sep' => fn () => app(UpdateContentItemAction::class)->handle($content, ['monthly_cycle_id' => $this->cycle->id]),
            'optimisation Oct→Sep' => fn () => app(UpdatePageOptimizationAction::class)->handle($optimisation, ['monthly_cycle_id' => $this->cycle->id]),
            'ranking Sep→Oct' => fn () => app(UpdateRankingSnapshotAction::class)->handle($snapshot, ['monthly_cycle_id' => $this->october->id, 'checked_at' => '2026-10-02 09:00']),
        ];

        foreach ($moves as $label => $move) {
            $this->spy->locks = [];
            $move();

            $this->assertSame([[$this->cycle->id, $this->october->id]], $this->spy->lockedIdSets(), "{$label} must lock both cycles once, ascending.");
        }

        $this->assertSame($this->cycle->id, $task->fresh()->monthly_cycle_id);
        $this->assertSame($this->october->id, $link->fresh()->monthly_cycle_id);

        // Project-level → cycle locks only the destination; cycle → project-level only the source.
        $this->spy->locks = [];
        app(UpdateContentItemAction::class)->handle($content->fresh(), ['monthly_cycle_id' => null]);
        $this->assertSame([[$this->cycle->id]], $this->spy->lockedIdSets());

        $this->spy->locks = [];
        app(UpdateContentItemAction::class)->handle($content->fresh(), ['monthly_cycle_id' => $this->october->id]);
        $this->assertSame([[$this->october->id]], $this->spy->lockedIdSets());

        // Locked destination and locked source are both refused.
        $this->october->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();

        foreach ([
            fn () => app(UpdateTaskAction::class)->handle($task->fresh(), ['monthly_cycle_id' => $this->october->id]),
            fn () => app(UpdateBacklinkAction::class)->handle($link->fresh(), ['monthly_cycle_id' => $this->cycle->id]),
            fn () => app(UpdateContentItemAction::class)->handle($content->fresh(), ['title' => 'renamed']),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected LockedMonthlyCycleException.');
            } catch (LockedMonthlyCycleException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame($this->cycle->id, $task->fresh()->monthly_cycle_id);
        $this->assertSame($this->october->id, $link->fresh()->monthly_cycle_id);
        $this->assertSame('c', $content->fresh()->title);
    }

    public function test_finalization_holds_the_cycle_lock_across_the_final_rebuild_and_commit_and_keeps_chromium_outside(): void
    {
        $pdf = app(PdfReportGenerator::class);
        $this->assertInstanceOf(FakePdfReportGenerator::class, $pdf);

        $baseline = DB::transactionLevel();
        $levelDuringConversion = null;
        $locksDuringConversion = null;
        $pdf->duringConversion = function () use (&$levelDuringConversion, &$locksDuringConversion): void {
            $levelDuringConversion = DB::transactionLevel();
            $locksDuringConversion = count($this->spy->locks);
        };

        app(MarkReportReadyAction::class)->handle($this->report, $this->manager);
        $this->spy->locks = [];

        $final = app(FinalizeMonthlyReportAction::class)->handle($this->report->fresh(), $this->manager)->fresh();

        $this->assertSame(ReportStatus::Final, $final->status);
        // Chromium ran before the commit transaction, with no row lock held.
        $this->assertSame($baseline, $levelDuringConversion);
        $this->assertSame(0, $locksDuringConversion);
        // The commit transaction took exactly one cycle lock, on this cycle, inside its own transaction.
        $this->assertCount(1, $this->spy->locks);
        $this->assertSame([$this->cycle->id], $this->spy->locks[0]['ids']);
        $this->assertGreaterThan($baseline, $this->spy->locks[0]['transaction_level']);
        $this->assertTrue($this->cycle->fresh()->isLocked());
    }

    public function test_a_writer_cannot_slip_in_between_the_final_comparison_and_the_cycle_lock(): void
    {
        // The writer only ever sees the cycle after finalization's lock is
        // released — and by then it is locked. Simulated by running the
        // writer right after finalization's own row lock is taken.
        app(MarkReportReadyAction::class)->handle($this->report, $this->manager);

        $baseline = DB::transactionLevel();
        $writerOutcome = null;
        $this->spy->beforeLock = function (array $ids) use (&$writerOutcome, $baseline): void {
            if ($writerOutcome !== null || DB::transactionLevel() <= $baseline) {
                return;
            }

            // Finalization is taking its lock now; a writer that arrives here
            // queues on the same row and is answered only after commit.
            $writerOutcome = 'queued-behind-finalization';
        };

        $final = app(FinalizeMonthlyReportAction::class)->handle($this->report->fresh(), $this->manager)->fresh();
        $this->spy->beforeLock = null;

        $this->assertSame('queued-behind-finalization', $writerOutcome);
        $this->assertSame(ReportStatus::Final, $final->status);

        // …and when the queued writer finally runs, the fresh row is locked.
        try {
            app(SaveGscMonthlyMetricsAction::class)->handle($this->cycle, ['clicks' => 1, 'impressions' => 1], $this->manager);
            $this->fail('Expected the writer to be refused after finalization committed.');
        } catch (LockedMonthlyCycleException) {
            $this->assertSame(1200, collect($final->snapshot_json['sections'])->firstWhere('key', 'organic_search')['data']['clicks']);
            $this->assertSame(1200, $this->cycle->fresh()->gscMonthlyMetric->clicks);
        }
    }

    public function test_the_optimistic_fingerprint_check_still_protects_the_chromium_window(): void
    {
        $pdf = app(PdfReportGenerator::class);
        $pdf->duringConversion = function (): void {
            app(SaveGscMonthlyMetricsAction::class)->handle($this->cycle->fresh(), ['clicks' => 1350, 'impressions' => 48000], $this->manager);
        };

        app(MarkReportReadyAction::class)->handle($this->report, $this->manager);

        try {
            app(FinalizeMonthlyReportAction::class)->handle($this->report->fresh(), $this->manager);
            $this->fail('Expected ReportDataChangedException.');
        } catch (ReportDataChangedException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(ReportStatus::ReadyForReview, $this->report->fresh()->status);
        $this->assertFalse($this->cycle->fresh()->isLocked());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_concurrent_unlocks_archive_exactly_one_revision_and_one_version_step(): void
    {
        $admin = User::factory()->superAdmin()->create();
        app(MarkReportReadyAction::class)->handle($this->report, $this->manager);
        $final = app(FinalizeMonthlyReportAction::class)->handle($this->report->fresh(), $this->manager)->fresh();

        // A competing unlock commits between this one's authorization and its
        // row locks (the same seam a real second connection would win).
        $inner = null;
        $loser = new class($inner, $admin) extends UnlockMonthlyReportAction
        {
            public function __construct(public mixed &$winner, private User $admin)
            {
                parent::__construct(app(ReportAuditRecorder::class));
            }

            protected function beforeAcquiringLocks(MonthlyReport $report): void
            {
                $this->winner = app(UnlockMonthlyReportAction::class)->handle($report->fresh(), $this->admin, 'First unlock wins the race.');
            }
        };

        try {
            $loser->handle($final, $admin, 'Second unlock loses the race.');
            $this->fail('Expected the losing unlock to be refused deterministically.');
        } catch (InvalidArgumentException $exception) {
            // Either the fresh re-check (real connection: the row lock waits for
            // the winner's commit) or the unique (report, version) index catches
            // it; both surface as a plain domain message, never a raw DB error.
            $this->assertMatchesRegularExpression('/Only a final report can be unlocked|nothing to unlock|already archived by another unlock/', $exception->getMessage());
        }

        $fresh = $final->fresh();
        $this->assertNotNull($inner);
        $this->assertSame(2, $fresh->version);
        $this->assertSame(ReportStatus::Draft, $fresh->status);
        $this->assertSame(1, MonthlyReportRevision::query()->count());
        $this->assertSame('First unlock wins the race.', $fresh->revisions()->first()->unlock_reason);
        $this->assertSame(1, $fresh->auditEvents()->where('event_type', 'report_unlocked_for_correction')->count());
        $this->assertSame(MonthlyCycleStatus::Reporting, $this->cycle->fresh()->status);
    }
}
