<?php

namespace Tests\Feature\Dashboard;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Reports\EnsureMonthlyReportAction;
use App\Actions\Reports\FinalizeMonthlyReportAction;
use App\Actions\Reports\MarkReportReadyAction;
use App\Actions\Reports\UnlockMonthlyReportAction;
use App\Enums\ReportStatus;
use App\Filament\Pages\ReportsOverview;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Client;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\BuildsCompleteReports;
use Tests\Support\FakePdfReportGenerator;
use Tests\TestCase;

class ReportsOverviewTest extends TestCase
{
    use BuildsCompleteReports;
    use RefreshDatabase;

    protected User $admin;

    protected User $executive;

    protected MonthlyReport $correction;

    protected MonthlyReport $ready;

    protected MonthlyReport $draft;

    protected Project $unrelated;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');
        Storage::fake('local');
        FakePdfReportGenerator::install();

        $this->admin = User::factory()->superAdmin()->create();
        $this->executive = User::factory()->seoExecutive()->create();

        // Casa Botanica (owned by the executive): finalized v1, unlocked → v2 draft correction.
        $this->buildCompleteReport($this->executive);
        app(MarkReportReadyAction::class)->handle($this->report, $this->manager);
        app(FinalizeMonthlyReportAction::class)->handle($this->report->fresh(), $this->manager);
        $this->correction = app(UnlockMonthlyReportAction::class)->handle($this->report->fresh(), $this->admin, 'GA4 sessions were entered incorrectly.')->fresh();

