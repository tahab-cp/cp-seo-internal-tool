<?php

namespace Tests\Feature\Pages;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Enums\MonthlyCycleStatus;
use App\Filament\Resources\Projects\Pages\ProjectPageDetail;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\MonthlyCycle;
use App\Models\Page;
use App\Models\PageOptimization;
use App\Models\Project;
use App\Models\User;
use App\Services\MonthlyCycles\TargetProgressService;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression for "Record optimisation" on the Page detail screen.
 *
 * Two guarantees: the action always writes against the page in the URL
 * (never the first page, the home page or another project's page), and a
 * page WITHOUT any optimisation history still renders the Livewire modal
 * container the action needs. Filament's page layout leaves that container
 * to the table view on HasTable pages, and the redesigned screen only
 * renders the table once the page has history — so pages without records
 * used to open no modal at all while the home page (with history) worked.
 */
class PageDetailRecordsCorrectPageTest extends TestCase
{
    use RefreshDatabase;

    protected const COMPONENT = 'App\Filament\Resources\Projects\Pages\ProjectPageDetail';

    protected User $manager;

    protected Project $project;

    protected MonthlyCycle $september;

    protected Page $home;

    protected Page $interior;

    protected Page $kitchen;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->manager = User::factory()->seoManager()->create();
        $this->project = Project::factory()->create(['website_url' => 'https://www.brightnest.example']);
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));

        $this->home = Page::factory()->forProject($this->project)->create(['url' => 'https://www.brightnest.example/', 'path' => '/', 'title' => 'Home']);
        $this->interior = Page::factory()->forProject($this->project)->create(['url' => 'https://www.brightnest.example/interior-design', 'path' => '/interior-design', 'title' => 'Interior Design']);
        $this->kitchen = Page::factory()->forProject($this->project)->create(['url' => 'https://www.brightnest.example/kitchen-design', 'path' => '/kitchen-design', 'title' => 'Kitchen Design']);
    }

    protected function detailUrl(Page $page): string
    {
        return ProjectResource::getUrl('page', ['record' => $this->project, 'page' => $page]);
    }

    protected function detail(Page $page)
    {
        return Livewire::test(ProjectPageDetail::class, ['record' => $this->project->getRouteKey(), 'page' => $page->getRouteKey()]);
    }

    protected function record(Page $page, string $notes, array $extra = []): void
    {
        $this->detail($page)
            ->assertSet('pageId', $page->getKey())
            ->callAction('recordOptimization', data: [
                'monthly_cycle_id' => $this->september->id,
                'optimized_at' => '2026-09-10 09:00',
                'content_updated' => true,
                'notes' => $notes,
            ] + $extra)
            ->assertHasNoFormErrors()
            ->assertNotified('Optimisation recorded');
    }

    /**
     * The number of action-modal containers rendered inside the page component itself
     * (the topbar and sidebar render their own; the notifications component follows the page).
     */
    protected function pageModalContainers(string $html): int
    {
        $start = strpos($html, 'wire:name="'.static::COMPONENT.'"');
        $end = strpos($html, 'wire:name="Filament\Livewire\Notifications"', $start);

        $this->assertNotFalse($start, 'the page component is rendered');
        $this->assertNotFalse($end, 'the notifications component follows the page component');

        return substr_count(substr($html, $start, $end - $start), 'wire:partial="action-modals"');
    }

    /**
     * Drive the page exactly as the browser does: extract the Livewire snapshot from the
     * rendered HTML and post updates to Livewire's endpoint.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    protected function livewireUpdate(string $snapshot, array $updates, array $calls): array
    {
        $component = $this->withHeaders(['X-Livewire' => 'true'])
            ->postJson('/livewire-3925760e/update', [
                '_token' => csrf_token(),
                'components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => $calls]],
            ])
            ->assertOk()
            ->json('components.0');

        return [$component['snapshot'], json_decode($component['snapshot'], true), $component['effects']];
    }

    protected function pageSnapshot(string $html): string
    {
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

        foreach ($matches[1] as $raw) {
            $json = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5);

            if ((json_decode($json, true)['memo']['name'] ?? null) === static::COMPONENT) {
                return $json;
            }
        }

        $this->fail('page component snapshot not found');
    }

    public function test_every_page_renders_the_modal_container_whether_or_not_it_has_history(): void
    {
        PageOptimization::factory()->forPage($this->home)->forCycle($this->september)->create();

        $this->actingAs($this->manager);

        foreach ([$this->home, $this->interior, $this->kitchen] as $page) {
            $html = $this->get($this->detailUrl($page))->assertOk()->getContent();

            $this->assertSame(1, $this->pageModalContainers($html), $page->title.' renders exactly one action-modal container');
            $this->assertStringContainsString("mountAction('recordOptimization'", $html);
        }

        // The layout itself is unchanged: history shows the table, no history shows the compact block.
        $this->assertStringNotContainsString('data-page-history-empty', $this->get($this->detailUrl($this->home))->getContent());
        $kitchen = $this->get($this->detailUrl($this->kitchen))->getContent();
        $this->assertStringContainsString('data-page-history-empty', $kitchen);
        $this->assertStringContainsString('data-page-record-first', $kitchen);
        $this->assertStringNotContainsString('fi-ta-empty-state', $kitchen);
    }

    public function test_the_browser_flow_records_against_the_page_in_the_url_on_a_page_without_history(): void
    {
        PageOptimization::factory()->forPage($this->home)->forCycle($this->september)->create(['notes' => 'existing home record']);

        $this->actingAs($this->manager);

        foreach ([$this->kitchen, $this->interior] as $page) {
            $html = $this->get($this->detailUrl($page))->assertOk()->getContent();
            $snapshot = $this->pageSnapshot($html);
            $this->assertSame($page->getKey(), json_decode($snapshot, true)['data']['pageId']);

            // Click "Record optimisation": the modal comes back as the action-modals partial ...
            [$snapshot, $state, $effects] = $this->livewireUpdate($snapshot, [], [['method' => 'mountAction', 'params' => ['recordOptimization']]]);
            $this->assertSame('recordOptimization', $state['data']['mountedActions'][0][0][0]['name'] ?? null);
            $this->assertStringContainsString('Record optimisation', $effects['partials']['action-modals'] ?? '');
            $this->assertStringContainsString('monthly_cycle_id', $effects['partials']['action-modals'] ?? '');
            $this->assertSame('sync-action-modals', $effects['dispatches'][0]['name'] ?? null);
            // ... and the initial HTML has the container that partial is morphed into.
            $this->assertSame(1, $this->pageModalContainers($html));

            // Submit the form.
            [, $state] = $this->livewireUpdate($snapshot, [
                'mountedActions.0.data.monthly_cycle_id' => (string) $this->september->id,
                'mountedActions.0.data.optimized_at' => '2026-09-10 09:00:00',
                'mountedActions.0.data.content_updated' => true,
                'mountedActions.0.data.notes' => 'browser '.$page->title,
            ], [['method' => 'callMountedAction', 'params' => []]]);
            $this->assertSame([], $state['data']['mountedActions'][0], 'the modal closed after saving');
        }

        $this->assertSame(['browser Kitchen Design'], PageOptimization::query()->where('page_id', $this->kitchen->id)->pluck('notes')->all());
        $this->assertSame(['browser Interior Design'], PageOptimization::query()->where('page_id', $this->interior->id)->pluck('notes')->all());
        $this->assertSame(['existing home record'], PageOptimization::query()->where('page_id', $this->home->id)->pluck('notes')->all(), 'nothing leaked onto the home page');
    }

    public function test_each_page_detail_records_against_its_own_page(): void
    {
        $this->actingAs($this->manager);

        $this->record($this->kitchen, 'kitchen work');
        $this->record($this->interior, 'interior work');
        $this->record($this->home, 'home work');

        $byPage = PageOptimization::query()->get()->groupBy('page_id')->map(fn ($events) => $events->pluck('notes')->all())->all();

        $this->assertSame(['kitchen work'], $byPage[$this->kitchen->id]);
        $this->assertSame(['interior work'], $byPage[$this->interior->id]);
        $this->assertSame(['home work'], $byPage[$this->home->id], 'nothing leaked onto the home page');
        $this->assertSame(3, PageOptimization::query()->count());
        $this->assertTrue(PageOptimization::query()->get()->every(fn (PageOptimization $event) => (int) $event->project_id === (int) $this->project->id));

        // The monthly target still counts distinct pages: a second Kitchen event adds nothing.
        $this->record($this->kitchen, 'kitchen second pass');
        $this->assertSame(3, app(TargetProgressService::class)->pagesOptimisedActual($this->september));
        $this->assertSame(4, PageOptimization::query()->count());
    }

    public function test_crafted_page_ids_are_ignored_and_foreign_pages_and_cycles_are_refused(): void
    {
        $other = Project::factory()->create();
        $foreign = Page::factory()->forProject($other)->create(['title' => 'Foreign']);

        $this->actingAs($this->manager);

        // Crafted form state naming another page of the same project, or another project's page, never wins.
        $this->record($this->interior, 'crafted same project', ['page_id' => $this->home->id]);
        $this->record($this->interior, 'crafted foreign project', ['page_id' => $foreign->id]);

        $this->assertSame(2, PageOptimization::query()->where('page_id', $this->interior->id)->count());
        $this->assertSame(0, PageOptimization::query()->where('page_id', $this->home->id)->count());
        $this->assertSame(0, PageOptimization::query()->where('page_id', $foreign->id)->count());

        // A foreign page cannot be mounted under this project at all.
        $this->get($this->detailUrl($foreign))->assertNotFound();

        // A foreign project's cycle is refused by validation and nothing is written.
        $foreignCycle = app(CreateMonthlyCycleAction::class)->handle($other, new CyclePeriod(2026, 9));
        $this->detail($this->kitchen)
            ->callAction('recordOptimization', data: ['monthly_cycle_id' => $foreignCycle->id, 'optimized_at' => '2026-09-10 09:00', 'content_updated' => true])
            ->assertHasFormErrors(['monthly_cycle_id']);
        $this->assertSame(0, PageOptimization::query()->where('page_id', $this->kitchen->id)->count());
    }

    public function test_locked_months_still_refuse_new_records_from_every_page(): void
    {
        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();
        $this->actingAs($this->manager);

        foreach ([$this->home, $this->interior, $this->kitchen] as $page) {
            $this->detail($page)
                ->callAction('recordOptimization', data: ['monthly_cycle_id' => $this->september->id, 'optimized_at' => '2026-09-10 09:00', 'content_updated' => true])
                ->assertHasFormErrors(['monthly_cycle_id']);
        }

        $this->assertSame(0, PageOptimization::query()->count());
    }
}
