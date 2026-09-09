<?php

namespace Tests\Feature\Reports;

use App\Actions\Analytics\SaveGscMonthlyMetricsAction;
use App\Actions\Backlinks\CreateBacklinkAction;
use App\Actions\Notes\CreateMonthlyNoteAction;
use App\Actions\Reports\FinalizeMonthlyReportAction;
use App\Actions\Reports\MarkReportReadyAction;
use App\Actions\Reports\UpdateMonthlyReportDraftAction;
use App\Enums\MonthlyCycleStatus;
use App\Enums\ReportStatus;
use App\Exceptions\ReportDataChangedException;
use App\Exceptions\ReportNotReadyException;
use App\Filament\Resources\Projects\Pages\ProjectReportEditor;
use App\Models\MonthlyReport;
use App\Models\RankingSnapshot;
use App\Models\User;
use App\Services\Reports\PdfReportGenerator;
use App\Services\Reports\ReportSnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\BuildsCompleteReports;
use Tests\Support\FakePdfReportGenerator;
use Tests\TestCase;

/**
 * The final snapshot / PDF and the locked monthly data must describe the
 * same state. Source changes that land while Chromium is rendering abort
 * the finalization instead of being silently locked in.
 */
class ReportFinalizationConsistencyTest extends TestCase
{
    use BuildsCompleteReports;
    use RefreshDatabase;

    protected FakePdfReportGenerator $pdf;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-30 10:00:00');
        Storage::fake('local');
        $this->pdf = FakePdfReportGenerator::install();