        // Unrelated project: ready v1 (all optional) plus an August draft.
        $this->unrelated = Project::factory()->forClient(Client::factory()->create(['name' => 'Secret Client']))->create(['name' => 'Secret Project']);
        $cycle = app(CreateMonthlyCycleAction::class)->handle($this->unrelated, new CyclePeriod(2026, 9));
        $this->ready = app(EnsureMonthlyReportAction::class)->handle($cycle);
        $this->ready->sections()->update(['is_required' => false]);
        $this->ready = app(MarkReportReadyAction::class)->handle($this->ready, $this->manager)->fresh();
        $august = app(CreateMonthlyCycleAction::class)->handle($this->unrelated, new CyclePeriod(2026, 8));
        $this->draft = app(EnsureMonthlyReportAction::class)->handle($august);
    }

    public function test_admin_and_manager_see_every_report_with_version_readiness_and_correction_state(): void
    {
        foreach ([$this->admin, $this->manager] as $user) {
            $this->actingAs($user);

            $this->assertTrue(ReportsOverview::canAccess());
            $this->get(ReportsOverview::getUrl())->assertOk()->assertSee('Casa Botanica')->assertSee('Secret Project');

            Livewire::test(ReportsOverview::class)
                ->assertCanSeeTableRecords([$this->correction, $this->ready, $this->draft])
                ->assertSee('v2')
                ->assertSee('correction')
                ->assertSee('1 superseded')
                ->assertSee('100%')
                ->assertSee('0 / 0')
                ->assertSee('9 / 9')
                ->assertTableActionVisible('open', $this->correction);
        }

        $labels = collect(Filament::getPanel('admin')->getNavigation())->flatMap(fn ($g) => $g->getItems())->map(fn ($i) => $i->getLabel())->all();
        $this->assertContains('Reports', $labels);
    }

    public function test_filters_and_search_work_and_stay_scoped(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ReportsOverview::class)
            ->filterTable('ready', true)
            ->assertCanSeeTableRecords([$this->ready])
            ->assertCanNotSeeTableRecords([$this->correction, $this->draft])
            ->resetTableFilters()
            ->filterTable('final', true)
            ->assertCanNotSeeTableRecords([$this->correction, $this->ready, $this->draft])
            ->resetTableFilters()
            ->filterTable('correction', true)
            ->assertCanSeeTableRecords([$this->correction])
            ->assertCanNotSeeTableRecords([$this->ready, $this->draft])
            ->resetTableFilters()
            ->filterTable('incomplete', true)
            ->assertCanSeeTableRecords([$this->correction, $this->draft])
            ->assertCanNotSeeTableRecords([$this->ready])
            ->resetTableFilters()
            ->filterTable('status', ReportStatus::ReadyForReview->value)
            ->assertCanSeeTableRecords([$this->ready])
            ->resetTableFilters()
            ->filterTable('period', '2026-08')
            ->assertCanSeeTableRecords([$this->draft])
            ->assertCanNotSeeTableRecords([$this->correction, $this->ready])
            ->resetTableFilters()
            ->filterTable('client', $this->unrelated->client_id)
            ->assertCanSeeTableRecords([$this->ready, $this->draft])
            ->assertCanNotSeeTableRecords([$this->correction])
            ->resetTableFilters()
            ->filterTable('primary_seo', $this->executive->id)
            ->assertCanSeeTableRecords([$this->correction])
            ->assertCanNotSeeTableRecords([$this->ready])
            ->resetTableFilters()
            ->searchTable('Secret Client')
            ->assertCanSeeTableRecords([$this->ready, $this->draft])
            ->assertCanNotSeeTableRecords([$this->correction])
            ->searchTable('Casa')
            ->assertCanSeeTableRecords([$this->correction])
            ->assertCanNotSeeTableRecords([$this->ready]);

        // Finalize the ready report: the Final filter picks it up with finalizer details.
        app(FinalizeMonthlyReportAction::class)->handle($this->ready, $this->manager);

        Livewire::test(ReportsOverview::class)
            ->filterTable('final', true)
            ->assertCanSeeTableRecords([$this->ready])
            ->assertSee('Morgan Manager');
    }

    public function test_executive_sees_only_accessible_reports_even_through_filters_and_search(): void
    {
        $this->actingAs($this->executive);

        $this->assertTrue(ReportsOverview::canAccess());

        $html = $this->get(ReportsOverview::getUrl())->assertOk()->getContent();
        $this->assertStringContainsString('Casa Botanica', $html);
        $this->assertStringNotContainsString('Secret', $html);

        Livewire::test(ReportsOverview::class)
            ->assertCanSeeTableRecords([$this->correction])
            ->assertCanNotSeeTableRecords([$this->ready, $this->draft])
            ->filterTable('ready', true)
            ->assertCanNotSeeTableRecords([$this->ready])
            ->resetTableFilters()
            ->searchTable('Secret')
            ->assertCanNotSeeTableRecords([$this->ready, $this->draft])
            ->searchTable('')
            ->filterTable('project', $this->unrelated->id)
            ->assertCanNotSeeTableRecords([$this->ready, $this->draft]);

        // Filter option lists never mention unrelated clients / projects / owners.
        $component = Livewire::test(ReportsOverview::class);
        $filters = $component->instance()->getTable()->getFilters();
        $this->assertArrayNotHasKey($this->unrelated->client_id, $filters['client']->getOptions());
        $this->assertArrayNotHasKey($this->unrelated->id, $filters['project']->getOptions());

        $outsider = User::factory()->seoExecutive()->create();
        $this->actingAs($outsider);
        $this->get(ReportsOverview::getUrl())->assertOk()->assertDontSee('Casa Botanica')->assertDontSee('Secret');
        Livewire::test(ReportsOverview::class)->assertCanNotSeeTableRecords([$this->correction, $this->ready, $this->draft]);
    }

    public function test_open_navigates_to_the_existing_editor_and_the_page_holds_no_report_logic(): void
    {
        $this->actingAs($this->admin);

        $url = ProjectResource::getUrl('report', ['record' => $this->project, 'report' => $this->correction]);

        Livewire::test(ReportsOverview::class)
            ->assertTableActionHasUrl('open', $url, $this->correction)
            ->assertTableActionDoesNotExist('finalize', record: $this->ready)
            ->assertTableActionDoesNotExist('markReady', record: $this->correction)
            ->assertTableActionDoesNotExist('unlock', record: $this->correction);

        $source = File::get(app_path('Filament/Pages/ReportsOverview.php')).File::get(app_path('Services/Dashboard/ReportsOverviewQuery.php'));
        foreach (['FinalizeMonthlyReportAction', 'MarkReportReadyAction', 'UnlockMonthlyReportAction', 'PdfReportGenerator', 'lockForUpdate', 'ReportSnapshotBuilder'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }

        $this->assertStringContainsString('ReportReadinessService', File::get(app_path('Filament/Pages/ReportsOverview.php')));
    }
}
