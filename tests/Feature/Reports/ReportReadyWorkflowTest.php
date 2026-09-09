<?php

namespace Tests\Feature\Reports;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Reports\EnsureMonthlyReportAction;
use App\Actions\Reports\MarkReportReadyAction;
use App\Enums\ReportSectionStatus;
use App\Enums\ReportStatus;
use App\Exceptions\ReportNotReadyException;
use App\Filament\Resources\Projects\Pages\ProjectReportEditor;
use App\Models\AuthorityMetric;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Support\BuildsCompleteReports;
use Tests\TestCase;

class ReportReadyWorkflowTest extends TestCase
{
    use BuildsCompleteReports;
    use RefreshDatabase;

    protected User $executive;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-30 10:00:00');

        $this->executive = User::factory()->seoExecutive()->create();
        $this->buildCompleteReport($this->executive);
    }

    public function test_incomplete_report_cannot_be_marked_ready_and_explains_what_is_missing(): void
    {
        $this->cycle->gscQueryMetrics()->delete();
        $this->cycle->monthlyNotes()->whereIn('type', ['recommendation', 'next_month_focus'])->delete();

        try {
            app(MarkReportReadyAction::class)->handle($this->report, $this->manager);
            $this->fail('Expected ReportNotReadyException.');
        } catch (ReportNotReadyException $exception) {
            $this->assertStringContainsString('77% ready', $exception->getMessage());
            $this->assertStringContainsString('Top Keywords', $exception->getMessage());
            $this->assertStringContainsString('Recommendations', $exception->getMessage());
            $this->assertSame(['top_keywords', 'recommendations'], $exception->readiness->missing()->map(fn ($s) => $s->key->value)->all());
        }

        $this->assertSame(ReportStatus::Draft, $this->report->fresh()->status);

        $this->actingAs($this->manager);

        Livewire::test(ProjectReportEditor::class, ['record' => $this->project->getRouteKey(), 'report' => $this->report->getKey()])
            ->assertSee('data-readiness="77"', false)
            ->assertSee('data-missing="top_keywords"', false)
            ->assertSee('data-missing="recommendations"', false)
            ->callAction('markReady')
            ->assertNotified()
            ->assertSee('data-report-status="draft"', false);

        $this->assertSame(ReportStatus::Draft, $this->report->fresh()->status);
    }

    public function test_complete_report_may_be_marked_ready_by_the_assigned_executive_and_by_a_manager(): void
    {
        $this->actingAs($this->executive);

        Livewire::test(ProjectReportEditor::class, ['record' => $this->project->getRouteKey(), 'report' => $this->report->getKey()])
            ->assertSee('data-report-ready', false)
            ->callAction('markReady')
            ->assertNotified('Report marked Ready for Review')
            ->assertSee('data-report-status="ready_for_review"', false)
            ->assertActionHidden('markReady')
            ->assertActionHidden('finalize');

        $this->assertSame(ReportStatus::ReadyForReview, $this->report->fresh()->status);

        // Manager on a second project's complete draft.
        $second = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 8));
        $report = app(EnsureMonthlyReportAction::class)->handle($second);
        $report->sections()->update(['is_required' => false]);

        $this->assertSame(ReportStatus::ReadyForReview, app(MarkReportReadyAction::class)->handle($report, $this->manager)->status);
        $this->assertSame(ReportStatus::ReadyForReview, app(MarkReportReadyAction::class)->handle($report, User::factory()->superAdmin()->create())->fresh()->status);
    }

    public function test_unrelated_executive_cannot_mark_another_projects_report_ready(): void
    {
        $outsider = User::factory()->seoExecutive()->create();

        $this->assertFalse($outsider->can('markReady', $this->report));

        try {
            app(MarkReportReadyAction::class)->handle($this->report, $outsider);
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(ReportStatus::Draft, $this->report->fresh()->status);

        $this->actingAs($outsider);
        $this->get(route('filament.admin.reports.preview', ['project' => $this->project->getKey(), 'report' => $this->report->getKey()]))->assertNotFound();
    }

    public function test_mark_ready_re_evaluates_live_readiness_and_ignores_stale_stored_statuses(): void
    {
        // Stored statuses all claim complete while real data is missing.
        $this->cycle->authorityMetric()->delete();
        $this->report->sections()->update(['status' => ReportSectionStatus::Complete->value]);

        try {
            app(MarkReportReadyAction::class)->handle($this->report, $this->manager);
            $this->fail('Expected stale stored statuses to be ignored.');
        } catch (ReportNotReadyException $exception) {
            $this->assertSame(['site_authority'], $exception->readiness->missing()->map(fn ($s) => $s->key->value)->all());
        }

        // The attempt synchronised the truth back into the display cache.
        $this->assertSame(ReportSectionStatus::Incomplete, $this->report->sections()->where('section_key', 'site_authority')->first()->status);
        $this->assertSame(ReportStatus::Draft, $this->report->fresh()->status);

        // Conversely, stored "incomplete" cannot block a report whose data is complete.
        AuthorityMetric::factory()->forCycle($this->cycle)->create();
        $this->report->sections()->update(['status' => ReportSectionStatus::Incomplete->value]);

        $ready = app(MarkReportReadyAction::class)->handle($this->report->fresh(), $this->manager);
        $this->assertSame(ReportStatus::ReadyForReview, $ready->status);
        $this->assertTrue($ready->sections()->where('is_required', true)->get()->every(fn ($s) => $s->status === ReportSectionStatus::Complete));
    }

    public function test_mark_ready_is_idempotent(): void
    {
        $first = app(MarkReportReadyAction::class)->handle($this->report, $this->manager);
        $updatedAt = $first->fresh()->updated_at->toDateTimeString();

        Carbon::setTestNow('2026-09-30 11:00:00');

        $second = app(MarkReportReadyAction::class)->handle($first->fresh(), $this->executive);

        $this->assertSame(ReportStatus::ReadyForReview, $second->status);
        $this->assertSame($updatedAt, $second->fresh()->updated_at->toDateTimeString());
        $this->assertSame(1, MonthlyReport::query()->count());

        // Ready is not frozen: once data disappears, re-marking is refused but the status is untouched.
        $this->cycle->gscPageMetrics()->delete();

        try {
            app(MarkReportReadyAction::class)->handle($second->fresh(), $this->manager);
            $this->fail('Expected ReportNotReadyException.');
        } catch (ReportNotReadyException) {
            $this->assertSame(ReportStatus::ReadyForReview, $second->fresh()->status);
        }
    }
}
