<?php

namespace Tests\Feature\Reports;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Reports\EnsureMonthlyReportAction;
use App\Enums\MonthlyCycleStatus;
use App\Enums\ReportStatus;
use App\Filament\Resources\Projects\Pages\ProjectReports;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\AuthorityMetric;
use App\Models\MonthlyCycle;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectReportsAccessTest extends TestCase
{
    use RefreshDatabase;

    protected User $executive;

    protected Project $assigned;

    protected Project $unrelated;

    protected MonthlyCycle $assignedCycle;

    protected MonthlyCycle $unrelatedCycle;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->executive = User::factory()->seoExecutive()->create();
        $this->assigned = Project::factory()->ownedBy($this->executive)->create();
        $this->unrelated = Project::factory()->create();
        $this->assignedCycle = app(CreateMonthlyCycleAction::class)->handle($this->assigned, new CyclePeriod(2026, 9));
        $this->unrelatedCycle = app(CreateMonthlyCycleAction::class)->handle($this->unrelated, new CyclePeriod(2026, 9));
    }

    public function test_admin_and_manager_ensure_drafts_and_see_readiness_on_any_project(): void
    {
        foreach ([User::factory()->superAdmin()->create(), User::factory()->seoManager()->create()] as $index => $user) {
            $this->actingAs($user);

            $this->assertTrue($user->can('viewReports', $this->unrelated));
            $this->assertTrue($user->can('prepareReports', $this->unrelated));
            $this->assertTrue($user->can('ensureReport', $this->unrelatedCycle));

            $component = Livewire::test(ProjectReports::class, ['record' => $this->unrelated->getRouteKey()])
                ->assertCanSeeTableRecords([$this->unrelatedCycle])
                ->assertSee('September 2026');

            if ($index === 0) {
                $component
                    ->assertSee('Not started')
                    ->assertTableActionVisible('ensureReport', $this->unrelatedCycle)
                    ->callTableAction('ensureReport', $this->unrelatedCycle)
                    ->assertNotified('Draft report ready');
            }

            $this->assertSame(1, MonthlyReport::query()->count());

            $component = Livewire::test(ProjectReports::class, ['record' => $this->unrelated->getRouteKey()])
                ->assertSee('Draft')
                ->assertTableActionHidden('ensureReport', $this->unrelatedCycle);

            if ($index === 0) {
                $component->assertSee('0%')->assertSee('0 / 9 complete');
            }

            $component
                ->callTableAction('editSummary', $this->unrelatedCycle, data: ['executive_summary' => 'Summary by '.$user->id])
                ->assertNotified('Executive summary saved')
                ->assertSee('11%')
                ->assertSee('1 / 9 complete')
                ->callTableAction('refreshReadiness', $this->unrelatedCycle)
                ->assertNotified();

            $this->assertSame('Summary by '.$user->id, $this->unrelatedCycle->monthlyReport()->value('executive_summary'));
        }
    }

    public function test_assigned_executive_may_ensure_the_draft_and_view_readiness(): void
    {
        $this->actingAs($this->executive);

        $this->assertTrue($this->executive->can('viewReports', $this->assigned));
        $this->assertTrue($this->executive->can('ensureReport', $this->assignedCycle));
        $this->assertFalse($this->executive->can('manageReportSections', $this->assigned));

        $this->get(ProjectResource::getUrl('reports', ['record' => $this->assigned]))
            ->assertOk()
            ->assertSee('September 2026')
            ->assertDontSee(ProjectResource::getUrl('report-sections', ['record' => $this->assigned]));

        Livewire::test(ProjectReports::class, ['record' => $this->assigned->getRouteKey()])
            ->assertActionHidden('reportSections')
            ->callTableAction('ensureReport', $this->assignedCycle)
            ->assertNotified('Draft report ready')
            ->assertSee('0 / 9 complete');

        AuthorityMetric::factory()->forCycle($this->assignedCycle)->create();

        Livewire::test(ProjectReports::class, ['record' => $this->assigned->getRouteKey()])
            ->assertSee('1 / 9 complete')
            ->assertSee('11%')
            ->callTableAction('editSummary', $this->assignedCycle, data: ['executive_summary' => 'Prepared by the executive.'])
            ->assertNotified('Executive summary saved')
            ->assertSee('2 / 9 complete');

        $report = $this->assignedCycle->monthlyReport()->firstOrFail();
        $this->assertSame(ReportStatus::Draft, $report->status);
        $this->assertSame(10, $report->sections()->count());
        // Ensuring the draft did not change the project template.
        $this->assertSame(10, $this->assigned->reportSections()->count());
        $this->assertTrue($this->assigned->reportSections()->where('section_key', 'executive_summary')->value('is_required'));
    }

    public function test_unrelated_executive_cannot_reach_reports_or_readiness(): void
    {
        $report = app(EnsureMonthlyReportAction::class)->handle($this->unrelatedCycle);

        $this->actingAs($this->executive);

        $this->get(ProjectResource::getUrl('reports', ['record' => $this->unrelated]))->assertNotFound();
        $this->get(ProjectResource::getUrl('report-sections', ['record' => $this->unrelated]))->assertNotFound();

        try {
            Livewire::test(ProjectReports::class, ['record' => $this->unrelated->getRouteKey()]);
            $this->fail('Expected the project to be outside the scoped resource query.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertFalse($this->executive->can('view', $report));
        $this->assertFalse($this->executive->can('prepare', $report));
        $this->assertFalse($this->executive->can('viewReports', $this->unrelated));
        $this->assertFalse($this->executive->can('ensureReport', $this->unrelatedCycle));
        $this->assertFalse(MonthlyReport::query()->accessibleBy($this->executive)->whereKey($report->id)->exists());

        // Crafted table actions against another project's cycle do nothing.
        Livewire::test(ProjectReports::class, ['record' => $this->assigned->getRouteKey()])
            ->assertCanNotSeeTableRecords([$this->unrelatedCycle])
            ->mountTableAction('editSummary', $this->unrelatedCycle)
            ->callMountedTableAction();

        $this->assertNull($report->fresh()->executive_summary);
    }

    public function test_locked_month_offers_no_draft_creation_or_editing(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->assignedCycle->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();

        Livewire::test(ProjectReports::class, ['record' => $this->assigned->getRouteKey()])
            ->assertSee('Locked')
            ->assertTableActionHidden('ensureReport', $this->assignedCycle)
            ->mountTableAction('ensureReport', $this->assignedCycle)
            ->callMountedTableAction();

        $this->assertDatabaseCount('monthly_reports', 0);
    }

    public function test_reports_are_project_scoped_and_nothing_from_later_milestones_exists(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $labels = collect(Filament::getPanel('admin')->getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->map(fn ($item) => $item->getLabel())
            ->all();

        $this->assertNotContains('Reports', $labels);
        $this->get('/admin')->assertOk()->assertDontSee('/admin/reports');
        $this->get(ProjectResource::getUrl('view', ['record' => $this->assigned]))
            ->assertOk()
            ->assertSee(ProjectResource::getUrl('reports', ['record' => $this->assigned]));
        $this->get(ProjectResource::getUrl('reports', ['record' => $this->assigned]))
            ->assertOk()
            ->assertDontSee('Finalize')
            ->assertDontSee('Download PDF')
            ->assertDontSee('Ready for review');

        $resourceModels = collect(Filament::getPanel('admin')->getResources())->map(fn (string $r): string => $r::getModel())->all();
        $this->assertNotContains(MonthlyReport::class, $resourceModels);

        foreach (['report_snapshots', 'report_files', 'audit_logs', 'cycle_unlocks', 'csv_imports', 'analytics_syncs'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }

        foreach ([
            'App\Actions\Reports\MarkReportReadyAction',
            'App\Actions\Reports\FinalizeMonthlyReportAction',
            'App\Actions\MonthlyCycles\LockMonthlyCycleAction',
            'App\Actions\MonthlyCycles\UnlockMonthlyCycleAction',
            'App\Services\Reports\ReportSnapshotBuilder',
            'App\Services\Reports\PdfReportGenerator',
            'App\Filament\Pages\Reports',
            'App\Filament\Pages\TeamDashboard',
        ] as $class) {
            $this->assertFalse(class_exists($class), "[{$class}] belongs to a later milestone.");
        }

        foreach ([app_path('Services/Pdf'), app_path('Services/Integrations'), resource_path('views/reports')] as $path) {
            $this->assertFalse(File::exists($path), "[{$path}] belongs to a later milestone.");
        }

        $this->assertEmpty(array_filter(
            array_keys(app('router')->getRoutes()->getRoutesByName()),
            fn (string $name): bool => str_contains($name, 'pdf') || str_contains($name, 'oauth') || str_contains($name, 'csv') || str_contains($name, 'finalize'),
        ));
        $this->assertFalse(class_exists('Spatie\LaravelPdf\Facades\Pdf') || class_exists('Spatie\Browsershot\Browsershot'));
    }
}
