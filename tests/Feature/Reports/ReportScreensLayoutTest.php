<?php

namespace Tests\Feature\Reports;

use App\Actions\Analytics\SaveGscQueryMetricsAction;
use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Reports\EnsureMonthlyReportAction;
use App\Actions\Reports\FinalizeMonthlyReportAction;
use App\Actions\Reports\MarkReportReadyAction;
use App\Actions\Reports\UnlockMonthlyReportAction;
use App\Actions\Reports\UpdateReportReviewNotesAction;
use App\Enums\ReportStatus;
use App\Filament\Pages\ReportsOverview;
use App\Filament\Resources\Projects\Pages\ProjectReportEditor;
use App\Filament\Resources\Projects\Pages\ProjectReports;
use App\Filament\Resources\Projects\Pages\ProjectReportSections;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Services\Reports\PdfReportGenerator;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\BuildsCompleteReports;
use Tests\Support\FakePdfReportGenerator;
use Tests\TestCase;

/**
 * Presentation of the redesigned reporting screens. Every status,
 * readiness value, version and audit event comes from the existing
 * actions and services; only placement and wording are asserted.
 */
class ReportScreensLayoutTest extends TestCase
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

    protected function editor(?User $user = null)
    {
        return Livewire::test(ProjectReportEditor::class, ['record' => $this->project->getRouteKey(), 'report' => $this->report->getKey()]);
    }

    protected function editorUrl(): string
    {
        return ProjectResource::getUrl('report', ['record' => $this->project, 'report' => $this->report]);
    }

    protected function finalise(): void
    {
        app(MarkReportReadyAction::class)->handle($this->report->fresh(), $this->manager);
        $this->report = app(FinalizeMonthlyReportAction::class)->handle($this->report->fresh(), $this->manager)->fresh();
    }

    public function test_project_reports_shows_the_workspace_header_summary_cards_and_history(): void
    {
        $this->actingAs($this->manager);
        $html = $this->get(ProjectResource::getUrl('reports', ['record' => $this->project]))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<h1[^>]*>\s*Casa Botanica\s*<\/h1>/s', $html));
        $this->assertSame(1, preg_match('/aria-current="page"[^>]*data-project-module="reports"|data-project-module="reports"[^>]*aria-current="page"/', $html), 'Reports is the active module');
        $this->assertSame(1, preg_match('/data-reports-header>.*?<h2[^>]*>Reports<\/h2>.*?Prepare, review and manage monthly SEO reports for this project\./s', $html));
        $this->assertStringNotContainsString('Back to project', $html);

        $this->assertSame(1, preg_match('/<div\b[^>]*data-reports-summary[^>]*>/s', $html, $summary));
        $this->assertStringContainsString('--cols-xl: repeat(4', $summary[0]);
        $this->assertSame(1, preg_match('/data-reports-card="report".*?data-reports-status="draft">Draft</s', $html));
        $this->assertSame(1, preg_match('/data-reports-card="readiness".*?data-reports-readiness="100">100%</s', $html));
        $this->assertSame(1, preg_match('/data-reports-card="sections".*?data-reports-sections="9\/9">9 \/ 9</s', $html));
        $this->assertSame(1, preg_match('/data-reports-card="version".*?data-reports-version="1">v1</s', $html));
        $this->assertStringContainsString('data-report-status-badge="draft"', $html);
        $this->assertStringContainsString('data-reports-open-current', $html);
        $this->assertSame(1, preg_match('/data-reports-table-heading>Report history</', $html));

        Livewire::test(ProjectReports::class, ['record' => $this->project->getRouteKey()])
            ->assertSee('September 2026')
            ->assertSee('v1')
            ->assertSee('Draft')
            ->assertSee('100%')
            ->assertSee('9 of 9 complete')
            ->assertTableActionVisible('open', $this->cycle)
            ->assertTableActionHidden('ensureReport', $this->cycle)
            ->assertActionDoesNotExist('viewProject');
    }

    public function test_project_reports_offers_a_compact_create_draft_state_and_stays_scoped(): void
    {
        $bare = Project::factory()->forClient(Client::factory()->create(['name' => 'BrightNest Interiors']))->ownedBy($this->executive)->create(['name' => 'BrightNest Manchester SEO']);
        $cycle = app(CreateMonthlyCycleAction::class)->handle($bare, new CyclePeriod(2026, 9));

        $this->actingAs($this->executive);
        $html = $this->get(ProjectResource::getUrl('reports', ['record' => $bare]))->assertOk()->getContent();

        $this->assertStringContainsString('data-report-status-badge="none"', $html);
        $this->assertSame(1, preg_match('/data-reports-empty>.*?No report started yet.*?Create a draft report when you are ready.*?data-reports-create-current/s', $html));
        $this->assertStringNotContainsString('data-reports-summary', $html);

        Livewire::test(ProjectReports::class, ['record' => $bare->getRouteKey()])
            ->callTableAction('ensureReport', $cycle)
            ->assertNotified('Draft report ready');

        $outsider = User::factory()->seoExecutive()->create();
        $this->actingAs($outsider);
        $this->get(ProjectResource::getUrl('reports', ['record' => $this->project]))->assertNotFound();
        $this->get(ProjectResource::getUrl('report', ['record' => $this->project, 'report' => $this->report]))->assertNotFound();
    }

    public function test_global_reports_overview_shows_status_counts_and_a_combined_client_project_column(): void
    {
        $this->finalise();
        app(UnlockMonthlyReportAction::class)->handle($this->report->fresh(), $this->admin, 'GA4 sessions were entered incorrectly.');

        $other = Project::factory()->forClient(Client::factory()->create(['name' => 'Secret Client']))->create(['name' => 'Secret Project']);
        $cycle = app(CreateMonthlyCycleAction::class)->handle($other, new CyclePeriod(2026, 9));
        $ready = app(EnsureMonthlyReportAction::class)->handle($cycle);
        $ready->sections()->update(['is_required' => false]);
        app(MarkReportReadyAction::class)->handle($ready, $this->manager);

        $this->actingAs($this->manager);
        $html = $this->get(ReportsOverview::getUrl())->assertOk()->getContent();

        $this->assertStringContainsString('Review report progress across your accessible projects.', $html);
        $this->assertSame(1, preg_match('/<div\b[^>]*data-reports-overview-summary[^>]*>/s', $html, $summary));
        $this->assertStringContainsString('--cols-xl: repeat(4', $summary[0]);
        $this->assertSame(1, preg_match('/data-reports-overview-card="draft".*?data-reports-overview-count="1"/s', $html));
        $this->assertSame(1, preg_match('/data-reports-overview-card="ready".*?data-reports-overview-count="1"/s', $html));
        $this->assertSame(1, preg_match('/data-reports-overview-card="final".*?data-reports-overview-count="0"/s', $html));
        $this->assertSame(1, preg_match('/data-reports-overview-card="correction".*?data-reports-overview-count="1"/s', $html));

        Livewire::test(ReportsOverview::class)
            ->assertSee('Casa Botanica')
            ->assertSee('Casa Botanica Ltd')
            ->assertSee('Secret Project')
            ->assertSee('Draft · correction')
            ->assertSee('Ready for review')
            ->assertSee('v2')
            ->assertSee('1 superseded')
            ->assertSee('Client / Project')
            ->assertTableActionVisible('open', $ready);

        // The executive only sees their own project, and never a report-editing action here.
        $this->actingAs($this->executive);
        Livewire::test(ReportsOverview::class)
            ->assertSee('Casa Botanica')
            ->assertDontSee('Secret Project')
            ->assertTableActionDoesNotExist('finalize', record: $this->report->fresh());
        $this->assertSame(1, preg_match('/data-reports-overview-card="draft".*?data-reports-overview-count="1"/s', $this->get(ReportsOverview::getUrl())->getContent()));
    }

    public function test_draft_editor_renders_the_guided_header_readiness_sections_and_action_hierarchy(): void
    {
        $this->cycle->gscQueryMetrics()->delete();

        $this->actingAs($this->executive);
        $html = $this->get($this->editorUrl())->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<h1[^>]*>\s*Casa Botanica\s*<\/h1>/s', $html));
        $this->assertSame(1, preg_match('/aria-current="page"[^>]*data-project-module="reports"|data-project-module="reports"[^>]*aria-current="page"/', $html));
        $this->assertSame(1, preg_match('/data-report-header data-report-status="draft" data-readiness="88".*?<h2[^>]*>September 2026 SEO report<\/h2>/s', $html));
        $this->assertStringContainsString('data-report-status-badge="draft"', $html);
        $this->assertStringContainsString('Casa Botanica • Casa Botanica Ltd', $html);
        $this->assertSame(1, preg_match('/data-report-version="1">Version v1 · in preparation</', $html));
        $this->assertSame(1, preg_match('/data-report-workflow="1".*?data-workflow-step="1" data-workflow-state="current".*?data-workflow-step="2" data-workflow-state="todo"/s', $html));
        $this->assertStringNotContainsString('data-period-locked', $html);
        $this->assertStringNotContainsString('data-report-unlock', $html);

        // Readiness summary: bar, label, missing sections with reasons.
        $this->assertSame(1, preg_match('/data-readiness-summary data-readiness-ready="0".*?data-readiness-percentage>88%<.*?aria-valuenow="88".*?width: 88%.*?data-readiness-label>8 \/ 9 required sections complete</s', $html));
        $this->assertSame(1, preg_match('/data-missing-sections>.*?Missing required information.*?data-missing="top_keywords">.*?Top Keywords.*?Enter at least one Search Console query\./s', $html));
        $this->assertStringNotContainsString('data-report-ready', $html);

        // Section overview and section cards.
        $this->assertSame(1, preg_match('/data-section-nav-item="top_keywords" data-section-nav-state="missing"/', $html));
        $this->assertSame(1, preg_match('/data-section-nav-item="rankings" data-section-nav-state="complete"/', $html));
        $this->assertSame(1, preg_match('/id="section-organic_search".*?data-report-section="organic_search".*?data-section-state="complete".*?>Clicks<.*?>1,200<.*?>CTR<.*?>2.5%</s', $html));
        $this->assertSame(1, preg_match('/id="section-rankings".*?data-rankings-summary.*?1 improved.*?data-rankings-keyword="villa rentals marbella">.*?>24<.*?>8<.*?Improved 16 positions/s', $html));
        $this->assertSame(1, preg_match('/id="section-backlinks".*?data-backlinks-progress="backlinks".*?1 \/ 50.*?2%.*?data-backlinks-progress="guest_posts".*?1 \/ 5.*?20%/s', $html));
        $this->assertSame(1, preg_match('/id="section-recommendations".*?data-report-notes="recommendations">.*?Add FAQ schema to villa pages\..*?data-report-notes="next-month-focus">.*?Publish the pricing guide\./s', $html));
        $this->assertSame(1, preg_match('/id="section-landing_pages".*?>\/villas</s', $html), 'landing pages show a clean path');
        $this->assertStringNotContainsString('>https://casabotanica.example/villas<', $html);
        $this->assertSame(1, preg_match('/data-summary-sources>.*?1 win.*?1 challenge.*?0 observations/s', $html));
        $this->assertStringContainsString('data-section-commentary="site_authority"', $html);
        $this->assertStringNotContainsString('custom_text', preg_replace('/<script.*?<\/script>/s', '', $html));

        // Draft actions: mark ready primary, preview secondary, no finalize/download/unlock.
        $this->editor()
            ->assertActionVisible('preview')
            ->assertActionVisible('markReady')
            ->assertActionHidden('finalize')
            ->assertActionHidden('downloadPdf')
            ->assertActionHidden('unlock')
            ->assertActionVisible('editExecutiveSummary')
            ->assertActionVisible('editSectionText')
            ->assertActionHidden('editReviewNotes')
            ->assertActionDoesNotExist('delete');
        $this->assertStringNotContainsString('data-internal-notes', $html, 'an executive never sees the internal notes card');
    }

    public function test_ready_state_shows_manager_only_finalise_and_the_internal_notes_card(): void
    {
        app(MarkReportReadyAction::class)->handle($this->report->fresh(), $this->manager);
        app(UpdateReportReviewNotesAction::class)->handle($this->report->fresh(), 'INTERNAL: double-check the DR figure.');

        $this->actingAs($this->manager);
        $html = $this->get($this->editorUrl())->assertOk()->getContent();

        $this->assertStringContainsString('data-report-status="ready_for_review"', $html);
        $this->assertSame(1, preg_match('/data-report-workflow="2".*?data-workflow-step="1" data-workflow-state="done".*?data-workflow-step="2" data-workflow-state="current"/s', $html));
        $this->assertSame(1, preg_match('/data-report-ready>All required sections are complete\. Ready for manager review\./', $html));
        $this->assertSame(1, preg_match('/data-internal-notes.*?Internal review notes.*?Internal only.*?will not appear in the client report.*?data-review-notes>INTERNAL: double-check the DR figure\./s', $html));

        $this->editor()
            ->assertActionVisible('finalize')
            ->assertActionVisible('preview')
            ->assertActionVisible('editReviewNotes')
            ->assertActionHidden('markReady')
            ->assertActionHidden('downloadPdf');

        $this->actingAs($this->executive);
        $this->editor()->assertActionHidden('finalize')->assertActionHidden('editReviewNotes');
        $this->assertStringNotContainsString('INTERNAL: double-check', $this->get($this->editorUrl())->getContent());

        // Review notes never reach the client preview.
        $this->actingAs($this->manager);
        $this->get(route('filament.admin.reports.preview', ['project' => $this->project->getKey(), 'report' => $this->report]))->assertOk()
            ->assertDontSee('INTERNAL: double-check')
            ->assertSee('data-report-toolbar-state="Ready for review preview"', false);
    }

    public function test_final_state_is_calm_locked_and_offers_view_download_and_super_admin_unlock_only(): void
    {
        $this->finalise();

        $this->actingAs($this->manager);
        $html = $this->get($this->editorUrl())->assertOk()->getContent();

        $this->assertStringContainsString('data-report-status="final"', $html);
        $this->assertStringContainsString('data-report-status-badge="final"', $html);
        $this->assertSame(1, preg_match('/data-period-locked[^>]*>.*?Locked/s', $html));
        $this->assertStringContainsString('Reporting period locked', $html);
        $this->assertSame(1, preg_match('/data-report-version="1">Current version v1</', $html));
        $this->assertSame(1, preg_match('/data-finalized-by>Finalised by Morgan Manager</', $html));
        $this->assertSame(1, preg_match('/data-finalized-at>15 Sep 2026, 10:00</', $html));
        $this->assertSame(1, preg_match('/data-report-workflow="3".*?data-workflow-step="3" data-workflow-state="current"/s', $html));
        $this->assertSame(1, preg_match('/data-report-ready>Report data is complete\. This version has been finalised\./', $html));
        $this->assertSame(1, preg_match('/data-version-row="1" data-version-state="final".*?Current final report.*?>\s*View\s*<.*?Download PDF/s', $html));
        $this->assertSame(1, preg_match('/data-audit-timeline>.*?data-audit-event="report_finalized" data-audit-version="1">.*?v1 finalised by Morgan Manager.*?data-audit-event="report_marked_ready"/s', $html), 'newest event first, human wording');
        $this->assertStringNotContainsString('report_finalized<', $html);
        $this->assertStringNotContainsString('data-report-unlock', $html, 'a manager cannot unlock');

        $this->editor()
            ->assertActionVisible('downloadPdf')
            ->assertActionVisible('preview')
            ->assertActionHidden('markReady')
            ->assertActionHidden('finalize')
            ->assertActionHidden('unlock')
            ->assertActionHidden('editExecutiveSummary')
            ->assertActionHidden('editSectionText');

        $this->actingAs($this->executive);
        $this->editor()->assertActionHidden('unlock')->assertActionVisible('downloadPdf');

        $this->actingAs($this->admin);
        $this->assertStringContainsString('data-report-unlock', $this->get($this->editorUrl())->getContent());
        $this->editor()->assertActionVisible('unlock');
    }

    public function test_correction_state_shows_the_banner_previous_version_and_history(): void
    {
        $this->finalise();
        app(UnlockMonthlyReportAction::class)->handle($this->report->fresh(), $this->admin, 'GA4 sessions were entered incorrectly.');

        $this->actingAs($this->executive);
        $html = $this->get($this->editorUrl())->assertOk()->getContent();

        $this->assertStringContainsString('data-report-status-badge="draft" data-report-correction="1"', $html);
        $this->assertStringContainsString('Draft · Correction', $html);
        $this->assertSame(1, preg_match('/data-correction-banner.*?Correction in progress.*?Current working version.*?v2 Draft.*?previous final report has been preserved.*?Previous final.*?data-previous-version="1">v1 • Finalised 15 Sep 2026 by Morgan Manager.*?Unlock reason: GA4 sessions were entered incorrectly\..*?View Previous Final.*?Download Previous PDF/s', $html));
        $this->assertSame(1, preg_match('/data-version-row="2" data-version-state="draft".*?Current working version.*?data-version-row="1" data-version-state="superseded".*?Superseded.*?Morgan Manager.*?Unlock reason: GA4 sessions were entered incorrectly\..*?>\s*View\s*<.*?Download PDF/s', $html));
        $this->assertSame(1, preg_match('/data-audit-event="report_unlocked_for_correction" data-audit-version="2">.*?v1 unlocked for correction by Ava Admin · v2 started.*?Reason: GA4 sessions were entered incorrectly\./s', $html));
        $this->assertSame(1, preg_match('/data-reports-card="report".*?data-reports-status="draft">Draft<.*?Correction in progress/s', $this->get(ProjectResource::getUrl('reports', ['record' => $this->project]))->getContent()));

        // The archived version stays viewable with its own toolbar and download.
        $revision = $this->report->fresh()->revisions()->firstOrFail();
        $params = ['project' => $this->project->getKey(), 'report' => $this->report->getKey(), 'revision' => $revision->getKey()];
        $this->get(route('filament.admin.reports.revisions.preview', $params))->assertOk()
            ->assertSee('data-report-toolbar-state="Superseded v1"', false)
            ->assertSee('data-report-toolbar-back', false)
            ->assertSee('data-report-toolbar-pdf', false)
            ->assertSee('data-metric="clicks">1,200<', false);
        $this->get(route('filament.admin.reports.revisions.pdf', $params))->assertOk();
    }

    public function test_preview_has_a_browser_toolbar_that_never_reaches_the_pdf(): void
    {
        $this->actingAs($this->manager);
        $preview = $this->get(route('filament.admin.reports.preview', ['project' => $this->project->getKey(), 'report' => $this->report]))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<div class="toolbar" data-report-toolbar data-report-toolbar-state="Draft preview">.*?data-report-toolbar-back>← Back to report<\/a>/s', $preview));
        $this->assertStringContainsString('href="'.$this->editorUrl().'"', $preview);
        $this->assertStringNotContainsString('data-report-toolbar-pdf', $preview, 'no PDF while draft');
        $this->assertStringContainsString('Preview —', $preview);
        $this->assertStringContainsString('@media print { .toolbar { display: none !important; } }', $preview);
        $this->assertStringContainsString('Monthly SEO Report', $preview);
        $this->assertStringNotContainsString('fi-section', $preview, 'the client report never uses admin styling');

        $this->finalise();
        $generator = app(PdfReportGenerator::class);
        $this->assertInstanceOf(FakePdfReportGenerator::class, $generator);
        $this->assertNotNull($generator->lastHtml);
        $this->assertStringNotContainsString('data-report-toolbar', $generator->lastHtml, 'the PDF HTML carries no toolbar');
        $this->assertStringNotContainsString('@media screen', $generator->lastHtml, 'the PDF HTML carries no screen-only canvas styles');

        $final = $this->get(route('filament.admin.reports.preview', ['project' => $this->project->getKey(), 'report' => $this->report]))->assertOk()->getContent();
        $this->assertStringContainsString('data-report-toolbar-state="Final report · v1"', $final);
        $this->assertStringContainsString('data-report-toolbar-pdf', $final);
        $this->assertStringNotContainsString('Preview —', $final);
    }

    public function test_report_sections_settings_render_as_ordered_rows_for_managers_only(): void
    {
        $this->actingAs($this->manager);
        $html = $this->get(ProjectResource::getUrl('report-sections', ['record' => $this->project]))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/aria-current="page"[^>]*data-project-module="report-sections"|data-project-module="report-sections"[^>]*aria-current="page"/', $html));
        $this->assertSame(1, preg_match('/data-report-sections-header>.*?<h2[^>]*>Report sections<\/h2>.*?Choose which sections appear in future monthly reports\./s', $html));
        $this->assertSame(1, preg_match('/data-future-reports-warning.*?Changes affect future reports only\. Existing reports keep their saved section layout\./s', $html));
        $this->assertStringContainsString('10 of 10 sections included · 9 required for readiness.', $html);
        $this->assertSame(1, preg_match('/data-report-section="executive_summary" data-enabled="1" data-required="1" data-sort-order="10">.*?data-section-position>1<.*?Executive Summary.*?Included.*?Required/s', $html));
        $this->assertSame(1, preg_match('/data-report-section="audience_country" data-enabled="1" data-required="0".*?Optional/s', $html));
        $this->assertStringNotContainsString('font-mono', $html, 'raw section keys are not displayed');
        $this->assertStringNotContainsString('Back to project', $html);

        Livewire::test(ProjectReportSections::class, ['record' => $this->project->getRouteKey()])
            ->assertActionVisible('editSections')
            ->assertActionDoesNotExist('viewProject');

        $this->actingAs($this->executive);
        $this->get(ProjectResource::getUrl('report-sections', ['record' => $this->project]))->assertForbidden();
    }

    public function test_report_lifecycle_and_readiness_rules_are_unchanged(): void
    {
        $this->actingAs($this->executive);

        // An incomplete draft still cannot be marked ready, and the reason is shown.
        $this->cycle->gscQueryMetrics()->delete();
        $this->editor()->callAction('markReady')->assertNotified();
        $this->assertSame(ReportStatus::Draft, $this->report->fresh()->status);

        // Only the executive's own project's report reaches Ready, and finalising remains a manager step.
        app(SaveGscQueryMetricsAction::class)->handle($this->cycle, [['query' => 'villa rentals', 'clicks' => 10, 'impressions' => 100]], $this->manager);
        $this->editor()->callAction('markReady')->assertNotified('Report marked Ready for Review');
        $this->assertSame(ReportStatus::ReadyForReview, $this->report->fresh()->status);
        $this->editor()->mountAction('finalize')->callMountedAction();
        $this->assertSame(ReportStatus::ReadyForReview, $this->report->fresh()->status);

        $this->actingAs($this->manager);
        $this->editor()->callAction('finalize')->assertNotified('Report finalized and reporting period locked');
        $this->assertSame(ReportStatus::Final, $this->report->fresh()->status);
        $this->assertTrue($this->cycle->fresh()->isLocked());
    }
}
