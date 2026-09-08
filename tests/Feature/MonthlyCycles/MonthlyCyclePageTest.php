<?php

namespace Tests\Feature\MonthlyCycles;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Packages\SyncPackageTargetsAction;
use App\Filament\Resources\Projects\Pages\ProjectMonthlyCycles;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\MonthlyCycle;
use App\Models\MonthlyCycleTarget;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

class MonthlyCyclePageTest extends TestCase
{
    use RefreshDatabase;

    protected Package $growth;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 08:00:00');

        $this->growth = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 50],
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 8],
        ])->create();
    }

    public function test_manager_and_admin_may_manually_ensure_the_current_cycle(): void
    {
        foreach ([
            User::factory()->seoManager()->create(),
            User::factory()->superAdmin()->create(),
        ] as $user) {
            $this->actingAs($user);
            $project = Project::factory()->withPackage($this->growth)->create();
            $project->targetOverrides()->create(['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 40]);

            $this->assertTrue($user->can('ensureMonthlyCycle', $project));

            Livewire::test(ProjectMonthlyCycles::class, ['record' => $project->getRouteKey()])
                ->assertOk()
                ->assertSee('No monthly cycles yet')
                ->assertActionVisible('ensureCurrentMonth')
                ->callAction('ensureCurrentMonth')
                ->assertNotified('September 2026 cycle ready')
                ->assertActionHidden('ensureCurrentMonth')
                ->assertSee('September 2026')
                ->assertSee('Open')
                ->assertSee('Backlinks')
                ->assertSee('40');

            $this->assertSame(1, $project->monthlyCycles()->count());
        }
    }

    public function test_ensuring_is_idempotent_from_the_page(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());
        $project = Project::factory()->withPackage($this->growth)->create();
        $cycle = app(CreateMonthlyCycleAction::class)->handle($project, CyclePeriod::current());

        Livewire::test(ProjectMonthlyCycles::class, ['record' => $project->getRouteKey()])
            ->assertActionHidden('ensureCurrentMonth')
            ->mountAction('ensureCurrentMonth')
            ->callMountedAction();

        $this->assertSame(1, $project->monthlyCycles()->count());
        $this->assertSame(2, $cycle->targets()->count());
    }

    public function test_executive_cannot_manually_ensure_or_create_a_cycle(): void
    {
        $executive = User::factory()->seoExecutive()->create();
        $project = Project::factory()->withPackage($this->growth)->ownedBy($executive)->create();

        $this->actingAs($executive);

        $this->assertFalse($executive->can('ensureMonthlyCycle', $project));

        Livewire::test(ProjectMonthlyCycles::class, ['record' => $project->getRouteKey()])
            ->assertOk()
            ->assertActionHidden('ensureCurrentMonth')
            ->assertDontSee('Ensure September 2026')
            ->mountAction('ensureCurrentMonth')
            ->callMountedAction();

        $this->assertSame(0, $project->monthlyCycles()->count());
    }

    public function test_executive_may_view_cycles_for_an_assigned_project(): void
    {
        $executive = User::factory()->seoExecutive()->create();
        $owned = Project::factory()->withPackage($this->growth)->ownedBy($executive)->create();
        $member = Project::factory()->withPackage($this->growth)->withTeam([$executive])->create();

        foreach ([$owned, $member] as $project) {
            $cycle = app(CreateMonthlyCycleAction::class)->handle($project, new CyclePeriod(2026, 9));

            $this->actingAs($executive);

            $this->get(ProjectResource::getUrl('monthly-cycles', ['record' => $project]))
                ->assertOk()
                ->assertSee('September 2026')
                ->assertSee('Backlinks');

            $this->assertTrue($executive->can('view', $cycle));
        }
    }

    public function test_executive_cannot_access_unrelated_project_cycles(): void
    {
        $executive = User::factory()->seoExecutive()->create();
        $unrelated = Project::factory()->withPackage($this->growth)->create();
        $cycle = app(CreateMonthlyCycleAction::class)->handle($unrelated, new CyclePeriod(2026, 9));

        $this->actingAs($executive);

        $this->get(ProjectResource::getUrl('monthly-cycles', ['record' => $unrelated]))->assertNotFound();

        try {
            Livewire::test(ProjectMonthlyCycles::class, ['record' => $unrelated->getRouteKey()]);
            $this->fail('Expected the project to be outside the scoped resource query.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertFalse($executive->can('view', $cycle));
        $this->assertFalse($executive->can('view', $unrelated));
    }

    public function test_guests_and_inactive_users_are_blocked(): void
    {
        $project = Project::factory()->create();

        $this->get(ProjectResource::getUrl('monthly-cycles', ['record' => $project]))
            ->assertRedirect(Filament::getLoginUrl());

        $this->actingAs(User::factory()->seoManager()->inactive()->create())
            ->get(ProjectResource::getUrl('monthly-cycles', ['record' => $project]))
            ->assertForbidden();
    }

    public function test_the_page_displays_the_stored_snapshot_not_current_package_values(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());
        $project = Project::factory()->withPackage($this->growth)->create();
        app(CreateMonthlyCycleAction::class)->handle($project, new CyclePeriod(2026, 9));

        // Change live configuration after the snapshot.
        app(SyncPackageTargetsAction::class)->handle($this->growth, [
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 777],
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 888],
        ]);

        $this->get(ProjectResource::getUrl('monthly-cycles', ['record' => $project]))
            ->assertOk()
            ->assertSee('data-target-key="backlinks">50<', false)
            ->assertSee('data-target-key="blogs">8<', false)
            // Live values must not appear as target cells (bare numbers could match ids in URLs).
            ->assertDontSee('data-target-key="backlinks">777<', false)
            ->assertDontSee('data-target-key="blogs">888<', false);
    }

    public function test_the_month_selector_switches_between_cycles(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());
        $project = Project::factory()->withPackage($this->growth)->create();
        $august = app(CreateMonthlyCycleAction::class)->handle($project, new CyclePeriod(2026, 8));
        $september = app(CreateMonthlyCycleAction::class)->handle($project, new CyclePeriod(2026, 9));

        Livewire::test(ProjectMonthlyCycles::class, ['record' => $project->getRouteKey()])
            ->assertSet('selectedCycleId', $september->id)
            ->assertSee('August 2026')
            ->set('selectedCycleId', $august->id)
            ->assertSee('August 2026');
    }

    public function test_cycles_and_targets_have_no_filament_resource_or_edit_route(): void
    {
        $models = collect(Filament::getPanel('admin')->getResources())
            ->map(fn (string $resource): string => $resource::getModel())
            ->all();

        $this->assertNotContains(MonthlyCycle::class, $models);
        $this->assertNotContains(MonthlyCycleTarget::class, $models);

        $routes = collect(Route::getRoutes()->getRoutes())->map(fn ($route): string => $route->uri());

        $this->assertTrue($routes->filter(fn (string $uri): bool => str_contains($uri, 'monthly-cycle-target'))->isEmpty());
        $this->assertTrue($routes->filter(fn (string $uri): bool => str_contains($uri, 'monthly-cycles/create') || str_contains($uri, 'monthly-cycles/{'))->isEmpty());

        $admin = User::factory()->superAdmin()->create();
        $cycle = MonthlyCycle::factory()->create();

        $this->assertFalse($admin->can('create', MonthlyCycle::class));
        $this->assertFalse($admin->can('update', $cycle));
        $this->assertFalse($admin->can('delete', $cycle));
    }

    public function test_the_project_view_links_to_the_monthly_cycles_page(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());
        $project = Project::factory()->create();

        $this->get(ProjectResource::getUrl('view', ['record' => $project]))
            ->assertOk()
            ->assertSee(ProjectResource::getUrl('monthly-cycles', ['record' => $project]));
    }
}
