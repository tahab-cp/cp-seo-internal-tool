<?php

namespace Tests\Feature\Backlinks;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Enums\BacklinkStatus;
use App\Enums\BacklinkType;
use App\Enums\MonthlyCycleStatus;
use App\Filament\Resources\Projects\Pages\ProjectBacklinks;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Backlink;
use App\Models\MonthlyCycle;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectBacklinksUiTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected Project $project;

    protected MonthlyCycle $september;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->manager = User::factory()->seoManager()->create();
        $package = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 50],
            ['target_key' => 'guest_posts', 'label' => 'Guest Posts', 'target_value' => 8],
        ])->create();
        $this->project = Project::factory()->withPackage($package)->create();
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));

        $this->actingAs($this->manager);
    }

    protected function page()
    {
        return Livewire::test(ProjectBacklinks::class, ['record' => $this->project->getRouteKey()]);
    }

    public function test_form_validation_and_creation_default_to_the_selected_month(): void
    {
        $this->page()
            ->callAction('createBacklink', data: [
                'monthly_cycle_id' => null,
                'published_url' => 'not a url',
                'target_url' => 'nope',
                'type' => null,
                'status' => null,
                'domain_authority' => 120,
                'spam_score' => -1,
            ])
            ->assertHasFormErrors([
                'monthly_cycle_id' => 'required',
                'published_url' => 'url',
                'target_url' => 'url',
                'type' => 'required',
                'status' => 'required',
                'domain_authority' => 'max',
                'spam_score' => 'min',
            ]);

        $this->assertDatabaseCount('backlinks', 0);

        $this->page()
            ->assertSet('selectedCycle', (string) $this->september->id)
            ->callAction('createBacklink', data: [
                'monthly_cycle_id' => $this->september->id,
                'published_url' => 'https://blog.example/guest',
                'anchor_text' => 'luxury villas',
                'target_url' => 'https://casa.example/villas',
                'type' => 'guest_post',
                'status' => 'live',
                'published_date' => '2026-09-10',
                'domain_authority' => 45,
                'domain_rating' => 50,
                'spam_score' => 1,
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Backlink added');

        $backlink = Backlink::query()->where('published_url', 'https://blog.example/guest')->firstOrFail();

        $this->assertTrue($backlink->createdBy->is($this->manager));
        $this->assertSame(BacklinkType::GuestPost, $backlink->type);
        $this->assertSame(45, $backlink->domain_authority);
    }

    public function test_the_header_shows_progress_remaining_and_live_type_breakdown_for_the_selected_month(): void
    {
        Backlink::factory()->count(20)->forCycle($this->september)->status(BacklinkStatus::Live)->create();
        Backlink::factory()->count(5)->forCycle($this->september)->status(BacklinkStatus::Live)->guestPost()->create();
        Backlink::factory()->count(3)->forCycle($this->september)->status(BacklinkStatus::Submitted)->guestPost()->create();
        Backlink::factory()->count(2)->forCycle($this->september)->status(BacklinkStatus::Removed)->create();
        Backlink::factory()->count(4)->forCycle($this->september)->status(BacklinkStatus::Live)->type(BacklinkType::Profile)->create();

        $this->get(ProjectResource::getUrl('backlinks', ['record' => $this->project]))
            ->assertOk()
            ->assertSee('data-backlinks-actual="29"', false)
            ->assertSee('data-backlinks-target="50"', false)
            ->assertSee('29 / 50')
            ->assertSee('21 remaining')
            ->assertSee('data-guest-posts-actual="5"', false)
            ->assertSee('5 / 8')
            ->assertSee('3 remaining')
            ->assertSee('data-type-breakdown="citation">20<', false)
            ->assertSee('data-type-breakdown="guest_post">5<', false)
            ->assertSee('data-type-breakdown="profile">4<', false)
            ->assertDontSee('data-type-breakdown="directory"', false);
    }

    public function test_over_target_and_no_target_render_safely(): void
    {
        Backlink::factory()->count(55)->forCycle($this->september)->status(BacklinkStatus::Live)->create();

        $this->get(ProjectResource::getUrl('backlinks', ['record' => $this->project]))
            ->assertOk()
            ->assertSee('55 / 50')
            ->assertSee('5 over target')
            ->assertSee('data-backlinks-remaining="0"', false)
            ->assertDontSee('data-backlinks-remaining="-5"', false)
            ->assertDontSee('-5 remaining');

        $bare = Project::factory()->create();
        $cycle = app(CreateMonthlyCycleAction::class)->handle($bare, new CyclePeriod(2026, 9));
        Backlink::factory()->count(12)->forCycle($cycle)->status(BacklinkStatus::Live)->create();

        $this->get(ProjectResource::getUrl('backlinks', ['record' => $bare]))
            ->assertOk()
            ->assertSee('12 / No target')
            ->assertSee('No target configured for this month')
            ->assertDontSee('12 / 0');
    }

    public function test_month_selector_and_all_time_view(): void
    {
        $october = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 10));
        $sep = Backlink::factory()->forCycle($this->september)->status(BacklinkStatus::Live)->create(['published_url' => 'https://sep.example/1']);
        $oct = Backlink::factory()->forCycle($october)->status(BacklinkStatus::Live)->guestPost()->create(['published_url' => 'https://oct.example/1']);

        $page = $this->page();

        $page->assertSet('selectedCycle', (string) $this->september->id)
            ->assertCanSeeTableRecords([$sep])
            ->assertCanNotSeeTableRecords([$oct])
            ->assertSee('1 / 50')
            ->set('selectedCycle', (string) $october->id)
            ->assertCanSeeTableRecords([$oct])
            ->assertCanNotSeeTableRecords([$sep])
            ->assertSee('1 / 8')
            ->set('selectedCycle', 'all')
            ->assertCanSeeTableRecords([$sep, $oct])
            ->assertSee('All-time view');

        $this->get(ProjectResource::getUrl('backlinks', ['record' => $this->project, 'cycle' => $october->id]))
            ->assertOk()
            ->assertSee('October 2026');
    }

    public function test_search_filters_and_status_workflow(): void
    {
        $citation = Backlink::factory()->forCycle($this->september)->status(BacklinkStatus::Submitted)->create([
            'published_url' => 'https://alpha.example/post', 'anchor_text' => 'alpha anchor', 'target_url' => 'https://casa.example/a', 'domain_authority' => 30, 'domain_rating' => 20,
        ]);
        $guest = Backlink::factory()->forCycle($this->september)->status(BacklinkStatus::Live)->guestPost()->create([
            'published_url' => 'https://beta.example/post', 'anchor_text' => 'beta anchor', 'target_url' => 'https://casa.example/b', 'domain_authority' => 70, 'domain_rating' => 65,
        ]);

        $this->page()
            ->searchTable('alpha anchor')->assertCanSeeTableRecords([$citation])->assertCanNotSeeTableRecords([$guest])
            ->searchTable('casa.example/b')->assertCanSeeTableRecords([$guest])->assertCanNotSeeTableRecords([$citation])
            ->searchTable('')
            ->filterTable('type', 'guest_post')->assertCanSeeTableRecords([$guest])->assertCanNotSeeTableRecords([$citation])
            ->resetTableFilters()
            ->filterTable('status', 'submitted')->assertCanSeeTableRecords([$citation])->assertCanNotSeeTableRecords([$guest])
            ->resetTableFilters()
            ->filterTable('domain_authority', ['da_min' => 50, 'da_max' => 100])->assertCanSeeTableRecords([$guest])->assertCanNotSeeTableRecords([$citation])
            ->resetTableFilters()
            ->filterTable('domain_rating', ['dr_min' => null, 'dr_max' => 30])->assertCanSeeTableRecords([$citation])->assertCanNotSeeTableRecords([$guest])
            ->resetTableFilters()
            ->callTableAction('setStatus', $citation, data: ['status' => 'live'])
            ->assertNotified('Status updated');

        $this->assertSame(BacklinkStatus::Live, $citation->fresh()->status);
        $this->assertNotSoftDeleted($citation);

        $this->get(ProjectResource::getUrl('backlinks', ['record' => $this->project]))
            ->assertSee('2 / 50')
            ->assertSee('1 / 8');
    }

    public function test_locked_months_are_read_only_in_the_ui(): void
    {
        $frozen = Backlink::factory()->forCycle($this->september)->status(BacklinkStatus::Live)->create(['anchor_text' => 'frozen']);
        $october = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 10));
        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();

        $this->page()
            ->assertSee('Locked')
            ->assertTableActionHidden('edit', $frozen)
            ->assertTableActionHidden('setStatus', $frozen)
            ->mountTableAction('setStatus', $frozen)
            ->callMountedTableAction()
            ->callAction('createBacklink', data: [
                'monthly_cycle_id' => $this->september->id,
                'published_url' => 'https://late.example/post',
                'type' => 'citation',
                'status' => 'live',
            ])
            ->assertHasFormErrors(['monthly_cycle_id']);

        $this->assertSame('frozen', $frozen->fresh()->anchor_text);
        $this->assertSame(BacklinkStatus::Live, $frozen->fresh()->status);
        $this->assertSame(1, Backlink::query()->count());

        // October is open.
        $this->page()
            ->callAction('createBacklink', data: [
                'monthly_cycle_id' => $october->id,
                'published_url' => 'https://october.example/post',
                'type' => 'citation',
                'status' => 'planned',
            ])
            ->assertHasNoFormErrors();

        $this->assertSame(1, $october->backlinks()->count());
    }
}
