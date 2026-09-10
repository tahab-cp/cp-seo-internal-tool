<?php

namespace Tests\Feature\Reports;

use App\Actions\Reports\FinalizeMonthlyReportAction;
use App\Actions\Reports\MarkReportReadyAction;
use App\Actions\Reports\UnlockMonthlyReportAction;
use App\Enums\ReportStatus;
use App\Filament\Resources\Projects\Pages\ProjectReportEditor;
use App\Filament\Resources\Projects\Pages\ProjectReports;
use App\Models\MonthlyReportRevision;
use App\Models\Project;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\BuildsCompleteReports;
use Tests\Support\FakePdfReportGenerator;
use Tests\TestCase;

class ReportVersionUiTest extends TestCase
{
    use BuildsCompleteReports;
    use RefreshDatabase;

    protected User $admin;

    protected User $executive;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-12 10:00:00');
        Storage::fake('local');
        FakePdfReportGenerator::install();

        $this->admin = User::factory()->superAdmin()->create(['name' => 'Ava Admin']);
        $this->executive = User::factory()->seoExecutive()->create(['name' => 'Eli Executive']);
        $this->buildCompleteReport($this->executive);
        app(MarkReportReadyAction::class)->handle($this->report, $this->manager);
        $this->report = app(FinalizeMonthlyReportAction::class)->handle($this->report->fresh(), $this->manager)->fresh();
    }

    protected function editor()
    {
        return Livewire::test(ProjectReportEditor::class, ['record' => $this->project->getRouteKey(), 'report' => $this->report->getKey()]);
    }

    protected function revisionUrls(MonthlyReportRevision $revision, ?Project $project = null, ?int $reportId = null): array
    {
        $params = ['project' => ($project ?? $this->project)->getKey(), 'report' => $reportId ?? $this->report->getKey(), 'revision' => $revision->getKey()];

        return [route('filament.admin.reports.revisions.preview', $params), route('filament.admin.reports.revisions.pdf', $params)];
    }

    public function test_only_super_admin_sees_and_can_use_unlock_for_correction(): void
    {
        foreach ([$this->manager, $this->executive] as $user) {
            $this->actingAs($user);

            $this->editor()
                ->assertSee('data-report-version="1"', false)
                ->assertSee('Current version')
                ->assertActionHidden('unlock')
                ->mountAction('unlock')
                ->callMountedAction();

            $this->assertSame(ReportStatus::Final, $this->report->fresh()->status);
        }

        $this->actingAs($this->admin);

        $this->editor()
            ->assertActionVisible('unlock')
            ->callAction('unlock', data: ['reason' => ''])
            ->assertHasFormErrors(['reason' => 'required']);

        $this->editor()
            ->callAction('unlock', data: ['reason' => 'short'])
            ->assertHasFormErrors(['reason']);

        $this->assertSame(ReportStatus::Final, $this->report->fresh()->status);

        $this->editor()
            ->callAction('unlock', data: ['reason' => 'Incorrect GA4 sessions were entered for September.'])
            ->assertHasNoFormErrors()
            ->assertNotified('Report unlocked for correction. The previous final version has been preserved.')
            ->assertSee('data-report-status="draft"', false)
            ->assertSee('data-report-version="2"', false)
            ->assertSee('data-correction-banner', false)
            ->assertSee('Correction in progress')
            ->assertSee('data-previous-version="1"', false)
            ->assertSee('View Previous Final')
            ->assertSee('Download Previous PDF')
            ->assertSee('data-version-row="1"', false)
            ->assertSee('data-version-state="superseded"', false)
            ->assertSee('Incorrect GA4 sessions were entered for September.')
            ->assertSee('data-audit-event="report_unlocked_for_correction"', false)
            ->assertActionHidden('unlock')
            ->assertActionVisible('editExecutiveSummary');

        $this->assertSame(2, $this->report->fresh()->version);
        $this->assertSame(1, $this->report->fresh()->revisions()->count());
    }

    public function test_history_and_audit_render_for_authorized_users_after_refinalization(): void
    {
        $this->actingAs($this->admin);
        app(UnlockMonthlyReportAction::class)->handle($this->report, $this->admin, 'Incorrect GA4 sessions were entered.');

        Carbon::setTestNow('2026-09-12 10:47:00');
        app(MarkReportReadyAction::class)->handle($this->report->fresh(), $this->manager);
        $v2 = app(FinalizeMonthlyReportAction::class)->handle($this->report->fresh(), $this->manager)->fresh();

        foreach ([$this->admin, $this->manager, $this->executive] as $user) {
            $this->actingAs($user);

            $this->editor()
                ->assertSee('data-report-version="2"', false)
                ->assertSee('data-version-row="2"', false)
                ->assertSee('data-version-state="final"', false)
                ->assertSee('data-version-row="1"', false)
                ->assertSee('data-version-state="superseded"', false)
                ->assertSee('Unlock reason: Incorrect GA4 sessions were entered.')
                ->assertSee('data-audit-event="report_finalized"', false)
                ->assertSee('data-audit-event="report_unlocked_for_correction"', false)
                ->assertSee('Morgan Manager')
                ->assertSee('Ava Admin')
                ->assertDontSee('data-correction-banner', false);

            Livewire::test(ProjectReports::class, ['record' => $this->project->getRouteKey()])
                ->assertSee('Final')
                ->assertSee('v2')
                ->assertSee('1 superseded version')
                ->assertSee('data-history-revision="1"', false);
        }

        $this->assertSame(
            ['report_marked_ready', 'report_finalized', 'report_unlocked_for_correction', 'report_marked_ready', 'report_finalized'],
            $v2->auditEvents()->get()->map(fn ($e) => $e->event_type->value)->all(),
        );
    }

    public function test_archived_versions_are_previewable_and_downloadable_by_authorized_users_only(): void
    {
        app(UnlockMonthlyReportAction::class)->handle($this->report, $this->admin, 'Incorrect GA4 sessions were entered.');
        $revision = $this->report->fresh()->revisions()->firstOrFail();
        [$preview, $pdf] = $this->revisionUrls($revision);

        foreach ([$this->admin, $this->manager, $this->executive] as $user) {
            $this->actingAs($user);

            $this->assertTrue($user->can('view', $revision));
            $this->assertTrue($user->can('downloadPdf', $revision));

            $this->get($preview)->assertOk()
                ->assertSee('data-report-status="final"', false)
                ->assertSee('data-metric="clicks">1,200<', false)
                ->assertSee('Finalized 12 September 2026 by Morgan Manager');

            $response = $this->get($pdf)->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('content-type'));
            $this->assertStringContainsString('casa-botanica-seo-report-2026-09-v1.pdf', (string) $response->headers->get('content-disposition'));
            $this->assertStringStartsWith('%PDF', $response->streamedContent());
        }

        // Unrelated executive: 404 everywhere, including guessed ids under their own project.
        $outsider = User::factory()->seoExecutive()->create();
        $own = Project::factory()->ownedBy($outsider)->create();
        $this->actingAs($outsider);

        $this->assertFalse($outsider->can('view', $revision));
        $this->get($preview)->assertNotFound();
        $this->get($pdf)->assertNotFound();

        [$guessPreview, $guessPdf] = $this->revisionUrls($revision, $own, $this->report->getKey());
        $this->get($guessPreview)->assertNotFound();
        $this->get($guessPdf)->assertNotFound();

        // A revision id that belongs to another report is a 404 for anyone.
        $this->actingAs($this->admin);
        $foreign = MonthlyReportRevision::factory()->create();
        [$foreignPreview] = $this->revisionUrls($foreign);
        $this->get($foreignPreview)->assertNotFound();

        // The storage path is never a URL, and guests are redirected.
        $this->assertContains($this->get('/storage/'.$revision->generated_pdf_path)->getStatusCode(), [403, 404]);
        auth()->logout();
        $this->get($preview)->assertRedirect(Filament::getLoginUrl());
    }

    public function test_audit_history_is_project_scoped(): void
    {
        $outsider = User::factory()->seoExecutive()->create();
        $this->actingAs($outsider);

        $this->get(route('filament.admin.resources.projects.report', ['record' => $this->project, 'report' => $this->report]))->assertNotFound();
        $this->assertFalse($outsider->can('viewHistory', $this->report));
        $this->assertFalse($outsider->can('view', $this->report->auditEvents()->firstOrFail()));

        $this->assertTrue($this->executive->can('viewHistory', $this->report));
        $this->assertTrue($this->executive->can('view', $this->report->auditEvents()->firstOrFail()));
    }
}
