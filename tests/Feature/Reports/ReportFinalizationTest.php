<?php

namespace Tests\Feature\Reports;

use App\Actions\Analytics\SaveGscMonthlyMetricsAction;
use App\Actions\Backlinks\CreateBacklinkAction;
use App\Actions\Content\CreateContentItemAction;
use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Notes\CreateMonthlyNoteAction;
use App\Actions\Reports\EnsureMonthlyReportAction;
use App\Actions\Reports\FinalizeMonthlyReportAction;
use App\Actions\Reports\MarkReportReadyAction;
use App\Actions\Reports\UpdateMonthlyReportDraftAction;
use App\Actions\Tasks\CreateTaskAction;
use App\Enums\MonthlyCycleStatus;
use App\Enums\ReportSectionStatus;
use App\Enums\ReportStatus;
use App\Exceptions\LockedMonthlyCycleException;
use App\Exceptions\PdfGenerationException;
use App\Exceptions\ReportNotReadyException;
use App\Filament\Resources\Projects\Pages\ProjectReportEditor;
use App\Models\MonthlyReport;
use App\Models\User;
use App\Services\Reports\PdfReportGenerator;
use App\Services\Reports\ReportSnapshotBuilder;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Support\BuildsCompleteReports;
use Tests\Support\FakePdfReportGenerator;
use Tests\TestCase;

class ReportFinalizationTest extends TestCase
{
    use BuildsCompleteReports;
    use RefreshDatabase;

    protected User $executive;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-30 10:00:00');
        Storage::fake('local');

