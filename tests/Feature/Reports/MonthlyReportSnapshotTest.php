<?php

namespace Tests\Feature\Reports;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Packages\SyncPackageTargetsAction;
use App\Actions\Reports\EnsureMonthlyReportAction;
use App\Actions\Reports\UpdateMonthlyReportDraftAction;
use App\Actions\Reports\UpdateProjectReportSectionsAction;
use App\Enums\MonthlyCycleStatus;
use App\Enums\ReportSectionKey;
use App\Enums\ReportSectionStatus;
use App\Enums\ReportStatus;
use App\Exceptions\LockedMonthlyCycleException;
use App\Models\MonthlyCycle;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportSection;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class MonthlyReportSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected Package $package;

    protected Project $project;

    protected MonthlyCycle $september;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->package = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 50],
        ])->create();
        $this->project = Project::factory()->withPackage($this->package)->create();
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
    }

    public function test_ensure_creates_one_draft_report_per_cycle_with_a_section_snapshot(): void
    {
        $this->assertSame(0, $this->project->reportSections()->count());

        $report = app(EnsureMonthlyReportAction::class)->handle($this->september);

        $this->assertSame(ReportStatus::Draft, $report->status);
        $this->assertTrue($report->monthlyCycle->is($this->september));
        $this->assertTrue($this->september->monthlyReport->is($report));
        $this->assertNull($report->executive_summary);
        $this->assertNull($report->finalized_at);
        $this->assertNull($report->generated_pdf_path);

        // The project was initialised on the way, and the snapshot mirrors it.
        $this->assertSame(10, $this->project->reportSections()->count());
        $this->assertSame(10, $report->sections()->count());
        $this->assertSame(
            $this->project->reportSections()->ordered()->pluck('section_key')->map(fn ($k) => $k->value)->all(),
            $report->sections()->pluck('section_key')->map(fn ($k) => $k->value)->all(),
        );
        $this->assertTrue($report->sections->every(fn (MonthlyReportSection $s): bool => $s->status === ReportSectionStatus::Incomplete));
        $this->assertFalse($report->sections->firstWhere('section_key', ReportSectionKey::AudienceCountry)->is_required);

        // Idempotent: same report, same snapshot, no duplicates.
        $again = app(EnsureMonthlyReportAction::class)->handle($this->september->fresh());
        $this->assertSame($report->id, $again->id);
        $this->assertSame(1, MonthlyReport::query()->count());
        $this->assertSame(10, MonthlyReportSection::query()->count());

        try {
            MonthlyReport::factory()->forCycle($this->september)->create();
            $this->fail('Expected the unique constraint to reject a second report.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_project_configuration_changes_never_alter_an_existing_snapshot(): void
    {
        app(UpdateProjectReportSectionsAction::class)->handle($this->project, [
            ['section_key' => 'executive_summary', 'title' => 'Summary', 'is_enabled' => true, 'is_required' => true],
            ['section_key' => 'backlinks', 'title' => 'Link Building', 'is_enabled' => true, 'is_required' => true],
            ['section_key' => 'rankings', 'title' => 'Rankings', 'is_enabled' => true, 'is_required' => false],
        ]);

        $report = app(EnsureMonthlyReportAction::class)->handle($this->september);
        $snapshot = fn (string $key): MonthlyReportSection => $report->sections()->where('section_key', $key)->firstOrFail();

        $this->assertSame('Link Building', $snapshot('backlinks')->title);
        $this->assertSame(20, $snapshot('backlinks')->sort_order);
        $this->assertFalse($snapshot('rankings')->is_required);

        // Title, enabled, required and order all change on the project…
        app(UpdateProjectReportSectionsAction::class)->handle($this->project, [
            ['section_key' => 'rankings', 'title' => 'Keyword Positions', 'is_enabled' => false, 'is_required' => true],
            ['section_key' => 'backlinks', 'title' => 'Links', 'is_enabled' => false, 'is_required' => false],
            ['section_key' => 'executive_summary', 'title' => 'Overview', 'is_enabled' => true, 'is_required' => false],
        ]);

        // …and re-running Ensure does not re-snapshot.
        app(EnsureMonthlyReportAction::class)->handle($this->september->fresh());

        $this->assertSame('Link Building', $snapshot('backlinks')->title);
        $this->assertTrue($snapshot('backlinks')->is_enabled);
        $this->assertTrue($snapshot('backlinks')->is_required);
        $this->assertSame(20, $snapshot('backlinks')->sort_order);
        $this->assertSame('Rankings', $snapshot('rankings')->title);
        $this->assertTrue($snapshot('rankings')->is_enabled);
        $this->assertFalse($snapshot('rankings')->is_required);
        $this->assertSame(30, $snapshot('rankings')->sort_order);
        $this->assertSame('Summary', $snapshot('executive_summary')->title);
        $this->assertTrue($snapshot('executive_summary')->is_required);

        // A new month's report uses the new configuration.
        $october = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 10));
        $next = app(EnsureMonthlyReportAction::class)->handle($october);

        $this->assertSame('Keyword Positions', $next->sections()->where('section_key', 'rankings')->value('title'));
        $this->assertSame(10, $next->sections()->where('section_key', 'rankings')->value('sort_order'));
        $this->assertFalse((bool) $next->sections()->where('section_key', 'backlinks')->value('is_enabled'));
        $this->assertSame('Overview', $next->sections()->where('section_key', 'executive_summary')->value('title'));
        $this->assertSame(2, MonthlyReport::query()->count());
    }

    public function test_package_target_changes_do_not_alter_the_report_snapshot(): void
    {
        $report = app(EnsureMonthlyReportAction::class)->handle($this->september);
        $before = $report->sections()->get()->map(fn (MonthlyReportSection $s): array => $s->only(['section_key', 'title', 'is_enabled', 'is_required', 'sort_order']))->all();

        app(SyncPackageTargetsAction::class)->handle($this->package, [
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 5],
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 12],
        ]);

        $this->assertEquals($before, $report->fresh()->sections()->get()->map(fn (MonthlyReportSection $s): array => $s->only(['section_key', 'title', 'is_enabled', 'is_required', 'sort_order']))->all());
        $this->assertSame(50, $this->september->targets()->where('target_key', 'backlinks')->value('target_value'));
    }

    public function test_locked_cycle_cannot_start_a_report_but_existing_reports_stay_readable(): void
    {
        $october = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 10));
        $october->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();

        try {
            app(EnsureMonthlyReportAction::class)->handle($october->fresh());
            $this->fail('Expected LockedMonthlyCycleException.');
        } catch (LockedMonthlyCycleException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseCount('monthly_reports', 0);

        // A report that existed before the lock is returned, not duplicated, and not editable.
        $report = app(EnsureMonthlyReportAction::class)->handle($this->september);
        app(UpdateMonthlyReportDraftAction::class)->handle($report, ['executive_summary' => 'Before lock']);
        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();

        $same = app(EnsureMonthlyReportAction::class)->handle($this->september->fresh());
        $this->assertSame($report->id, $same->id);
        $this->assertSame('Before lock', $same->executive_summary);

        foreach ([User::factory()->superAdmin()->create(), User::factory()->seoManager()->create()] as $user) {
            $this->assertTrue($user->can('view', $same));
            $this->assertFalse($user->can('prepare', $same));
            $this->assertFalse($user->can('ensureReport', $this->september->fresh()));
        }

        try {
            app(UpdateMonthlyReportDraftAction::class)->handle($same, ['executive_summary' => 'After lock']);
            $this->fail('Expected LockedMonthlyCycleException.');
        } catch (LockedMonthlyCycleException) {
            $this->assertSame('Before lock', $same->fresh()->executive_summary);
        }
    }

    public function test_only_draft_reports_are_editable_and_no_transition_is_exposed(): void
    {
        $report = app(EnsureMonthlyReportAction::class)->handle($this->september);

        app(UpdateMonthlyReportDraftAction::class)->handle($report, ['executive_summary' => '  Strong month.  ']);
        $this->assertSame('Strong month.', $report->fresh()->executive_summary);

        app(UpdateMonthlyReportDraftAction::class)->handle($report, ['executive_summary' => '   ']);
        $this->assertNull($report->fresh()->executive_summary);

        try {
            app(UpdateMonthlyReportDraftAction::class)->handle($report, ['executive_summary' => str_repeat('s', 10001)]);
            $this->fail('Expected the summary length cap.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        // Status is not an accepted attribute; the report stays a draft.
        app(UpdateMonthlyReportDraftAction::class)->handle($report, ['status' => 'final', 'executive_summary' => 'x']);
        $this->assertSame(ReportStatus::Draft, $report->fresh()->status);

        $ready = MonthlyReport::factory()->status(ReportStatus::ReadyForReview)->create();

        try {
            app(UpdateMonthlyReportDraftAction::class)->handle($ready, ['executive_summary' => 'nope']);
            $this->fail('Expected non-draft reports to be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertNull($ready->fresh()->executive_summary);
        }

        $this->assertFalse(User::factory()->superAdmin()->create()->can('prepare', $ready));
        $this->assertFalse(class_exists('App\Actions\Reports\MarkReportReadyAction'));
        $this->assertFalse(class_exists('App\Actions\Reports\FinalizeMonthlyReportAction'));
    }
}
