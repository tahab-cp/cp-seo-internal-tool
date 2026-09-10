<?php

namespace Tests\Feature\Pages;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Enums\MonthlyCycleStatus;
use App\Filament\Resources\Projects\Pages\ProjectPageDetail;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Client;
use App\Models\MonthlyCycle;
use App\Models\Page;
use App\Models\PageOptimization;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Presentation of the redesigned Page detail screen. Values come from the
 * existing records and actions; only their placement is asserted.
 */
class ProjectPageDetailLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected User $executive;

    protected Project $project;

    protected MonthlyCycle $september;

    protected Page $home;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->manager = User::factory()->seoManager()->create(['name' => 'James Manager']);
        $this->executive = User::factory()->seoExecutive()->create();
        $client = Client::factory()->create(['name' => 'BrightNest Interiors']);
        $this->project = Project::factory()->forClient($client)->ownedBy($this->executive)->create([
            'name' => 'BrightNest Manchester SEO', 'website_url' => 'https://www.brightnest.example', 'target_location' => 'Manchester, UK',
        ]);
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
        $this->home = Page::factory()->forProject($this->project)->create(['url' => 'https://www.brightnest.example/', 'path' => '/', 'title' => 'Home', 'page_type' => 'Homepage']);
    }

    protected function detailUrl(Page $page): string
    {
        return ProjectResource::getUrl('page', ['record' => $this->project, 'page' => $page]);
    }

    public function test_the_workspace_header_and_page_header_render_with_pages_active(): void
    {
        $this->actingAs($this->manager);
        $html = $this->get($this->detailUrl($this->home))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<h1[^>]*>\s*BrightNest Manchester SEO\s*<\/h1>/s', $html));
        $this->assertStringContainsString('BrightNest Interiors • Manchester, UK', $html);
        $this->assertSame(1, preg_match('/aria-current="page"[^>]*data-project-module="pages"|data-project-module="pages"[^>]*aria-current="page"/', $html), 'Pages stays the active module');

        $this->assertSame(1, preg_match('/<h2[^>]*data-page-title>Home<\/h2>/', $html));
        $this->assertSame(1, preg_match('/data-page-type[^>]*>.*?Homepage/s', $html), 'the page type badge is shown next to the title');
        $this->assertStringContainsString('data-page-status="active"', $html);
        $this->assertSame(1, preg_match('/<a[^>]*href="https:\/\/www\.brightnest\.example\/"[^>]*target="_blank"[^>]*data-page-url/s', $html), 'the URL opens in a new tab');
        $this->assertStringContainsString('Page details can still be updated at any time. Optimisation records from a locked reporting month are read-only.', $html);
        $this->assertStringNotContainsString('Project master data', $html);
    }

    public function test_actions_keep_record_optimisation_primary_and_removal_behind_more(): void
    {
        $this->actingAs($this->manager);

        Livewire::test(ProjectPageDetail::class, ['record' => $this->project->getRouteKey(), 'page' => $this->home->getRouteKey()])
            ->assertActionVisible('recordOptimization')
            ->assertActionVisible('editPage')
            ->assertActionVisible('markRemoved')
            ->assertActionHidden('reactivate')
            ->assertActionVisible('backToPages')
            ->assertActionDoesNotExist('delete')
            ->callAction('markRemoved')
            ->assertActionHidden('recordOptimization')
            ->assertActionVisible('reactivate');

        $html = $this->get($this->detailUrl($this->home))->getContent();
        $this->assertStringContainsString('data-page-status="removed"', $html);
        $this->assertStringNotContainsString('data-page-record-first', $html, 'a removed page offers no record button');
    }

    public function test_details_and_current_month_summary_render_the_empty_states_compactly(): void
    {
        $this->actingAs($this->manager);
        $html = $this->get($this->detailUrl($this->home))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<dl\b[^>]*data-page-details[^>]*>/s', $html, $details));
        $this->assertStringContainsString('--cols-sm: repeat(2', $details[0]);
        foreach (['Title', 'Page type', 'URL', 'Status', 'Path', 'Last optimised', 'Optimisation records', 'Current month'] as $term) {
            $this->assertStringContainsString($term, $html);
        }
        $this->assertStringContainsString('data-page-last-optimised="never"', $html);
        $this->assertStringContainsString('Never optimised', $html);
        $this->assertStringContainsString('data-page-optimisation-count="0"', $html);

        $this->assertStringContainsString('data-page-month="September 2026"', $html);
        $this->assertStringContainsString('data-page-cycle-status="open"', $html);
        $this->assertStringContainsString('data-page-month-summary="none"', $html);
        $this->assertStringContainsString('Not recorded yet.', $html);
        $this->assertStringContainsString('data-page-record-this-month', $html);

        $this->assertStringContainsString('data-page-history-empty', $html);
        $this->assertStringContainsString('No optimisation work recorded yet', $html);
        $this->assertStringContainsString('data-page-record-first', $html);
        $this->assertStringContainsString('mountAction(\'recordOptimization\')', $html, 'the body buttons open the existing action');
        $this->assertStringNotContainsString('fi-ta-empty-state', $html, 'no tall table empty state');

        // No cycle at all: a calm message, nothing created.
        $bare = Project::factory()->ownedBy($this->executive)->create();
        $page = Page::factory()->forProject($bare)->create();
        $this->get(ProjectResource::getUrl('page', ['record' => $bare, 'page' => $page]))->assertOk()
            ->assertSee('data-page-cycle-status="none"', false)
            ->assertSee('data-page-month-summary="no-cycle"', false);
        $this->assertSame(0, $bare->monthlyCycles()->count());
    }

    public function test_history_and_month_summary_render_recorded_events_with_change_chips(): void
    {
        PageOptimization::factory()->forPage($this->home)->forCycle($this->september)->create(['optimized_at' => '2026-09-10 09:00:00', 'user_id' => $this->manager->id, 'meta_title_updated' => true, 'meta_description_updated' => true, 'content_updated' => false, 'internal_links_updated' => false, 'schema_updated' => false, 'notes' => 'Refreshed the title tags across the header']);
        $second = PageOptimization::factory()->forPage($this->home)->forCycle($this->september)->create(['optimized_at' => '2026-09-12 14:00:00', 'user_id' => $this->manager->id, 'meta_title_updated' => false, 'meta_description_updated' => false, 'content_updated' => true, 'internal_links_updated' => true, 'schema_updated' => false, 'notes' => 'Second pass']);

        $this->actingAs($this->manager);
        $html = $this->get($this->detailUrl($this->home))->assertOk()->getContent();

        $this->assertStringContainsString('data-page-last-optimised="2026-09-12"', $html);
        $this->assertStringContainsString('12 Sep 2026', $html);
        $this->assertSame(1, preg_match('/12 Sep 2026 <span[^>]*>· \d+ days ago<\/span>/', $html), 'a muted relative date follows the last optimised date');
        $this->assertStringContainsString('data-page-optimisation-count="2"', $html);
        $this->assertStringContainsString('data-page-month-summary="recorded" data-page-month-events="2"', $html);
        $this->assertStringContainsString('Recorded 12 Sep 2026 by James Manager', $html);
        $this->assertStringContainsString('2 optimisation events this month · counts once towards the monthly target.', $html);
        $this->assertSame(1, preg_match('/data-page-month-summary="recorded".*?Optimisation history/s', $html, $summary));
        foreach (['Meta title', 'Meta description', 'Content', 'Internal links'] as $change) {
            $this->assertStringContainsString($change, $summary[0], 'the union of changes across the month is shown as chips');
        }
        $this->assertStringNotContainsString('>Schema<', $summary[0]);
        $this->assertStringNotContainsString('data-page-history-empty', $html);

        Livewire::test(ProjectPageDetail::class, ['record' => $this->project->getRouteKey(), 'page' => $this->home->getRouteKey()])
            ->assertCanSeeTableRecords([$second])
            ->assertSee('Internal links')
            ->assertSee('Second pass')
            ->assertSee('September 2026')
            ->assertTableActionVisible('edit', $second)
            ->assertTableActionDoesNotExist('delete', record: $second);
    }

    public function test_locked_history_is_read_only_and_flagged(): void
    {
        $event = PageOptimization::factory()->forPage($this->home)->forCycle($this->september)->create(['notes' => 'Frozen']);
        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();

        $this->actingAs($this->manager);
        $html = $this->get($this->detailUrl($this->home))->assertOk()->getContent();

        $this->assertStringContainsString('data-page-cycle-status="locked"', $html);
        $this->assertStringContainsString('data-page-month-locked', $html);
        $this->assertStringContainsString('Locked · read-only', $html);
        $this->assertStringNotContainsString('data-page-record-this-month', $html);

        Livewire::test(ProjectPageDetail::class, ['record' => $this->project->getRouteKey(), 'page' => $this->home->getRouteKey()])
            ->assertTableActionHidden('edit', $event)
            ->mountTableAction('edit', $event)
            ->callMountedTableAction();

        $this->assertSame('Frozen', $event->fresh()->notes);
    }

    public function test_project_visibility_is_unchanged(): void
    {
        $outsider = User::factory()->seoExecutive()->create();
        $this->actingAs($outsider);
        $this->get($this->detailUrl($this->home))->assertNotFound();

        $this->actingAs($this->executive);
        $this->get($this->detailUrl($this->home))->assertOk()->assertSee('data-page-title>Home<', false);
    }
}
