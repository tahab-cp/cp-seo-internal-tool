<?php

namespace Tests\Feature\Reports;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Reports\EnsureMonthlyReportAction;
use App\Actions\Reports\FinalizeMonthlyReportAction;
use App\Actions\Reports\MarkReportReadyAction;
use App\Actions\Reports\UnlockMonthlyReportAction;
use App\Enums\ReportStatus;
use App\Filament\Resources\Projects\Pages\ProjectReports;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\BuildsCompleteReports;
use Tests\Support\FakePdfReportGenerator;
use Tests\TestCase;

/**
 * Presentation of the Report History cards on Project → Reports. Status,
 * readiness, versions and finalisation details come from the existing
 * actions and services; only their placement and wording are asserted.
 */
class ReportHistoryLayoutTest extends TestCase
{
    use BuildsCompleteReports;
    use RefreshDatabase;

    protected User $admin;

    protected User $executive;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');
        Storage::fake('local');
        FakePdfReportGenerator::install();

        $this->admin = User::factory()->superAdmin()->create(['name' => 'Ava Admin']);
        $this->executive = User::factory()->seoExecutive()->create(['name' => 'Eli Executive']);
        $this->buildCompleteReport($this->executive);
    }

    protected function html(): string
    {
        return $this->get(ProjectResource::getUrl('reports', ['record' => $this->project]))->assertOk()->getContent();
    }

    protected function card(string $html, int $cycleId): string
    {
        $this->assertSame(1, preg_match('/<div[^>]*data-report-history-card="'.$cycleId.'".*?data-report-history-card="|<div[^>]*data-report-history-card="'.$cycleId.'".*$/s', $html, $m), 'card for cycle '.$cycleId);

        return $m[0];
    }

    public function test_history_renders_cards_with_period_status_version_metrics_and_missing_chips(): void
    {
        $this->cycle->gscQueryMetrics()->delete();
        $this->cycle->monthlyNotes()->whereIn('type', ['recommendation', 'next_month_focus'])->delete();

        $this->actingAs($this->manager);
        $html = $this->html();

        $this->assertSame(1, preg_match('/data-reports-table-heading>Report history<.*?data-reports-history-helper>View previous monthly reports and their current status\./s', $html));
        $this->assertStringNotContainsString('One report per reporting month.', $html);
        $this->assertStringContainsString('fi-ta-content-grid', $html, 'records render as cards, not table rows');
        $this->assertStringNotContainsString('<th', substr($html, strpos($html, 'data-reports-table-heading')), 'no wide table header');

        $card = $this->card($html, $this->cycle->getKey());
        $this->assertSame(1, preg_match('/data-history-period>September 2026</', $card));
        $this->assertSame(1, preg_match('/data-history-badges>.*?data-history-current-badge.*?Current.*?data-report-status-badge="draft".*?Draft.*?data-history-version="1".*?v1/s', $card));
        $this->assertSame(1, preg_match('/<div\b[^>]*data-history-metrics[^>]*>/s', $card, $metrics));
        $this->assertStringContainsString('--cols-sm: repeat(2', $metrics[0]);
        $this->assertStringContainsString('--cols-xl: repeat(3', $metrics[0]);
        $this->assertSame(1, preg_match('/data-history-metric="readiness".*?data-history-readiness="77">77%<.*?aria-valuenow="77".*?width: 77%/s', $card));
        $this->assertSame(1, preg_match('/data-history-sections="7\/9">7 of 9 complete</', $card));
        $this->assertSame(1, preg_match('/data-history-metric="finalised".*?data-history-finalised="">—</s', $card));
        $this->assertSame(1, preg_match('/data-history-missing>.*?Missing.*?data-history-missing-section="top_keywords".*?Top Keywords.*?data-history-missing-section="recommendations".*?Recommendations/s', $card));
        $this->assertStringNotContainsString('Missing: Top Keywords, Recommendations', $card, 'missing sections are chips, not a sentence');
        $this->assertStringNotContainsString('data-history-complete', $card);
        $this->assertStringContainsString('Open report', $card);

        Livewire::test(ProjectReports::class, ['record' => $this->project->getRouteKey()])
            ->assertCanSeeTableRecords([$this->cycle])
            ->assertTableActionVisible('open', $this->cycle)
            ->assertTableActionHidden('ensureReport', $this->cycle)
            ->assertTableActionHidden('downloadPdf', $this->cycle)
            ->assertTableActionDoesNotExist('finalize', record: $this->cycle)
            ->assertTableActionDoesNotExist('unlock', record: $this->cycle);
    }

    public function test_complete_ready_and_final_states_render_their_own_wording_and_actions(): void
    {
        $this->actingAs($this->manager);
        $card = $this->card($this->html(), $this->cycle->getKey());
        $this->assertSame(1, preg_match('/data-history-complete>.*?All required sections complete/s', $card));
        $this->assertStringNotContainsString('data-history-missing>', $card);

        app(MarkReportReadyAction::class)->handle($this->report->fresh(), $this->manager);
        $card = $this->card($this->html(), $this->cycle->getKey());
        $this->assertStringContainsString('data-report-status-badge="ready_for_review"', $card);
        $this->assertStringContainsString('Ready for review', $card);
        $this->assertStringContainsString('Review report', $card);

        $this->report = app(FinalizeMonthlyReportAction::class)->handle($this->report->fresh(), $this->manager)->fresh();
        $card = $this->card($this->html(), $this->cycle->getKey());
        $this->assertStringContainsString('data-report-status-badge="final"', $card);
        $this->assertSame(1, preg_match('/data-history-readiness="100">100%<.*?var\(--success-500\)/s', $card));
        $this->assertSame(1, preg_match('/data-history-sections="9\/9">9 of 9 complete</', $card));
        $this->assertSame(1, preg_match('/data-history-finalised="2026-09-15">15 Sep 2026<.*?data-history-finalised-by>Morgan Manager</s', $card));
        $this->assertStringContainsString('data-history-locked', $card);
        $this->assertStringContainsString('View report', $card);
        $this->assertStringContainsString('Download PDF', $card);
        $this->assertStringNotContainsString('data-history-previous-versions', $card);

        Livewire::test(ProjectReports::class, ['record' => $this->project->getRouteKey()])
            ->assertTableActionVisible('open', $this->cycle)
            ->assertTableActionVisible('downloadPdf', $this->cycle);
    }

    public function test_correction_and_previous_versions_render_inside_the_month_card(): void
    {
        app(MarkReportReadyAction::class)->handle($this->report->fresh(), $this->manager);
        app(FinalizeMonthlyReportAction::class)->handle($this->report->fresh(), $this->manager);
        app(UnlockMonthlyReportAction::class)->handle($this->report->fresh(), $this->admin, 'GA4 sessions were entered incorrectly.');

        $this->actingAs($this->executive);
        $card = $this->card($this->html(), $this->cycle->getKey());

        $this->assertStringContainsString('data-report-status-badge="draft" data-report-correction="1"', $card);
        $this->assertStringContainsString('Draft · Correction', $card);
        $this->assertSame(1, preg_match('/data-history-version="2".*?v2/s', $card));
        $this->assertSame(1, preg_match('/data-history-previous-final="1">Previous final: v1 • Finalised 15 Sep 2026 by Morgan Manager</', $card));
        $this->assertStringContainsString('Continue correction', $card);
        $this->assertStringNotContainsString('data-history-previous-versions', $card, 'the correction line replaces the previous-versions line while correcting');

        // Re-finalised: the superseded version is listed compactly with its own links.
        app(MarkReportReadyAction::class)->handle($this->report->fresh(), $this->manager);
        app(FinalizeMonthlyReportAction::class)->handle($this->report->fresh(), $this->manager);
        $card = $this->card($this->html(), $this->cycle->getKey());
        $revision = $this->report->fresh()->revisions()->firstOrFail();

        $this->assertSame(1, preg_match('/data-history-previous-versions>.*?Previous versions:.*?data-history-revision="1">.*?v1.*?href="[^"]*revisions\/'.$revision->getKey().'\/preview"[^>]*>.*?View.*?<\/a>.*?href="[^"]*revisions\/'.$revision->getKey().'\/pdf"[^>]*>.*?PDF.*?<\/a>/s', $card));
        $this->assertStringNotContainsString('data-history-previous-final', $card);
        $this->assertSame(ReportStatus::Final, $this->report->fresh()->status);
        $this->assertSame(2, $this->report->fresh()->version);
    }

    public function test_months_without_a_report_and_many_months_stack_cleanly(): void
    {
        $august = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 8));
        $july = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 7));
        app(EnsureMonthlyReportAction::class)->handle($july);

        $this->actingAs($this->manager);
        $html = $this->html();

        $this->assertSame(1, preg_match('/data-report-history-card="'.$this->cycle->getKey().'".*?data-report-history-card="'.$august->getKey().'".*?data-report-history-card="'.$july->getKey().'"/s', $html), 'newest month first');

        $augustCard = $this->card($html, $august->getKey());
        $this->assertStringContainsString('data-report-status-badge="none"', $augustCard);
        $this->assertStringContainsString('data-history-not-started', $augustCard);
        $this->assertStringNotContainsString('data-history-metrics', $augustCard);
        $this->assertStringNotContainsString('data-history-current-badge', $augustCard, 'only the current month is flagged');
        $this->assertStringContainsString('Create draft report', $augustCard);

        // July has no data: only the Backlinks section counts (the package snapshot carries a target), so 1 of 9.
        $julyCard = $this->card($html, $july->getKey());
        $this->assertSame(1, preg_match('/data-history-readiness="11">11%</', $julyCard));
        $this->assertSame(1, preg_match('/data-history-sections="1\/9">1 of 9 complete</', $julyCard));
        $this->assertSame(8, substr_count($julyCard, 'data-history-missing-section='), 'every missing required section is a chip');

        Livewire::test(ProjectReports::class, ['record' => $this->project->getRouteKey()])
            ->assertCanSeeTableRecords([$this->cycle, $august, $july])
            ->assertTableActionVisible('ensureReport', $august)
            ->callTableAction('ensureReport', $august)
            ->assertNotified('Draft report ready');

        $this->assertNotNull($august->fresh()->monthlyReport);
    }

    public function test_unrelated_executives_stay_blocked(): void
    {
        $outsider = User::factory()->seoExecutive()->create();
        $this->actingAs($outsider);

        $this->get(ProjectResource::getUrl('reports', ['record' => $this->project]))->assertNotFound();
    }
}
