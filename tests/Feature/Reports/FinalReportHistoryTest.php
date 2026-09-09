<?php

namespace Tests\Feature\Reports;

use App\Actions\Packages\SyncPackageTargetsAction;
use App\Actions\Reports\FinalizeMonthlyReportAction;
use App\Actions\Reports\MarkReportReadyAction;
use App\Actions\Reports\UpdateProjectReportSectionsAction;
use App\Models\User;
use App\Services\Reports\ReportRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCompleteReports;
use Tests\Support\FakePdfReportGenerator;
use Tests\TestCase;

/**
 * Once final, the stored snapshot is the report. Nothing that changes
 * afterwards may alter its output.
 */
class FinalReportHistoryTest extends TestCase
{
    use BuildsCompleteReports;
    use RefreshDatabase;

    /** @var array<string, mixed> */
    protected array $snapshot;

    protected string $html;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-30 10:00:00');
        Storage::fake('local');
        FakePdfReportGenerator::install();

        $this->buildCompleteReport();
        app(MarkReportReadyAction::class)->handle($this->report, $this->manager);
        $this->report = app(FinalizeMonthlyReportAction::class)->handle($this->report->fresh(), $this->manager)->fresh();

        $this->snapshot = $this->report->snapshot_json;
        $this->html = $this->render();
    }

    protected function render(): string
    {
        $renderer = app(ReportRenderer::class);

        return $renderer->html($renderer->snapshotFor($this->report->fresh()));
    }

    protected function assertUnchanged(): void
    {
        $this->assertSame($this->snapshot, $this->report->fresh()->snapshot_json);
        $this->assertSame($this->html, $this->render());
    }

    public function test_package_and_override_changes_do_not_alter_the_final_report(): void
    {
        app(SyncPackageTargetsAction::class)->handle($this->package, [
            ['target_key' => 'backlinks', 'label' => 'Backlinks (new)', 'target_value' => 5],
        ]);
        $this->project->targetOverrides()->create(['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 1]);

        $this->assertUnchanged();
        $this->assertStringContainsString('1 / 50', $this->render());
        $this->assertStringNotContainsString('Backlinks (new)', $this->render());
    }

    public function test_client_and_project_renames_do_not_alter_the_final_report(): void
    {
        $this->project->client->forceFill(['name' => 'Renamed Client Co'])->save();
        $this->project->forceFill(['name' => 'Renamed Project', 'website_url' => 'https://renamed.example'])->save();

        $this->assertUnchanged();
        $this->assertStringContainsString('Casa Botanica Ltd', $this->render());
        $this->assertStringContainsString('https://casabotanica.example', $this->render());
        $this->assertStringNotContainsString('Renamed', $this->render());
    }

    public function test_source_row_changes_and_reconfiguration_do_not_alter_the_final_report(): void
    {
        // Analytics, backlinks, notes and rankings all change underneath (direct setup; the lock guards would refuse actions).
        $this->cycle->gscMonthlyMetric()->update(['clicks' => 999999]);
        $this->cycle->gscQueryMetrics()->delete();
        $this->cycle->ga4CountryMetrics()->update(['active_users' => 1]);
        $this->cycle->authorityMetric()->update(['moz_domain_authority' => 1]);
        $this->cycle->backlinks()->update(['status' => 'removed']);
        $this->cycle->backlinks()->create(['project_id' => $this->project->id, 'created_by' => $this->manager->id, 'published_url' => 'https://late.example/x', 'type' => 'citation', 'status' => 'live']);
        $this->cycle->monthlyNotes()->update(['body' => 'rewritten']);
        $this->keyword->rankingSnapshots()->update(['position' => 99]);

        // Project template changes and even a stale display status.
        app(UpdateProjectReportSectionsAction::class)->handle($this->project, [
            ['section_key' => 'organic_search', 'title' => 'Organic (template)', 'is_enabled' => false, 'is_required' => false],
        ]);

        $this->assertUnchanged();

        $html = $this->render();
        $this->assertStringContainsString('data-metric="clicks">1,200<', $html);
        $this->assertStringContainsString('villa rentals marbella', $html);
        $this->assertStringContainsString('Improved 16 positions', $html);
        $this->assertStringContainsString('Ranked #1 for villa rentals marbella.', $html);
        $this->assertStringContainsString('https://partner.example/guest-post', $html);
        $this->assertStringNotContainsString('999,999', $html);
        $this->assertStringNotContainsString('late.example', $html);
        $this->assertStringNotContainsString('rewritten', $html);
        $this->assertStringNotContainsString('Organic (template)', $html);
    }

    public function test_final_preview_is_served_from_the_snapshot(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->cycle->gscMonthlyMetric()->update(['clicks' => 424242]);

        $this->get(route('filament.admin.reports.preview', ['project' => $this->project->getKey(), 'report' => $this->report->getKey()]))
            ->assertOk()
            ->assertSee('data-report-status="final"', false)
            ->assertSee('data-metric="clicks">1,200<', false)
            ->assertDontSee('424,242')
            ->assertSee('Finalized 30 September 2026 by Morgan Manager');
    }
}
