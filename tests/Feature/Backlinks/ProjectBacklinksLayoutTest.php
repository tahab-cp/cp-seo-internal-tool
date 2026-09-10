<?php

namespace Tests\Feature\Backlinks;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Enums\BacklinkStatus;
use App\Enums\BacklinkType;
use App\Enums\MonthlyCycleStatus;
use App\Filament\Resources\Projects\Pages\ProjectBacklinks;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Backlink;
use App\Models\Client;
use App\Models\MonthlyCycle;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Presentation of the redesigned Project → Backlinks screen. Values come
 * from TargetProgressService and the records; only their placement and
 * wording are asserted.
 */
class ProjectBacklinksLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected User $executive;

    protected Project $project;

    protected MonthlyCycle $september;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->manager = User::factory()->seoManager()->create();
        $this->executive = User::factory()->seoExecutive()->create();
        $package = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 20],
            ['target_key' => 'guest_posts', 'label' => 'Guest Posts', 'target_value' => 4],
        ])->create();
        $client = Client::factory()->create(['name' => 'BrightNest Interiors']);
        $this->project = Project::factory()->forClient($client)->withPackage($package)->ownedBy($this->executive)->create([
            'name' => 'BrightNest Manchester SEO', 'website_url' => 'https://www.example.com', 'target_location' => 'Manchester, UK',
        ]);
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
    }

    protected function url(Project $project, array $extra = []): string
    {
        return ProjectResource::getUrl('backlinks', ['record' => $project] + $extra);
    }

    protected function page()
    {
        return Livewire::test(ProjectBacklinks::class, ['record' => $this->project->getRouteKey()]);
    }

    public function test_the_workspace_header_module_title_and_actions_render(): void
    {
        $this->actingAs($this->manager);
        $html = $this->get($this->url($this->project))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<h1[^>]*>\s*BrightNest Manchester SEO\s*<\/h1>/s', $html));
        $this->assertStringContainsString('BrightNest Interiors • Manchester, UK', $html);
        $this->assertSame(1, preg_match('/aria-current="page"[^>]*data-project-module="backlinks"|data-project-module="backlinks"[^>]*aria-current="page"/', $html), 'Backlinks is the active module');
        $this->assertSame(1, preg_match('/data-backlinks-header>.*?<h2[^>]*>Backlinks<\/h2>.*?Track monthly link-building work and guest post progress\./s', $html));
        $this->assertStringNotContainsString('Back to project', $html);
        $this->assertStringContainsString('Only live links count towards monthly progress. A live guest post counts towards both Backlinks and Guest Posts.', $html);
        $this->assertStringNotContainsString("month's snapshot", $html);

        $this->page()
            ->assertActionVisible('createBacklink')
            ->assertActionVisible('importCsv')
            ->assertActionDoesNotExist('viewProject');
    }

    public function test_progress_cards_show_actual_target_percentage_bar_and_remaining(): void
    {
        Backlink::factory()->count(9)->forCycle($this->september)->status(BacklinkStatus::Live)->create();
        Backlink::factory()->count(3)->forCycle($this->september)->status(BacklinkStatus::Live)->guestPost()->create();
        Backlink::factory()->count(2)->forCycle($this->september)->status(BacklinkStatus::Planned)->create();

        $this->actingAs($this->manager);
        $html = $this->get($this->url($this->project))->assertOk()->getContent();

        $this->assertStringContainsString('data-backlinks-period="September 2026"', $html);
        $this->assertStringContainsString('data-backlinks-cycle-status="open"', $html);
        $this->assertSame(1, preg_match('/<div\b[^>]*data-backlinks-metrics[^>]*>/s', $html, $metrics));
        $this->assertStringContainsString('--cols-sm: repeat(2', $metrics[0]);

        $this->assertSame(1, preg_match('/data-backlinks-card="backlinks".*?data-backlinks-actual="12" data-backlinks-target="20">12 \/ 20<.*?data-backlinks-percentage="60">60%<.*?aria-valuenow="60".*?width: 60%.*?data-backlinks-remaining="8">8 remaining</s', $html));
        $this->assertSame(1, preg_match('/data-backlinks-card="guest-posts".*?data-guest-posts-actual="3" data-guest-posts-target="4">3 \/ 4<.*?data-guest-posts-percentage="75">75%<.*?aria-valuenow="75".*?data-guest-posts-remaining="1">1 remaining</s', $html));
        $this->assertSame(1, preg_match('/<label for="backlinks-cycle"[^>]*>Reporting month<\/label>\s*<div style="min-width: 12rem">/s', $html), 'the month selector is compact');
        $this->assertStringNotContainsString('data-backlinks-locked', $html);
        $this->assertSame(1, preg_match('/data-backlinks-summary>14 backlinks recorded · 12 live</', $html));
    }

    public function test_over_target_and_no_target_states_render_without_capping_text_or_inventing_zero(): void
    {
        Backlink::factory()->count(24)->forCycle($this->september)->status(BacklinkStatus::Live)->create();

        $this->actingAs($this->manager);
        $html = $this->get($this->url($this->project))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/data-backlinks-actual="24" data-backlinks-target="20">24 \/ 20<.*?data-backlinks-percentage="120">120%<.*?aria-valuenow="100".*?width: 100%.*?data-backlinks-remaining="0">4 over target</s', $html));
        $this->assertSame(1, preg_match('/data-backlinks-card="backlinks".*?fi-color-success/s', $html), 'a met target reads as success');

        $bare = Project::factory()->create();
        $cycle = app(CreateMonthlyCycleAction::class)->handle($bare, new CyclePeriod(2026, 9));
        Backlink::factory()->count(12)->forCycle($cycle)->status(BacklinkStatus::Live)->create();

        $html = $this->get($this->url($bare))->assertOk()->getContent();
        $this->assertStringContainsString('data-backlinks-actual="12" data-backlinks-target="">12 / No target<', $html);
        $this->assertStringContainsString('No backlink target was configured for this reporting month.', $html);
        $this->assertStringContainsString('No guest post target was configured for this reporting month.', $html);
        $this->assertStringNotContainsString('data-backlinks-percentage', $html);
        $this->assertStringNotContainsString('role="progressbar"', $html, 'no bar, so no invented 0%');
        $this->assertSame(0, preg_match('/>\s*0%\s*</', $html), 'no invented 0%');
    }

    public function test_live_link_breakdown_lists_only_non_zero_types_for_live_links(): void
    {
        Backlink::factory()->count(3)->forCycle($this->september)->status(BacklinkStatus::Live)->guestPost()->create();
        Backlink::factory()->count(4)->forCycle($this->september)->status(BacklinkStatus::Live)->type(BacklinkType::Citation)->create();
        Backlink::factory()->count(2)->forCycle($this->september)->status(BacklinkStatus::Live)->type(BacklinkType::Directory)->create();
        Backlink::factory()->count(5)->forCycle($this->september)->status(BacklinkStatus::Submitted)->type(BacklinkType::Profile)->create();

        $this->actingAs($this->manager);
        $html = $this->get($this->url($this->project))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<div\b[^>]*data-backlinks-breakdown[^>]*>/s', $html, $grid));
        $this->assertStringContainsString('--cols-sm: repeat(3', $grid[0]);
        $this->assertStringContainsString('Live links only, for September 2026.', $html);
        $this->assertStringContainsString('data-type-breakdown="guest_post">3<', $html);
        $this->assertStringContainsString('data-type-breakdown="citation">4<', $html);
        $this->assertStringContainsString('data-type-breakdown="directory">2<', $html);
        $this->assertStringNotContainsString('data-type-breakdown="profile"', $html, 'submitted links are not live');
        $this->assertStringNotContainsString('data-type-breakdown="outreach"', $html);

        Backlink::query()->update(['status' => BacklinkStatus::Planned->value]);
        $this->get($this->url($this->project))->assertOk()->assertSee('No live links in September 2026 yet.');
    }

    public function test_all_time_view_hides_target_progress_and_labels_the_table(): void
    {
        $october = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 10));
        Backlink::factory()->forCycle($this->september)->status(BacklinkStatus::Live)->create();
        Backlink::factory()->forCycle($october)->status(BacklinkStatus::Planned)->create();

        $this->actingAs($this->manager);
        $html = $this->get($this->url($this->project, ['cycle' => 'all']))->assertOk()->getContent();

        $this->assertStringContainsString('data-backlinks-period="all"', $html);
        $this->assertStringContainsString('Select a reporting month to view target progress.', $html);
        $this->assertStringNotContainsString('data-backlinks-metrics', $html);
        $this->assertStringNotContainsString('data-backlinks-breakdown', $html);
        $this->assertSame(1, preg_match('/data-backlinks-table-heading>All backlinks</', $html));
        $this->assertSame(1, preg_match('/data-backlinks-summary>2 backlinks recorded</', $html));
        $this->assertStringContainsString('October 2026', $html, 'each row shows its reporting month in the all-time view');

        $html = $this->get($this->url($this->project, ['cycle' => $october->id]))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/data-backlinks-table-heading>Backlinks in October 2026</', $html));
    }

    public function test_the_table_presents_links_targets_types_metrics_and_statuses_compactly(): void
    {
        $guest = Backlink::factory()->forCycle($this->september)->status(BacklinkStatus::Live)->guestPost()->create([
            'published_url' => 'https://www.designexample.co.uk/brightnest/interior-design-trends-for-manchester-homes-2026',
            'anchor_text' => 'interior design Manchester',
            'target_url' => 'https://www.example.com/interior-design',
            'published_date' => '2026-09-10',
            'domain_authority' => 52,
            'domain_rating' => 48,
            'spam_score' => null,
        ]);
        $planned = Backlink::factory()->forCycle($this->september)->status(BacklinkStatus::Planned)->type(BacklinkType::Citation)->create([
            'published_url' => 'https://citations.example/listing', 'anchor_text' => null, 'target_url' => null, 'published_date' => null, 'domain_authority' => null, 'domain_rating' => null, 'spam_score' => null,
        ]);

        $this->actingAs($this->manager);

        $component = $this->page()
            ->assertCanSeeTableRecords([$guest, $planned])
            ->assertSee('10 Sep 2026')
            ->assertSee('designexample.co.uk/brightnest/interior-desig...')
            ->assertSee('Anchor: interior design Manchester')
            ->assertSee('example.com/interior-design')
            ->assertSee('Guest post')
            ->assertSee('Citation')
            ->assertSee('DA 52')
            ->assertSee('DR 48')
            ->assertSee('Spam —')
            ->assertSee('Not published')
            ->assertSee('Live')
            ->assertSee('Planned')
            ->assertTableActionVisible('edit', $guest)
            ->assertTableActionVisible('setStatus', $guest)
            ->assertTableActionDoesNotExist('delete', record: $guest);

        $html = $component->html();
        $this->assertSame(1, preg_match('/<a[^>]*href="https:\/\/www\.designexample\.co\.uk\/brightnest\/interior-design-trends-for-manchester-homes-2026"[^>]*target="_blank"/s', $html), 'the link opens the full URL in a new tab');
        $this->assertStringNotContainsString('>https://www.designexample.co.uk/brightnest/interior-design-trends-for-manchester-homes-2026<', $html, 'the full URL is not printed as text');
        $this->assertStringContainsString('Search link, anchor or target', $html);
    }

    public function test_the_empty_state_is_compact_and_offers_the_existing_actions(): void
    {
        $this->actingAs($this->manager);
        $html = $this->get($this->url($this->project))->assertOk()->getContent();

        $this->assertStringContainsString('No backlinks recorded yet', $html);
        $this->assertStringContainsString('Add link-building work for this reporting month to track progress and include it in the monthly report.', $html);
        $this->assertStringNotContainsString('data-backlinks-summary', $html);

        // The empty-state button is the SAME Add backlink workflow (BacklinkForm + CreateBacklinkAction).
        $this->page()
            ->assertSee('Import CSV')
            ->callTableAction('createFirstBacklink', data: [
                'monthly_cycle_id' => $this->september->id,
                'published_url' => 'https://blog.example/first',
                'type' => 'citation',
                'status' => 'live',
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Backlink added');

        $this->assertSame(1, Backlink::query()->where('published_url', 'https://blog.example/first')->count());
    }

    public function test_locked_months_are_flagged_and_read_only(): void
    {
        $frozen = Backlink::factory()->forCycle($this->september)->status(BacklinkStatus::Live)->create(['anchor_text' => 'frozen']);
        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();

        $this->actingAs($this->manager);
        $html = $this->get($this->url($this->project))->assertOk()->getContent();

        $this->assertStringContainsString('data-backlinks-cycle-status="locked"', $html);
        $this->assertStringContainsString('This reporting month is locked. Backlink records are read-only.', $html);
        $this->assertStringContainsString('September 2026 (locked)', $html);

        $this->page()
            ->assertTableActionHidden('edit', $frozen)
            ->assertTableActionHidden('setStatus', $frozen)
            ->mountTableAction('setStatus', $frozen)
            ->callMountedTableAction();

        $this->assertSame(BacklinkStatus::Live, $frozen->fresh()->status);
    }

    public function test_visibility_is_unchanged(): void
    {
        $outsider = User::factory()->seoExecutive()->create();
        $this->actingAs($outsider);
        $this->get($this->url($this->project))->assertNotFound();

        $this->actingAs($this->executive);
        $this->get($this->url($this->project))->assertOk()->assertSee('data-project-module="backlinks"', false);
        $this->get('/admin')->assertOk()->assertDontSee($this->url($this->project), 'Backlinks is never a global navigation item');
    }
}
