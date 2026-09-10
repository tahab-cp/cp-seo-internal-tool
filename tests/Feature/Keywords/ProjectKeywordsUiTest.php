<?php

namespace Tests\Feature\Keywords;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Enums\MonthlyCycleStatus;
use App\Filament\Resources\Projects\Pages\ProjectKeywordDetail;
use App\Filament\Resources\Projects\Pages\ProjectKeywords;
use App\Filament\Resources\Projects\ProjectResource;
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

class ProjectKeywordsUiTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected Project $project;

    protected MonthlyCycle $september;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-25 10:00:00');

        $this->manager = User::factory()->seoManager()->create();
        $this->project = Project::factory()->create();
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));

        $this->actingAs($this->manager);
    }

    protected function detail(Keyword $keyword)
    {
        return Livewire::test(ProjectKeywordDetail::class, ['record' => $this->project->getRouteKey(), 'keyword' => $keyword->getRouteKey()]);
    }

    public function test_keyword_form_validation_and_creation(): void
    {
        $page = Page::factory()->forProject($this->project)->create();
        $foreignPage = Page::factory()->create();
        Keyword::factory()->forProject($this->project)->create(['keyword' => 'SEO Agency London']);

        Livewire::test(ProjectKeywords::class, ['record' => $this->project->getRouteKey()])
            ->callAction('createKeyword', data: ['keyword' => '', 'status' => null, 'search_volume' => -1, 'target_page_id' => $foreignPage->id])
            ->assertHasFormErrors(['keyword' => 'required', 'status' => 'required', 'search_volume' => 'min', 'target_page_id']);

        // Normalised duplicate surfaces as a notification, not an error page.
        Livewire::test(ProjectKeywords::class, ['record' => $this->project->getRouteKey()])
            ->callAction('createKeyword', data: ['keyword' => ' seo agency london ', 'status' => 'active'])
            ->assertNotified('Keyword "seo agency london" (any location) is already tracked in project "'.$this->project->name.'".');

        $this->assertSame(1, $this->project->keywords()->count());

        Livewire::test(ProjectKeywords::class, ['record' => $this->project->getRouteKey()])
            ->callAction('createKeyword', data: [
                'keyword' => 'Luxury Villas',
                'status' => 'active',
                'target_page_id' => $page->id,
                'search_intent' => 'commercial',
                'keyword_role' => 'primary',
                'search_volume' => 880,
                'keyword_difficulty' => 42,
                'location' => 'London',
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Keyword added');

        $keyword = Keyword::query()->where('keyword', 'Luxury Villas')->firstOrFail();

        $this->assertTrue($keyword->targetPage->is($page));
        $this->assertSame('london', $keyword->location_normalized);
    }

    public function test_the_list_shows_derived_latest_rank_and_supports_search_and_filters(): void
    {
        $page = Page::factory()->forProject($this->project)->create(['url' => 'https://site.example/villas', 'title' => 'Villas']);
        $ranked = Keyword::factory()->targeting($page)->create(['keyword' => 'alpha villas', 'is_branded' => true, 'search_intent' => 'commercial']);
        $unranked = Keyword::factory()->forProject($this->project)->create(['keyword' => 'beta cottages']);
        RankingSnapshot::factory()->forKeyword($ranked)->forCycle($this->september)->at('2026-09-01 09:00', 24)->create();
        RankingSnapshot::factory()->forKeyword($ranked)->forCycle($this->september)->at('2026-09-20 09:00', 8)->create();
        RankingSnapshot::factory()->forKeyword($unranked)->forCycle($this->september)->at('2026-09-20 09:00', null)->create();

        $list = Livewire::test(ProjectKeywords::class, ['record' => $this->project->getRouteKey()]);

        $list->assertCanSeeTableRecords([$ranked, $unranked])
            ->assertSee('Not Ranking')
            ->assertSee('20 Sep 2026')
            ->searchTable('cottages')->assertCanSeeTableRecords([$unranked])->assertCanNotSeeTableRecords([$ranked])
            ->searchTable('site.example/villas')->assertCanSeeTableRecords([$ranked])->assertCanNotSeeTableRecords([$unranked])
            ->searchTable('')
            ->filterTable('is_branded', true)->assertCanSeeTableRecords([$ranked])->assertCanNotSeeTableRecords([$unranked])
            ->resetTableFilters()
            ->filterTable('search_intent', 'commercial')->assertCanSeeTableRecords([$ranked])->assertCanNotSeeTableRecords([$unranked])
            ->resetTableFilters()
            ->filterTable('target_page_id', $page->id)->assertCanSeeTableRecords([$ranked])->assertCanNotSeeTableRecords([$unranked]);

        // Latest rank is the latest snapshot: 8, not 24.
        $this->get(ProjectResource::getUrl('keywords', ['record' => $this->project]))
            ->assertOk()
            ->assertSee('alpha villas');
        $this->assertSame(8, Keyword::query()->with('latestSnapshot')->find($ranked->id)->latestSnapshot->position);
    }

    public function test_keyword_detail_shows_summary_and_history_and_records_rankings(): void
    {
        $keyword = Keyword::factory()->forProject($this->project)->create(['keyword' => 'luxury villas london']);

        $this->detail($keyword)
            ->assertSee('luxury villas london')
            ->assertSee('No ranking history yet')
            ->callAction('recordRanking', data: ['monthly_cycle_id' => $this->september->id, 'checked_at' => '2026-09-02 09:00', 'source' => 'manual', 'position' => 24])
            ->assertHasNoFormErrors()
            ->assertNotified('Ranking recorded');

        $this->detail($keyword)
            ->callAction('recordRanking', data: ['monthly_cycle_id' => $this->september->id, 'checked_at' => '2026-09-22 09:00', 'source' => 'manual', 'position' => 8, 'ranking_url' => 'https://site.example/villas'])
            ->assertHasNoFormErrors();

        $this->assertSame(2, $keyword->rankingSnapshots()->count());

        $this->get(ProjectResource::getUrl('keyword', ['record' => $this->project, 'keyword' => $keyword]))
            ->assertOk()
            ->assertSee('data-month-start>24<', false)
            ->assertSee('data-month-latest>8<', false)
            ->assertSee('Improved 16 positions')
            ->assertSee('24 → 8')
            ->assertSee('September 2026');

        // Position zero is refused by the form.
        $this->detail($keyword)
            ->callAction('recordRanking', data: ['monthly_cycle_id' => $this->september->id, 'checked_at' => '2026-09-23 09:00', 'source' => 'manual', 'position' => 0])
            ->assertHasFormErrors(['position' => 'min']);

        // Correcting an observation.
        $latest = $keyword->rankingSnapshots()->first();

        $this->detail($keyword)
            ->assertCanSeeTableRecords($keyword->rankingSnapshots()->get())
            ->callTableAction('edit', $latest, data: ['monthly_cycle_id' => $this->september->id, 'checked_at' => '2026-09-22 09:00', 'source' => 'manual', 'position' => 7])
            ->assertNotified('Observation corrected');

        $this->assertSame(7, $latest->fresh()->position);
    }

    public function test_detail_shows_not_ranking_and_one_snapshot_month_without_movement(): void
    {
        $keyword = Keyword::factory()->forProject($this->project)->create();
        RankingSnapshot::factory()->forKeyword($keyword)->forCycle($this->september)->at('2026-09-10 09:00', null)->create();

        $this->get(ProjectResource::getUrl('keyword', ['record' => $this->project, 'keyword' => $keyword]))
            ->assertOk()
            ->assertSee('data-month-latest>Not Ranking<', false)
            ->assertSee('No comparison yet')
            ->assertDontSee('data-month-latest>0<', false);
    }

    public function test_month_selector_uses_each_cycles_own_snapshots(): void
    {
        $keyword = Keyword::factory()->forProject($this->project)->create();
        $august = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 8));
        RankingSnapshot::factory()->forKeyword($keyword)->forCycle($august)->at('2026-08-02 09:00', 50)->create();
        RankingSnapshot::factory()->forKeyword($keyword)->forCycle($august)->at('2026-08-25 09:00', 40)->create();
        RankingSnapshot::factory()->forKeyword($keyword)->forCycle($this->september)->at('2026-09-02 09:00', 24)->create();
        RankingSnapshot::factory()->forKeyword($keyword)->forCycle($this->september)->at('2026-09-22 09:00', 8)->create();

        $this->detail($keyword)
            ->assertSet('selectedCycleId', $this->september->id)
            ->assertSee('Improved 16 positions')
            ->set('selectedCycleId', $august->id)
            ->assertSee('Improved 10 positions')
            ->assertDontSee('Improved 16 positions');
    }

    public function test_locked_months_are_read_only_in_the_ui_but_keyword_stays_editable(): void
    {
        $keyword = Keyword::factory()->forProject($this->project)->create(['keyword' => 'frozen keyword']);
        $snapshot = RankingSnapshot::factory()->forKeyword($keyword)->forCycle($this->september)->at('2026-09-02 09:00', 24)->create();
        $october = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 10));
        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();

        $this->detail($keyword)
            ->assertTableActionHidden('edit', $snapshot)
            ->mountTableAction('edit', $snapshot)
            ->callMountedTableAction()
            ->callAction('recordRanking', data: ['monthly_cycle_id' => $this->september->id, 'checked_at' => '2026-09-28 09:00', 'source' => 'manual', 'position' => 3])
            ->assertHasFormErrors(['monthly_cycle_id']);

        $this->assertSame(24, $snapshot->fresh()->position);
        $this->assertSame(1, RankingSnapshot::query()->count());

        $this->detail($keyword)
            ->callAction('recordRanking', data: ['monthly_cycle_id' => $october->id, 'checked_at' => '2026-10-02 09:00', 'source' => 'manual', 'position' => 3])
            ->assertHasNoFormErrors()
            ->callAction('editKeyword', data: ['keyword' => 'frozen keyword', 'status' => 'active', 'search_volume' => 500])
            ->assertHasNoFormErrors()
            ->callAction('pause');

        $this->assertSame(500, $keyword->fresh()->search_volume);
        $this->assertSame('paused', $keyword->fresh()->status->value);
        $this->assertSame(1, $october->rankingSnapshots()->count());
    }
}
