<?php

namespace Tests\Feature\Keywords;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Enums\MonthlyCycleStatus;
use App\Filament\Resources\Projects\Pages\ProjectKeywordDetail;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Client;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\Page;
use App\Models\Project;
use App\Models\RankingSnapshot;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Presentation of the redesigned Keyword detail screen. Values come from
 * the existing records and RankingMovementService; only their placement
 * and wording are asserted.
 */
class ProjectKeywordDetailLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected User $executive;

    protected Project $project;

    protected MonthlyCycle $september;

    protected Page $page;

    protected Keyword $keyword;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->manager = User::factory()->seoManager()->create();
        $this->executive = User::factory()->seoExecutive()->create();
        $client = Client::factory()->create(['name' => 'BrightNest Interiors']);
        $this->project = Project::factory()->forClient($client)->ownedBy($this->executive)->create([
            'name' => 'BrightNest Manchester SEO', 'website_url' => 'https://www.example.com', 'target_location' => 'Manchester, UK',
        ]);
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
        $this->page = Page::factory()->forProject($this->project)->create(['url' => 'https://www.example.com/interior-design', 'path' => '/interior-design', 'title' => 'Interior Design']);
        $this->keyword = Keyword::factory()->targeting($this->page)->create([
            'keyword' => 'interior designers manchester', 'search_intent' => 'commercial', 'keyword_role' => 'primary',
            'location' => 'Manchester', 'search_volume' => 900, 'keyword_difficulty' => 34, 'is_branded' => false, 'status' => 'active',
        ]);
    }

    protected function detailUrl(Keyword $keyword): string
    {
        return ProjectResource::getUrl('keyword', ['record' => $this->project, 'keyword' => $keyword]);
    }

    protected function detail(Keyword $keyword)
    {
        return Livewire::test(ProjectKeywordDetail::class, ['record' => $this->project->getRouteKey(), 'keyword' => $keyword->getRouteKey()]);
    }

    public function test_the_workspace_header_and_keyword_header_render_with_keywords_active(): void
    {
        $this->actingAs($this->manager);
        $html = $this->get($this->detailUrl($this->keyword))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<h1[^>]*>\s*BrightNest Manchester SEO\s*<\/h1>/s', $html));
        $this->assertStringContainsString('BrightNest Interiors • Manchester, UK', $html);
        $this->assertSame(1, preg_match('/aria-current="page"[^>]*data-project-module="keywords"|data-project-module="keywords"[^>]*aria-current="page"/', $html), 'Keywords is the active module');

        $this->assertSame(1, preg_match('/<h2[^>]*data-keyword-title>interior designers manchester<\/h2>/', $html));
        $this->assertStringContainsString('data-keyword-status="active"', $html);
        $this->assertSame(1, preg_match('/data-keyword-meta>Commercial • Primary • Manchester</', $html));
        $this->assertStringNotContainsString('data-keyword-branded', $html, 'non-branded keywords carry no branded badge');
        $this->assertStringNotContainsString('Project master data', $html);
        $this->assertStringContainsString('Keyword details can be updated at any time. Ranking records from a locked reporting month are read-only.', $html);
        $this->assertStringContainsString('Each ranking check is saved here. “Not Ranking” means the keyword was not found.', $html);
    }

    public function test_the_target_page_is_shown_as_a_title_with_a_muted_path_and_links_to_the_page(): void
    {
        $this->actingAs($this->manager);
        $html = $this->get($this->detailUrl($this->keyword))->assertOk()->getContent();

        $pageUrl = ProjectResource::getUrl('page', ['record' => $this->project, 'page' => $this->page]);
        $this->assertSame(1, preg_match('/<a[^>]*href="'.preg_quote($pageUrl, '/').'"[^>]*data-keyword-target-page="'.$this->page->id.'"[^>]*>.*?Interior Design.*?<\/a>/s', $html), 'the target page title links to the page detail');
        $this->assertSame(1, preg_match('/data-keyword-target>.*?Interior Design.*?\/interior-design.*?<\/div>\s*<\/div>/s', $html), 'the path follows the title as muted text');
        $this->assertStringNotContainsString('Interior Design https://www.example.com/interior-design', $html);

        // Without a target page: plain wording.
        $bare = Keyword::factory()->forProject($this->project)->create(['keyword' => 'bare keyword', 'location' => null, 'search_intent' => null, 'keyword_role' => null]);
        $html = $this->get($this->detailUrl($bare))->assertOk()->getContent();
        $this->assertStringContainsString('No target page assigned', $html);
        $this->assertSame(1, preg_match('/data-keyword-meta>Any location</', $html));
    }

    public function test_actions_keep_record_ranking_primary_and_status_changes_behind_more(): void
    {
        $this->actingAs($this->manager);

        $this->detail($this->keyword)
            ->assertActionVisible('backToKeywords')
            ->assertActionVisible('recordRanking')
            ->assertActionVisible('editKeyword')
            ->assertActionHidden('activate')
            ->assertActionVisible('pause')
            ->assertActionVisible('archive')
            ->assertActionDoesNotExist('delete')
            ->callAction('pause')
            ->assertNotified('Keyword paused')
            ->assertActionVisible('activate')
            ->assertActionHidden('pause')
            ->callAction('archive')
            ->assertNotified('Keyword archived');

        $this->assertSame('archived', $this->keyword->fresh()->status->value);

        $this->detail($this->keyword)->callAction('activate');
        $this->assertSame('active', $this->keyword->fresh()->status->value);
    }

    public function test_keyword_details_render_as_a_two_column_grid(): void
    {
        $this->actingAs($this->manager);
        $html = $this->get($this->detailUrl($this->keyword))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<dl\b[^>]*data-keyword-details[^>]*>/s', $html, $details));
        $this->assertStringContainsString('--cols-sm: repeat(2', $details[0]);
        foreach (['Keyword', 'Status', 'Target page', 'Role', 'Search volume', 'Keyword difficulty', 'Intent', 'Location', 'Branded'] as $term) {
            $this->assertStringContainsString($term, $html);
        }
        $this->assertSame(1, preg_match('/data-keyword-volume>900</', $html));
        $this->assertSame(1, preg_match('/data-keyword-difficulty>34</', $html));
        $this->assertSame(1, preg_match('/data-keyword-intent>Commercial</', $html));
        $this->assertSame(1, preg_match('/data-keyword-role>Primary</', $html));
        $this->assertSame(1, preg_match('/data-keyword-location>Manchester</', $html));
    }

    public function test_monthly_summary_renders_metric_cards_with_derived_movement_and_a_compact_selector(): void
    {
        RankingSnapshot::factory()->forKeyword($this->keyword)->forCycle($this->september)->at('2026-09-03 09:00', 24)->create();
        RankingSnapshot::factory()->forKeyword($this->keyword)->forCycle($this->september)->at('2026-09-10 10:00', 8)->create();

        $this->actingAs($this->manager);
        $html = $this->get($this->detailUrl($this->keyword))->assertOk()->getContent();

        $this->assertStringContainsString('data-keyword-period="September 2026"', $html);
        $this->assertStringContainsString('data-keyword-cycle-status="open"', $html);
        $this->assertSame(1, preg_match('/<div\b[^>]*data-keyword-metrics[^>]*>/s', $html, $metrics));
        $this->assertStringContainsString('--cols-sm: repeat(3', $metrics[0]);
        $this->assertSame(1, preg_match('/data-keyword-card="start".*?data-month-start>24<.*?Checked 3 Sep/s', $html));
        $this->assertSame(1, preg_match('/data-keyword-card="latest".*?data-month-latest>8<.*?Checked 10 Sep/s', $html));
        $this->assertSame(1, preg_match('/data-keyword-card="movement".*?fi-color-success[^>]*data-month-movement>Improved 16 positions<.*?24 → 8/s', $html));
        $this->assertStringNotContainsString('+16', $html);
        $this->assertSame(1, preg_match('/<label for="keyword-cycle"[^>]*>Reporting month<\/label>\s*<div style="min-width: 12rem">/s', $html), 'the month selector is compact');
        $this->assertStringNotContainsString('data-keyword-month-empty', $html);

        // Latest rank quick summary near the header.
        $this->assertSame(1, preg_match('/data-keyword-latest-rank>8</', $html));
        $this->assertStringContainsString('data-keyword-last-checked="2026-09-10"', $html);
        $this->assertStringContainsString('10 Sep 2026', $html);
    }

    public function test_every_movement_direction_uses_the_service_wording_and_tone(): void
    {
        $this->actingAs($this->manager);

        $cases = [
            [8, 14, 'Declined 6 positions', '8 → 14', 'fi-color-danger'],
            [null, 20, 'Now ranking at 20', 'Not Ranking → 20', 'fi-color-success'],
            [20, null, 'No longer ranking', '20 → Not Ranking', 'fi-color-warning'],
            [null, null, 'Not ranking', 'Not Ranking → Not Ranking', null],
            [12, 12, 'Unchanged', '12 → 12', null],
        ];

        foreach ($cases as [$start, $latest, $label, $transition, $tone]) {
            $keyword = Keyword::factory()->forProject($this->project)->create();
            RankingSnapshot::factory()->forKeyword($keyword)->forCycle($this->september)->at('2026-09-03 09:00', $start)->create();
            RankingSnapshot::factory()->forKeyword($keyword)->forCycle($this->september)->at('2026-09-10 09:00', $latest)->create();

            $html = $this->get($this->detailUrl($keyword))->assertOk()->getContent();
            $this->assertSame(1, preg_match('/data-keyword-card="movement".*?data-month-movement>'.preg_quote($label, '/').'<.*?'.preg_quote($transition, '/').'/s', $html, $card), $label);

            $this->assertSame(1, preg_match('/<div\b[^>]*data-keyword-card="movement".*?data-month-movement>/s', $html, $movement));
            if ($tone) {
                $this->assertStringContainsString($tone, $movement[0], $label.' carries its tone');
            } else {
                $this->assertStringNotContainsString('fi-color-', $movement[0], $label.' stays neutral');
            }
            $this->assertStringNotContainsString('data-month-latest>0<', $html);
        }
    }

    public function test_not_ranking_and_missing_months_render_clear_states_instead_of_zero_or_dashes(): void
    {
        RankingSnapshot::factory()->forKeyword($this->keyword)->forCycle($this->september)->at('2026-09-10 09:00', null)->create();

        $this->actingAs($this->manager);
        $html = $this->get($this->detailUrl($this->keyword))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/data-month-latest>Not Ranking</', $html));
        $this->assertSame(1, preg_match('/data-keyword-latest-rank>Not Ranking</', $html));
        $this->assertStringNotContainsString('data-month-latest>0<', $html);
        $this->assertSame(1, preg_match('/data-month-movement>No comparison yet<.*?Only one check this month/s', $html));

        // A month with no checks at all.
        $august = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 8));
        $html = $this->get($this->detailUrl($this->keyword).'?cycle='.$august->id)->assertOk()->getContent();
        $this->assertStringContainsString('data-keyword-period="August 2026"', $html);
        $this->assertSame(1, preg_match('/data-month-start>Not recorded</', $html));
        $this->assertSame(1, preg_match('/data-month-latest>Not recorded</', $html));
        $this->assertSame(1, preg_match('/data-month-movement>No data<.*?Nothing to compare yet/s', $html));
        $this->assertStringContainsString('No ranking checks have been recorded for August 2026.', $html);
        $this->assertStringContainsString('data-keyword-record-this-month', $html);
        $this->assertStringNotContainsString('data-month-start>—<', $html);

        // No cycles at all: a calm sentence.
        $bare = Project::factory()->create();
        $keyword = Keyword::factory()->forProject($bare)->create();
        $this->get(ProjectResource::getUrl('keyword', ['record' => $bare, 'keyword' => $keyword]))->assertOk()->assertSee('data-keyword-no-cycles', false);
    }

    public function test_history_renders_a_compact_empty_state_and_the_modal_container_without_snapshots(): void
    {
        $this->actingAs($this->manager);
        $html = $this->get($this->detailUrl($this->keyword))->assertOk()->getContent();

        $this->assertStringContainsString('data-keyword-history-empty', $html);
        $this->assertStringContainsString('No ranking history yet', $html);
        $this->assertStringContainsString('Record the first ranking check for this keyword to start tracking movement.', $html);
        $this->assertStringContainsString('data-keyword-record-first', $html);
        $this->assertStringContainsString("mountAction('recordRanking')", $html);
        $this->assertStringNotContainsString('fi-ta-empty-state', $html, 'no tall table empty state');
        $this->assertStringNotContainsString('data-keyword-latest', $html, 'no latest-rank summary without history');

        $start = strpos($html, 'wire:name="App\Filament\Resources\Projects\Pages\ProjectKeywordDetail"');
        $end = strpos($html, 'wire:name="Filament\Livewire\Notifications"', $start);
        $this->assertSame(1, substr_count(substr($html, $start, $end - $start), 'wire:partial="action-modals"'), 'the page keeps its action-modal container');

        $this->detail($this->keyword)
            ->callAction('recordRanking', data: ['monthly_cycle_id' => $this->september->id, 'checked_at' => '2026-09-10 09:00', 'source' => 'manual', 'position' => 8])
            ->assertHasNoFormErrors()
            ->assertNotified('Ranking recorded');

        $this->assertSame(8, $this->keyword->rankingSnapshots()->sole()->position);
    }

    public function test_history_rows_render_compactly_with_prominent_positions_and_short_urls(): void
    {
        $ranked = RankingSnapshot::factory()->forKeyword($this->keyword)->forCycle($this->september)->at('2026-09-10 10:00', 8)->create(['ranking_url' => 'https://www.example.com/interior-design/manchester/showroom-and-consultations']);
        $unranked = RankingSnapshot::factory()->forKeyword($this->keyword)->forCycle($this->september)->at('2026-09-03 09:00', null)->create();

        $this->actingAs($this->manager);
        $html = $this->get($this->detailUrl($this->keyword))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-keyword-history-empty', $html);
        $this->assertStringContainsString('10 Sep 2026, 10:00', $html);
        $this->assertStringContainsString('example.com/interior-design/manchester/s...', $html, 'long URLs truncate');
        $this->assertStringNotContainsString('>https://www.example.com/interior-design/manchester/showroom-and-consultations<', $html);

        $this->detail($this->keyword)
            ->assertCanSeeTableRecords([$ranked, $unranked])
            ->assertSee('Not Ranking')
            ->assertSee('Manual')
            ->assertSee('September 2026')
            ->assertTableActionVisible('edit', $ranked)
            ->assertTableActionDoesNotExist('delete', record: $ranked);

    }

    public function test_locked_history_is_read_only_and_flagged(): void
    {
        $snapshot = RankingSnapshot::factory()->forKeyword($this->keyword)->forCycle($this->september)->at('2026-09-03 09:00', 24)->create();
        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();

        $this->actingAs($this->manager);
        $html = $this->get($this->detailUrl($this->keyword))->assertOk()->getContent();

        $this->assertStringContainsString('data-keyword-cycle-status="locked"', $html);
        $this->assertStringContainsString('data-keyword-month-locked', $html);
        $this->assertStringContainsString('Locked · read-only', $html);
        $this->assertStringContainsString('September 2026 (locked)', $html);
        $this->assertStringNotContainsString('data-keyword-record-this-month', $html);

        $this->detail($this->keyword)
            ->assertTableActionHidden('edit', $snapshot)
            ->mountTableAction('edit', $snapshot)
            ->callMountedTableAction()
            ->callAction('editKeyword', data: ['keyword' => 'interior designers manchester', 'status' => 'active', 'search_volume' => 950])
            ->assertHasNoFormErrors();

        $this->assertSame(24, $snapshot->fresh()->position);
        $this->assertSame(950, $this->keyword->fresh()->search_volume, 'keyword details stay editable');
    }

    public function test_visibility_is_unchanged(): void
    {
        $outsider = User::factory()->seoExecutive()->create();
        $this->actingAs($outsider);
        $this->get($this->detailUrl($this->keyword))->assertNotFound();

        $this->actingAs($this->executive);
        $this->get($this->detailUrl($this->keyword))->assertOk()->assertSee('data-keyword-title>interior designers manchester<', false);
        $this->get('/admin')->assertOk()->assertDontSee(ProjectResource::getUrl('keywords', ['record' => $this->project]), 'Keywords is never a global navigation item');
    }
}
