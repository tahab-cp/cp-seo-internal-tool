<?php

namespace Tests\Feature\Reports;

use App\Actions\Backlinks\CreateBacklinkAction;
use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Notes\CreateMonthlyNoteAction;
use App\Actions\Reports\EnsureMonthlyReportAction;
use App\Actions\Reports\SyncReportSectionStatusesAction;
use App\Actions\Reports\UpdateMonthlyReportDraftAction;
use App\Actions\Reports\UpdateProjectReportSectionsAction;
use App\Enums\ContentType;
use App\Enums\KeywordStatus;
use App\Enums\MonthlyNoteType;
use App\Enums\ReportSectionKey;
use App\Enums\ReportSectionStatus;
use App\Models\AuthorityMetric;
use App\Models\ContentItem;
use App\Models\Ga4CountryMetric;
use App\Models\Ga4MonthlyMetric;
use App\Models\GscMonthlyMetric;
use App\Models\GscPageMetric;
use App\Models\GscQueryMetric;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\MonthlyReport;
use App\Models\Package;
use App\Models\Project;
use App\Models\RankingSnapshot;
use App\Models\User;
use App\Services\MonthlyCycles\TargetProgressService;
use App\Services\Reports\ReportReadinessService;
use App\Support\MonthlyCycles\CyclePeriod;
use App\Support\Reports\ReportReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReportReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected Project $project;

    protected MonthlyCycle $september;

    protected MonthlyReport $report;

    protected ReportReadinessService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->manager = User::factory()->seoManager()->create();
        // No package: no target snapshots, so backlinks readiness starts incomplete.
        $this->project = Project::factory()->create();
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
        $this->report = app(EnsureMonthlyReportAction::class)->handle($this->september);
        $this->service = app(ReportReadinessService::class);
    }

    protected function readiness(): ReportReadiness
    {
        return $this->service->evaluate($this->report->fresh());
    }

    protected function complete(string $key): bool
    {
        return $this->readiness()->section($key)->complete;
    }

    /**
     * Fill every section's source data so the report is fully ready.
     */
    protected function satisfyEverything(): void
    {
        app(UpdateMonthlyReportDraftAction::class)->handle($this->report, ['executive_summary' => 'Good month.']);
        AuthorityMetric::factory()->forCycle($this->september)->create();
        GscMonthlyMetric::factory()->forCycle($this->september)->create();
        Ga4MonthlyMetric::factory()->forCycle($this->september)->create();
        GscQueryMetric::factory()->forCycle($this->september)->create();
        GscPageMetric::factory()->forCycle($this->september)->create();
        Ga4CountryMetric::factory()->forCycle($this->september)->create();
        app(CreateMonthlyNoteAction::class)->handle($this->september, ['type' => 'recommendation', 'body' => 'Do more.'], $this->manager);
        $keyword = Keyword::factory()->forProject($this->project)->create();
        RankingSnapshot::factory()->forKeyword($keyword)->forCycle($this->september)->at('2026-09-10 09:00', 4)->create();
        app(CreateBacklinkAction::class)->handle($this->project, ['monthly_cycle_id' => $this->september->id, 'published_url' => 'https://a.example/p', 'type' => 'citation', 'status' => 'planned'], $this->manager);
    }

    public function test_a_fresh_report_is_not_ready_and_every_required_section_explains_why(): void
    {
        $readiness = $this->readiness();

        $this->assertFalse($readiness->isReady());
        $this->assertSame(9, $readiness->requiredCount());
        $this->assertSame(0, $readiness->completedRequiredCount());
        $this->assertSame(0, $readiness->percentage());
        $this->assertCount(9, $readiness->missing());
        $this->assertSame('0 / 9 required sections', $readiness->label());

        foreach ($readiness->sections as $section) {
            $this->assertFalse($section->complete);
            $this->assertNotEmpty($section->reason);
        }

        $array = $readiness->toArray();
        $this->assertFalse($array['ready']);
        $this->assertCount(10, $array['sections']);
        $this->assertSame('executive_summary', $array['sections'][0]['key']);
    }

    public function test_each_data_backed_section_completes_from_its_source_only(): void
    {
        $cases = [
            'executive_summary' => fn () => app(UpdateMonthlyReportDraftAction::class)->handle($this->report, ['executive_summary' => 'Strong month.']),
            'site_authority' => fn () => AuthorityMetric::factory()->forCycle($this->september)->create(),
            'organic_search' => fn () => GscMonthlyMetric::factory()->forCycle($this->september)->create(),
            'website_traffic' => fn () => Ga4MonthlyMetric::factory()->forCycle($this->september)->create(),
            'top_keywords' => fn () => GscQueryMetric::factory()->forCycle($this->september)->create(),
            'landing_pages' => fn () => GscPageMetric::factory()->forCycle($this->september)->create(),
            'audience_country' => fn () => Ga4CountryMetric::factory()->forCycle($this->september)->create(),
            'recommendations' => fn () => app(CreateMonthlyNoteAction::class)->handle($this->september, ['type' => 'next_month_focus', 'body' => 'Focus on pricing pages.'], $this->manager),
        ];

        $completed = [];

        foreach ($cases as $key => $satisfy) {
            $this->assertFalse($this->complete($key), "{$key} should start incomplete.");

            $satisfy();
            $completed[] = $key;

            foreach (array_keys($cases) as $other) {
                $this->assertSame(in_array($other, $completed, true), $this->complete($other), "{$other} after satisfying {$key}");
            }
        }

        // Data in another month does not count.
        $october = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 10));
        $octoberReport = app(EnsureMonthlyReportAction::class)->handle($october);
        $this->assertFalse($this->service->evaluate($octoberReport)->section('site_authority')->complete);
        $this->assertFalse($this->service->evaluate($octoberReport)->section('recommendations')->complete);
    }

    public function test_executive_summary_and_recommendations_rules_are_strict(): void
    {
        app(UpdateMonthlyReportDraftAction::class)->handle($this->report, ['executive_summary' => '   ']);
        $this->assertFalse($this->complete('executive_summary'));

        app(UpdateMonthlyReportDraftAction::class)->handle($this->report, ['executive_summary' => 'Done.']);
        $this->assertTrue($this->complete('executive_summary'));

        foreach ([MonthlyNoteType::Win, MonthlyNoteType::Challenge, MonthlyNoteType::Observation] as $type) {
            app(CreateMonthlyNoteAction::class)->handle($this->september, ['type' => $type->value, 'body' => 'Not a recommendation.'], $this->manager);
        }

        $this->assertFalse($this->complete('recommendations'));

        app(CreateMonthlyNoteAction::class)->handle($this->september, ['type' => 'recommendation', 'body' => 'Add FAQ schema.'], $this->manager);
        $this->assertTrue($this->complete('recommendations'));
    }

    public function test_rankings_rule_requires_a_snapshot_for_every_active_keyword(): void
    {
        // No active keywords: incomplete, with an explicit reason.
        $this->assertFalse($this->complete('rankings'));
        $this->assertStringContainsString('No active keywords', $this->readiness()->section('rankings')->reason);

        $a = Keyword::factory()->forProject($this->project)->create(['keyword' => 'alpha']);
        $b = Keyword::factory()->forProject($this->project)->create(['keyword' => 'beta']);
        $paused = Keyword::factory()->forProject($this->project)->status(KeywordStatus::Paused)->create(['keyword' => 'paused one']);

        $this->assertFalse($this->complete('rankings'));
        $this->assertStringContainsString('2 active keywords have no ranking', $this->readiness()->section('rankings')->reason);

        RankingSnapshot::factory()->forKeyword($a)->forCycle($this->september)->at('2026-09-10 09:00', 3)->create();
        $this->assertFalse($this->complete('rankings'));
        $this->assertStringContainsString('1 active keyword has no ranking', $this->readiness()->section('rankings')->reason);

        // A snapshot in another month does not satisfy September.
        $october = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 10));
        RankingSnapshot::factory()->forKeyword($b)->forCycle($october)->at('2026-10-02 09:00', 5)->create();
        $this->assertFalse($this->complete('rankings'));

        // "Not ranking" (null position) still counts as an observation.
        RankingSnapshot::factory()->forKeyword($b)->forCycle($this->september)->at('2026-09-12 09:00', null)->create();
        $this->assertTrue($this->complete('rankings'));

        // Paused keywords are ignored; another project's keywords are ignored.
        $this->assertSame(0, $paused->rankingSnapshots()->count());
        Keyword::factory()->create();
        $this->assertTrue($this->complete('rankings'));
    }

    public function test_backlinks_rule_is_about_context_not_target_achievement(): void
    {
        // No target snapshot, no rows: incomplete.
        $this->assertFalse($this->complete('backlinks'));

        // Real backlink rows complete it, whatever their status.
        $link = app(CreateBacklinkAction::class)->handle($this->project, ['monthly_cycle_id' => $this->september->id, 'published_url' => 'https://a.example/p', 'type' => 'citation', 'status' => 'planned'], $this->manager);
        $this->assertTrue($this->complete('backlinks'));
        $link->forceDelete();
        $this->assertFalse($this->complete('backlinks'));

        // A guest_posts target snapshot alone completes it, even at 0 actual.
        $this->september->targets()->create(['target_key' => TargetProgressService::GUEST_POSTS, 'label' => 'Guest Posts', 'target_value' => 4]);
        $this->assertTrue($this->complete('backlinks'));
        $this->assertSame('0 / 4', app(TargetProgressService::class)->guestPosts($this->september)->format());

        // Other target keys do not count.
        $this->september->targets()->delete();
        $this->september->targets()->create(['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 4]);
        $this->assertFalse($this->complete('backlinks'));

        // A backlinks target of 50 with 0 live links: complete (38 / 50 would be too).
        $this->september->targets()->create(['target_key' => TargetProgressService::BACKLINKS, 'label' => 'Backlinks', 'target_value' => 50]);
        $this->assertTrue($this->complete('backlinks'));
        $this->assertSame('0 / 50', app(TargetProgressService::class)->backlinks($this->september)->format());
    }

    public function test_target_achievement_never_affects_readiness(): void
    {
        $package = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 50],
            ['target_key' => 'guest_posts', 'label' => 'Guest Posts', 'target_value' => 5],
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 8],
            ['target_key' => 'pages_optimized', 'label' => 'Pages Optimised', 'target_value' => 6],
        ])->create();
        $project = Project::factory()->withPackage($package)->create();
        $cycle = app(CreateMonthlyCycleAction::class)->handle($project, new CyclePeriod(2026, 9));
        $report = app(EnsureMonthlyReportAction::class)->handle($cycle);

        app(UpdateMonthlyReportDraftAction::class)->handle($report, ['executive_summary' => 'Missed targets, honest month.']);
        AuthorityMetric::factory()->forCycle($cycle)->create();
        GscMonthlyMetric::factory()->forCycle($cycle)->create();
        Ga4MonthlyMetric::factory()->forCycle($cycle)->create();
        GscQueryMetric::factory()->forCycle($cycle)->create();
        GscPageMetric::factory()->forCycle($cycle)->create();
        app(CreateMonthlyNoteAction::class)->handle($cycle, ['type' => 'recommendation', 'body' => 'Push harder.'], $this->manager);
        $keyword = Keyword::factory()->forProject($project)->create();
        RankingSnapshot::factory()->forKeyword($keyword)->forCycle($cycle)->at('2026-09-10 09:00', 30)->create();
        ContentItem::factory()->forCycle($cycle)->type(ContentType::Blog)->published()->create();

        $progress = app(TargetProgressService::class);
        $this->assertSame('0 / 50', $progress->backlinks($cycle)->format());
        $this->assertSame('0 / 5', $progress->guestPosts($cycle)->format());
        $this->assertSame('1 / 8', $progress->blogs($cycle)->format());
        $this->assertSame('0 / 6', $progress->pagesOptimised($cycle)->format());

        $readiness = $this->service->evaluate($report);

        $this->assertTrue($readiness->isReady());
        $this->assertSame(100, $readiness->percentage());
        $this->assertSame(9, $readiness->requiredCount());
        $this->assertSame(9, $readiness->completedRequiredCount());
        // Audience by Country is optional and still empty; it neither blocks nor counts.
        $this->assertFalse($readiness->section('audience_country')->complete);
    }

    public function test_disabled_and_optional_sections_do_not_block_but_required_ones_do(): void
    {
        $this->satisfyEverything();
        $this->assertTrue($this->readiness()->isReady());

        // Configure a new report where Rankings is disabled, Landing Pages optional,
        // and Top Keywords required — then remove their data.
        app(UpdateProjectReportSectionsAction::class)->handle($this->project, [
            ['section_key' => 'rankings', 'is_enabled' => false, 'is_required' => true],
            ['section_key' => 'landing_pages', 'is_enabled' => true, 'is_required' => false],
        ]);
        $october = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 10));
        $report = app(EnsureMonthlyReportAction::class)->handle($october);
        app(UpdateMonthlyReportDraftAction::class)->handle($report, ['executive_summary' => 'October.']);
        AuthorityMetric::factory()->forCycle($october)->create();
        GscMonthlyMetric::factory()->forCycle($october)->create();
        Ga4MonthlyMetric::factory()->forCycle($october)->create();
        GscQueryMetric::factory()->forCycle($october)->create();
        app(CreateMonthlyNoteAction::class)->handle($october, ['type' => 'recommendation', 'body' => 'Keep going.'], $this->manager);
        $october->targets()->create(['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 10]);

        $readiness = $this->service->evaluate($report);

        // Rankings has no data and is disabled: ignored. Landing pages has no data and is optional: not blocking.
        $this->assertFalse($readiness->section('rankings')->enabled);
        $this->assertFalse($readiness->section('rankings')->complete);
        $this->assertNull($readiness->section('rankings')->reason);
        $this->assertFalse($readiness->section('landing_pages')->complete);
        $this->assertTrue($readiness->isReady());
        $this->assertSame(7, $readiness->requiredCount());
        $this->assertSame(100, $readiness->percentage());

        // A required section without data blocks and drives the percentage.
        $october->gscQueryMetrics()->delete();
        $readiness = $this->service->evaluate($report);

        $this->assertFalse($readiness->isReady());
        $this->assertSame(6, $readiness->completedRequiredCount());
        $this->assertSame(85, $readiness->percentage());
        $this->assertSame(['top_keywords'], $readiness->missing()->map(fn ($s) => $s->key->value)->all());
    }

    public function test_percentage_counts_enabled_required_sections_only_and_handles_zero(): void
    {
        $sections = $this->report->sections()->get();

        // 8 required-enabled, 1 disabled, 1 optional-enabled.
        $sections->firstWhere('section_key', ReportSectionKey::Rankings)->forceFill(['is_enabled' => false])->save();

        app(UpdateMonthlyReportDraftAction::class)->handle($this->report, ['executive_summary' => 'x']);
        AuthorityMetric::factory()->forCycle($this->september)->create();
        GscMonthlyMetric::factory()->forCycle($this->september)->create();
        Ga4MonthlyMetric::factory()->forCycle($this->september)->create();
        GscQueryMetric::factory()->forCycle($this->september)->create();
        GscPageMetric::factory()->forCycle($this->september)->create();
        Ga4CountryMetric::factory()->forCycle($this->september)->create();

        $readiness = $this->readiness();
        $this->assertSame(8, $readiness->requiredCount());
        $this->assertSame(6, $readiness->completedRequiredCount());
        $this->assertSame(75, $readiness->percentage());
        $this->assertFalse($readiness->isReady());

        // Zero required sections: nothing can block, nothing to divide.
        $this->report->sections()->update(['is_required' => false]);
        $readiness = $this->readiness();
        $this->assertSame(0, $readiness->requiredCount());
        $this->assertSame(100, $readiness->percentage());
        $this->assertTrue($readiness->isReady());
        $this->assertSame('0 / 0 required sections', $readiness->label());
    }

    public function test_stored_section_status_never_overrides_live_evaluation(): void
    {
        // Every stored status says complete, but no source data exists.
        $this->report->sections()->update(['status' => ReportSectionStatus::Complete->value]);

        $readiness = $this->readiness();
        $this->assertFalse($readiness->isReady());
        $this->assertSame(0, $readiness->completedRequiredCount());

        // Syncing writes the truth back for display.
        $synced = app(SyncReportSectionStatusesAction::class)->handle($this->report);
        $this->assertFalse($synced->isReady());
        $this->assertTrue($this->report->sections()->get()->every(fn ($s): bool => $s->status === ReportSectionStatus::Incomplete));

        $this->satisfyEverything();
        app(SyncReportSectionStatusesAction::class)->handle($this->report);
        $this->assertTrue($this->report->sections()->where('is_required', true)->get()->every(fn ($s): bool => $s->status === ReportSectionStatus::Complete));

        // Data removed later: stored says complete, live says otherwise.
        $this->september->authorityMetric()->delete();
        $this->assertSame(ReportSectionStatus::Complete, $this->report->sections()->where('section_key', 'site_authority')->first()->status);
        $this->assertFalse($this->complete('site_authority'));
        $this->assertFalse($this->readiness()->isReady());
    }
}