        $this->executive = User::factory()->seoExecutive()->create();
        $this->buildCompleteReport($this->executive);
        app(MarkReportReadyAction::class)->handle($this->report, $this->manager);
        $this->report = $this->report->fresh();
    }

    protected function assertUntouched(MonthlyReport $report, ReportStatus $status = ReportStatus::ReadyForReview): void
    {
        $fresh = $report->fresh();
        $cycle = $fresh->monthlyCycle->fresh();

        $this->assertSame($status, $fresh->status);
        $this->assertNull($fresh->snapshot_json);
        $this->assertNull($fresh->generated_pdf_path);
        $this->assertNull($fresh->generated_at);
        $this->assertNull($fresh->finalized_at);
        $this->assertNull($fresh->finalized_by);
        $this->assertSame(MonthlyCycleStatus::Open, $cycle->status);
        $this->assertNull($cycle->locked_at);
        $this->assertNull($cycle->locked_by);
    }

    public function test_executive_cannot_finalize_even_when_the_report_is_ready(): void
    {
        FakePdfReportGenerator::install();

        $this->assertFalse($this->executive->can('finalize', $this->report));

        try {
            app(FinalizeMonthlyReportAction::class)->handle($this->report, $this->executive);
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertUntouched($this->report);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->actingAs($this->executive);

        Livewire::test(ProjectReportEditor::class, ['record' => $this->project->getRouteKey(), 'report' => $this->report->getKey()])
            ->assertActionHidden('finalize')
            ->mountAction('finalize')
            ->callMountedAction();

        $this->assertUntouched($this->report);
    }

    public function test_manager_and_admin_finalize_a_ready_complete_report_and_lock_the_month(): void
    {
        $fake = FakePdfReportGenerator::install();

        foreach ([$this->manager, User::factory()->superAdmin()->create(['name' => 'Ava Admin'])] as $index => $finalizer) {
            if ($index === 1) {
                // Fresh ready report on a second month for the admin.
                $this->cycle = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 8));
                $this->report = app(EnsureMonthlyReportAction::class)->handle($this->cycle);
                $this->report->sections()->update(['is_required' => false]);
                app(MarkReportReadyAction::class)->handle($this->report, $finalizer);
                $this->report = $this->report->fresh();
            }

            Carbon::setTestNow('2026-10-01 09:30:00');

            $this->assertTrue($finalizer->can('finalize', $this->report));

            $final = app(FinalizeMonthlyReportAction::class)->handle($this->report, $finalizer);
            $fresh = $final->fresh();
            $cycle = $this->cycle->fresh();

            $this->assertSame(ReportStatus::Final, $fresh->status);
            $this->assertIsArray($fresh->snapshot_json);
            $this->assertSame(1, $fresh->snapshot_json['schema_version']);
            $this->assertSame('final', $fresh->snapshot_json['report']['status']);
            $this->assertSame($finalizer->name, $fresh->snapshot_json['finalized_by']['name']);
            $this->assertMatchesRegularExpression('#^reports/'.$this->project->getKey().'/2026-0[89]/report-'.$fresh->getKey().'-\d{14}\.pdf$#', $fresh->generated_pdf_path);
            Storage::disk('local')->assertExists($fresh->generated_pdf_path);
            $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($fresh->generated_pdf_path));
            $this->assertSame('2026-10-01 09:30:00', $fresh->generated_at->toDateTimeString());
            $this->assertSame('2026-10-01 09:30:00', $fresh->finalized_at->toDateTimeString());
            $this->assertSame($finalizer->getKey(), $fresh->finalized_by);
            $this->assertTrue($fresh->finalizedBy->is($finalizer));

            $this->assertSame(MonthlyCycleStatus::Locked, $cycle->status);
            $this->assertSame('2026-10-01 09:30:00', $cycle->locked_at->toDateTimeString());
            $this->assertSame($finalizer->getKey(), $cycle->locked_by);
            $this->assertTrue($cycle->isLocked());

            $this->assertTrue($fresh->sections()->where('is_required', true)->get()->every(fn ($s) => $s->status === ReportSectionStatus::Complete));
        }

        $this->assertSame(2, $fake->conversions);
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    public function test_draft_reports_and_locked_months_cannot_be_finalized(): void
    {
        FakePdfReportGenerator::install();

        $this->report->forceFill(['status' => ReportStatus::Draft])->save();

        try {
            app(FinalizeMonthlyReportAction::class)->handle($this->report->fresh(), $this->manager);
            $this->fail('Expected a draft to be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Ready for Review', $exception->getMessage());
        }

        $this->assertUntouched($this->report, ReportStatus::Draft);
        $this->assertFalse($this->manager->can('finalize', $this->report->fresh()));

        $this->report->forceFill(['status' => ReportStatus::ReadyForReview])->save();
        $this->cycle->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();

        try {
            app(FinalizeMonthlyReportAction::class)->handle($this->report->fresh(), $this->manager);
            $this->fail('Expected LockedMonthlyCycleException.');
        } catch (LockedMonthlyCycleException) {
            $this->assertSame(ReportStatus::ReadyForReview, $this->report->fresh()->status);
            $this->assertNull($this->report->fresh()->finalized_at);
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_finalization_re_runs_readiness_and_refuses_a_ready_report_whose_data_went_missing(): void
    {
        $fake = FakePdfReportGenerator::install();

        // Data removed after the report was marked ready.
        $this->cycle->gscQueryMetrics()->delete();
        $this->report->sections()->update(['status' => ReportSectionStatus::Complete->value]);

        try {
            app(FinalizeMonthlyReportAction::class)->handle($this->report, $this->manager);
            $this->fail('Expected ReportNotReadyException.');
        } catch (ReportNotReadyException $exception) {
            $this->assertSame(['top_keywords'], $exception->readiness->missing()->map(fn ($s) => $s->key->value)->all());
            $this->assertStringContainsString('cannot be finalized', $exception->getMessage());
        }

        $this->assertUntouched($this->report);
        $this->assertSame(0, $fake->conversions);
        $this->assertSame(ReportSectionStatus::Incomplete, $this->report->sections()->where('section_key', 'top_keywords')->first()->status);

        $this->actingAs($this->manager);

        Livewire::test(ProjectReportEditor::class, ['record' => $this->project->getRouteKey(), 'report' => $this->report->getKey()])
            ->assertActionVisible('finalize')
            ->callAction('finalize')
            ->assertNotified()
            ->assertSee('data-report-status="ready_for_review"', false);

        $this->assertUntouched($this->report);
    }

    public function test_pdf_failure_leaves_the_report_ready_and_the_month_unlocked(): void
    {
        $fake = FakePdfReportGenerator::install(shouldFail: true);

        try {
            app(FinalizeMonthlyReportAction::class)->handle($this->report, $this->manager);
            $this->fail('Expected PdfGenerationException.');
        } catch (PdfGenerationException $exception) {
            $this->assertStringContainsString('Chromium exploded', $exception->getMessage());
        }

        $this->assertSame(1, $fake->conversions);
        $this->assertUntouched($this->report);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertTrue($this->manager->can('finalize', $this->report->fresh()));

        // The UI surfaces the failure and stays on Ready.
        $this->actingAs($this->manager);

        Livewire::test(ProjectReportEditor::class, ['record' => $this->project->getRouteKey(), 'report' => $this->report->getKey()])
            ->callAction('finalize')
            ->assertNotified('Chromium exploded (simulated).')
            ->assertSee('data-report-status="ready_for_review"', false);

        Livewire::test(ProjectReportEditor::class, ['record' => $this->project->getRouteKey(), 'report' => $this->report->getKey()])
            ->assertActionVisible('finalize');

        // Once the renderer works again, finalization succeeds normally.
        $fake->shouldFail = false;
        app(FinalizeMonthlyReportAction::class)->handle($this->report->fresh(), $this->manager);
        $this->assertSame(ReportStatus::Final, $this->report->fresh()->status);
    }

    public function test_a_database_failure_after_the_pdf_was_written_cleans_up_the_orphaned_file(): void
    {
        FakePdfReportGenerator::install();

        // Make the finalizer id violate the FK so the transaction fails after the PDF exists.
        $ghost = User::factory()->seoManager()->create();
        $ghostId = $ghost->getKey();
        DB::table('users')->where('id', $ghostId)->delete();
        $ghost->setAttribute('id', $ghostId);

        try {
            app(FinalizeMonthlyReportAction::class)->handle($this->report, $ghost);
            $this->fail('Expected the transaction to fail.');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        $this->assertUntouched($this->report);
        $this->assertSame([], Storage::disk('local')->allFiles(), 'The PDF written before the failed transaction must be removed.');
    }

    public function test_finalization_is_idempotent_and_final_history_is_locked_for_editing(): void
    {
        $fake = FakePdfReportGenerator::install();

        $final = app(FinalizeMonthlyReportAction::class)->handle($this->report, $this->manager)->fresh();
        $path = $final->generated_pdf_path;
        $snapshot = $final->snapshot_json;
        $finalizedAt = $final->finalized_at->toDateTimeString();

        Carbon::setTestNow('2026-10-05 12:00:00');

        $again = app(FinalizeMonthlyReportAction::class)->handle($final, User::factory()->superAdmin()->create());

        $this->assertSame($final->getKey(), $again->getKey());
        $this->assertSame(1, $fake->conversions);
        $this->assertSame($path, $again->fresh()->generated_pdf_path);
        $this->assertSame($snapshot, $again->fresh()->snapshot_json);
        $this->assertSame($finalizedAt, $again->fresh()->finalized_at->toDateTimeString());
        $this->assertSame($this->manager->getKey(), $again->fresh()->finalized_by);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertFalse(User::factory()->superAdmin()->create()->can('finalize', $again->fresh()));

        // The existing lock guards now protect every monthly module.
        $cycle = $this->cycle->fresh();

        foreach ([
            fn () => app(UpdateMonthlyReportDraftAction::class)->handle($final->fresh(), ['executive_summary' => 'late']),
            fn () => app(CreateMonthlyNoteAction::class)->handle($cycle, ['type' => 'win', 'body' => 'late'], $this->manager),
            fn () => app(CreateBacklinkAction::class)->handle($this->project, ['monthly_cycle_id' => $cycle->id, 'published_url' => 'https://late.example/x', 'type' => 'citation', 'status' => 'live'], $this->manager),
            fn () => app(SaveGscMonthlyMetricsAction::class)->handle($cycle, ['clicks' => 1, 'impressions' => 1], $this->manager),
            fn () => app(CreateContentItemAction::class)->handle($this->project, ['title' => 'late', 'content_type' => 'blog', 'status' => 'planned', 'monthly_cycle_id' => $cycle->id]),
            fn () => app(CreateTaskAction::class)->handle($this->project, ['title' => 'late', 'monthly_cycle_id' => $cycle->id], $this->manager),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected LockedMonthlyCycleException.');
            } catch (LockedMonthlyCycleException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('A strong month with steady organic growth.', $final->fresh()->executive_summary);
    }

    public function test_the_real_generator_is_the_container_default_and_fails_cleanly_without_a_browser(): void
    {
        $generator = app(PdfReportGenerator::class);
        $this->assertInstanceOf(PdfReportGenerator::class, $generator);
        $this->assertNotInstanceOf(FakePdfReportGenerator::class, $generator);

        config(['reports.chromium_path' => 'C:\\definitely\\missing\\chrome.exe']);

        try {
            $generator->generate(app(ReportSnapshotBuilder::class)->build($this->report), $this->report);
            $this->fail('Expected PdfGenerationException.');
        } catch (PdfGenerationException $exception) {
            $this->assertStringContainsString('CHROMIUM_PATH', $exception->getMessage());
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertUntouched($this->report);
    }
}
