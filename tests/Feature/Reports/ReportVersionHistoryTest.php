<?php

namespace Tests\Feature\Reports;

use App\Actions\Analytics\SaveGscMonthlyMetricsAction;
use App\Actions\Backlinks\CreateBacklinkAction;
use App\Actions\Packages\SyncPackageTargetsAction;
use App\Actions\Reports\FinalizeMonthlyReportAction;
use App\Actions\Reports\MarkReportReadyAction;
use App\Actions\Reports\UnlockMonthlyReportAction;
use App\Models\MonthlyReportRevision;
use App\Models\User;
use App\Services\Reports\ReportRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCompleteReports;
use Tests\Support\FakePdfReportGenerator;
use Tests\TestCase;

/**
 * Historical accountability: v1 and v2 are separate, permanent records
 * that each render from their own stored snapshot.
 */
class ReportVersionHistoryTest extends TestCase
{
    use BuildsCompleteReports;
    use RefreshDatabase;

    protected User $admin;

    protected MonthlyReportRevision $v1;

    /** @var array<string, mixed> */
    protected array $v1Snapshot;

    protected string $v1Html;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-12 10:00:00');
        Storage::fake('local');
        FakePdfReportGenerator::install();

        $this->admin = User::factory()->superAdmin()->create();
        $this->buildCompleteReport();
        app(MarkReportReadyAction::class)->handle($this->report, $this->manager);
        $this->report = app(FinalizeMonthlyReportAction::class)->handle($this->report->fresh(), $this->manager)->fresh();

        app(UnlockMonthlyReportAction::class)->handle($this->report, $this->admin, 'GA4 organic sessions were entered incorrectly.');
        $this->v1 = $this->report->fresh()->revisions()->firstOrFail();
        $this->v1Snapshot = $this->v1->snapshot_json;
        $this->v1Html = app(ReportRenderer::class)->html($this->v1Snapshot);
    }

    protected function assertV1Unchanged(): void
    {
        $fresh = $this->v1->fresh();
        $this->assertSame($this->v1Snapshot, $fresh->snapshot_json);
        $this->assertSame($this->v1Html, app(ReportRenderer::class)->html($fresh->snapshot_json));
    }

    public function test_correcting_source_data_and_refinalizing_gives_two_distinct_permanent_versions(): void
    {
        // Corrections after unlock: analytics and backlinks change.
        app(SaveGscMonthlyMetricsAction::class)->handle($this->cycle->fresh(), ['clicks' => 1350, 'impressions' => 48000, 'ctr' => 2.5, 'average_position' => 14.3], $this->manager);
        app(CreateBacklinkAction::class)->handle($this->project, ['monthly_cycle_id' => $this->cycle->id, 'published_url' => 'https://second.example/link', 'type' => 'citation', 'status' => 'live', 'published_date' => '2026-09-20'], $this->manager);

        $this->assertV1Unchanged();

        Carbon::setTestNow('2026-09-13 09:00:00');
        app(MarkReportReadyAction::class)->handle($this->report->fresh(), $this->manager);
        $v2 = app(FinalizeMonthlyReportAction::class)->handle($this->report->fresh(), $this->manager)->fresh();

        $this->assertSame(2, $v2->version);
        $this->assertV1Unchanged();

        $section = fn (array $snapshot, string $key): array => collect($snapshot['sections'])->firstWhere('key', $key)['data'];

        // Analytics differ between versions.
        $this->assertSame(1200, $section($this->v1Snapshot, 'organic_search')['clicks']);
        $this->assertSame(1350, $section($v2->snapshot_json, 'organic_search')['clicks']);

        // Backlinks differ between versions.
        $this->assertSame(1, $section($this->v1Snapshot, 'backlinks')['totals']['recorded']);
        $this->assertSame(2, $section($v2->snapshot_json, 'backlinks')['totals']['recorded']);
        $this->assertSame('1 / 50', $section($this->v1Snapshot, 'backlinks')['progress']['backlinks']['display']);
        $this->assertSame('2 / 50', $section($v2->snapshot_json, 'backlinks')['progress']['backlinks']['display']);

        // Each preview renders its own snapshot.
        $this->actingAs($this->admin);
        $params = ['project' => $this->project->getKey(), 'report' => $v2->getKey()];

        $this->get(route('filament.admin.reports.preview', $params))->assertOk()
            ->assertSee('data-metric="clicks">1,350<', false)
            ->assertSee('https://second.example/link')
            ->assertSee('Finalized 13 September 2026');

        $this->get(route('filament.admin.reports.revisions.preview', $params + ['revision' => $this->v1->getKey()]))->assertOk()
            ->assertSee('data-metric="clicks">1,200<', false)
            ->assertDontSee('second.example')
            ->assertSee('Finalized 12 September 2026');

        // Both PDFs remain separately downloadable.
        $this->assertStringStartsWith('%PDF', $this->get(route('filament.admin.reports.pdf', $params))->assertOk()->streamedContent());
        $this->assertStringStartsWith('%PDF', $this->get(route('filament.admin.reports.revisions.pdf', $params + ['revision' => $this->v1->getKey()]))->assertOk()->streamedContent());
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    public function test_v1_never_rewrites_after_project_client_package_or_source_changes(): void
    {
        $this->project->client->forceFill(['name' => 'Renamed Client Co'])->save();
        $this->project->forceFill(['name' => 'Renamed Project'])->save();
        app(SyncPackageTargetsAction::class)->handle($this->package, [['target_key' => 'backlinks', 'label' => 'Backlinks (new)', 'target_value' => 5]]);
        $this->project->targetOverrides()->create(['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 1]);
        $this->cycle->gscMonthlyMetric()->update(['clicks' => 999999]);
        $this->cycle->targets()->where('target_key', 'backlinks')->update(['target_value' => 77]);

        $this->assertV1Unchanged();

        $html = app(ReportRenderer::class)->html($this->v1->fresh()->snapshot_json);
        $this->assertStringContainsString('Casa Botanica Ltd', $html);
        $this->assertStringContainsString('1 / 50', $html);
        $this->assertStringContainsString('data-metric="clicks">1,200<', $html);
        $this->assertStringNotContainsString('Renamed', $html);
        $this->assertStringNotContainsString('999,999', $html);
        $this->assertStringNotContainsString('Backlinks (new)', $html);

        // The revision route serves that same stored snapshot, never live data.
        $this->actingAs($this->admin);
        $this->get(route('filament.admin.reports.revisions.preview', ['project' => $this->project->getKey(), 'report' => $this->report->getKey(), 'revision' => $this->v1->getKey()]))
            ->assertOk()
            ->assertSee('Casa Botanica Ltd')
            ->assertSee('data-metric="clicks">1,200<', false)
            ->assertDontSee('999,999');
    }
}