        $this->buildCompleteReport();
        app(MarkReportReadyAction::class)->handle($this->report, $this->manager);
        $this->report = $this->report->fresh();
    }

    protected function assertStillReadyAndUnlocked(MonthlyReport $report): void
    {
        $fresh = $report->fresh();
        $cycle = $fresh->monthlyCycle->fresh();

        $this->assertSame(ReportStatus::ReadyForReview, $fresh->status);
        $this->assertNull($fresh->snapshot_json);
        $this->assertNull($fresh->generated_pdf_path);
        $this->assertNull($fresh->generated_at);
        $this->assertNull($fresh->finalized_at);
        $this->assertNull($fresh->finalized_by);
        $this->assertSame(MonthlyCycleStatus::Open, $cycle->status);
        $this->assertNull($cycle->locked_at);
        $this->assertNull($cycle->locked_by);
        $this->assertSame([], Storage::disk('local')->allFiles(), 'No PDF may survive an aborted finalization.');
    }

    public function test_unchanged_source_data_finalizes_normally(): void
    {
        $final = app(FinalizeMonthlyReportAction::class)->handle($this->report, $this->manager)->fresh();

        $this->assertSame(ReportStatus::Final, $final->status);
        $this->assertSame(1, $this->pdf->conversions);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertTrue($this->cycle->fresh()->isLocked());
        $this->assertSame($this->manager->getKey(), $final->finalized_by);
    }

    public function test_fingerprint_ignores_volatile_values_but_tracks_every_report_source(): void
    {
        $builder = app(ReportSnapshotBuilder::class);
        $base = $builder->fingerprint($builder->build($this->report, $this->manager, now()));

        Carbon::setTestNow('2026-10-01 12:00:00');
        $this->assertSame($base, $builder->fingerprint($builder->build($this->report->fresh(), User::factory()->superAdmin()->create(), now())));
        $this->assertSame($base, $builder->fingerprint($builder->build($this->report->fresh())));

        $changes = [
            'gsc summary' => fn () => app(SaveGscMonthlyMetricsAction::class)->handle($this->cycle, ['clicks' => 1201, 'impressions' => 48000, 'ctr' => 2.5, 'average_position' => 14.3], $this->manager),
            'note' => fn () => app(CreateMonthlyNoteAction::class)->handle($this->cycle, ['type' => 'win', 'body' => 'Late win.'], $this->manager),
            'ranking' => fn () => RankingSnapshot::factory()->forKeyword($this->keyword)->forCycle($this->cycle)->at('2026-09-29 09:00', 3)->create(),
            'backlink' => fn () => app(CreateBacklinkAction::class)->handle($this->project, ['monthly_cycle_id' => $this->cycle->id, 'published_url' => 'https://late.example/link', 'type' => 'citation', 'status' => 'live'], $this->manager),
            'executive summary' => fn () => app(UpdateMonthlyReportDraftAction::class)->handle($this->report->fresh(), ['executive_summary' => 'Rewritten.']),
            'section commentary' => fn () => $this->report->sections()->where('section_key', 'rankings')->update(['custom_text' => 'Late commentary']),
            'target snapshot' => fn () => $this->cycle->targets()->where('target_key', 'blogs')->update(['target_value' => 9]),
        ];

        $previous = $base;

        foreach ($changes as $label => $change) {
            $change();
            $current = $builder->fingerprint($builder->build($this->report->fresh()));
            $this->assertNotSame($previous, $current, "A {$label} change must alter the fingerprint.");
            $previous = $current;
        }
    }

    public function test_source_data_changed_during_pdf_generation_aborts_finalization_cleanly(): void
    {
        $this->pdf->duringConversion = function (): void {
            // Another authorized user corrects a metric while Chromium renders.
            app(SaveGscMonthlyMetricsAction::class)->handle($this->cycle->fresh(), ['clicks' => 1350, 'impressions' => 48000, 'ctr' => 2.5, 'average_position' => 14.3], $this->manager);
        };

        try {
            app(FinalizeMonthlyReportAction::class)->handle($this->report, $this->manager);
            $this->fail('Expected ReportDataChangedException.');
        } catch (ReportDataChangedException $exception) {
            $this->assertSame('Report data for September 2026 changed during finalization. Please review and finalize again.', $exception->getMessage());
        }

        $this->assertSame(1, $this->pdf->conversions, 'The PDF was generated once and then discarded.');
        $this->assertStillReadyAndUnlocked($this->report);

        // The corrected source data is what remains, not the stale value.
        $this->assertSame(1350, $this->cycle->fresh()->gscMonthlyMetric->clicks);

        // A subsequent clean finalization succeeds and captures the corrected data.
        $this->pdf->duringConversion = null;

        $final = app(FinalizeMonthlyReportAction::class)->handle($this->report->fresh(), $this->manager)->fresh();

        $this->assertSame(ReportStatus::Final, $final->status);
        $this->assertSame(2, $this->pdf->conversions);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertSame(1350, collect($final->snapshot_json['sections'])->firstWhere('key', 'organic_search')['data']['clicks']);
        $this->assertTrue($this->cycle->fresh()->isLocked());
        $this->assertNotNull($final->finalized_at);
    }

    public function test_data_removed_during_pdf_generation_is_caught_by_the_readiness_re_run(): void
    {
        $this->pdf->duringConversion = function (): void {
            $this->cycle->gscQueryMetrics()->delete();
        };

        try {
            app(FinalizeMonthlyReportAction::class)->handle($this->report, $this->manager);
            $this->fail('Expected ReportNotReadyException.');
        } catch (ReportNotReadyException $exception) {
            $this->assertSame(['top_keywords'], $exception->readiness->missing()->map(fn ($s) => $s->key->value)->all());
        }

        $this->assertStillReadyAndUnlocked($this->report);
    }

    public function test_the_editor_surfaces_the_abort_and_keeps_the_report_ready(): void
    {
        $this->pdf->duringConversion = function (): void {
            app(CreateMonthlyNoteAction::class)->handle($this->cycle->fresh(), ['type' => 'recommendation', 'body' => 'Added while finalizing.'], $this->manager);
        };

        $this->actingAs($this->manager);

        Livewire::test(ProjectReportEditor::class, ['record' => $this->project->getRouteKey(), 'report' => $this->report->getKey()])
            ->callAction('finalize')
            ->assertNotified('Report data for September 2026 changed during finalization. Please review and finalize again.')
            ->assertSee('data-report-status="ready_for_review"', false);

        $this->assertStillReadyAndUnlocked($this->report);

        Livewire::test(ProjectReportEditor::class, ['record' => $this->project->getRouteKey(), 'report' => $this->report->getKey()])
            ->assertActionVisible('finalize');
    }

    public function test_pdf_bytes_go_through_the_storage_api_never_a_local_disk_path(): void
    {
        // The report disk may be remote: the generator must only use put/exists/delete/download.
        Storage::fake('s3');
        config(['reports.pdf_disk' => 's3']);

        $final = app(FinalizeMonthlyReportAction::class)->handle($this->report, $this->manager)->fresh();

        Storage::disk('s3')->assertExists($final->generated_pdf_path);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertStringStartsWith('%PDF', Storage::disk('s3')->get($final->generated_pdf_path));

        $source = file_get_contents(app_path('Services/Reports/PdfReportGenerator.php'));
        $this->assertStringNotContainsString('->path(', $source, 'The generator must not resolve a local path on the report disk.');
        $this->assertStringContainsString('storage_path(\'app/tmp/reports/', $source, 'Chromium works in a local temp directory, not on the report disk.');
    }

    public function test_no_sandbox_is_never_enabled_by_default(): void
    {
        $this->assertSame([], config('reports.chromium_flags'));

        $source = file_get_contents(app_path('Services/Reports/PdfReportGenerator.php'));
        $this->assertStringNotContainsString('--no-sandbox', $source);

        config(['reports.chromium_flags' => ['--no-sandbox']]);
        $this->assertSame(['--no-sandbox'], config('reports.chromium_flags'));
        $this->assertInstanceOf(PdfReportGenerator::class, app(PdfReportGenerator::class));
    }
}
