<?php

namespace Tests\Feature\Analytics;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Filament\Resources\Projects\Pages\ProjectAnalytics;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\GscMonthlyMetric;
use App\Models\MonthlyCycle;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class AnalyticsAccessTest extends TestCase
{
    use RefreshDatabase;

    protected User $executive;

    protected Project $assigned;

    protected Project $unrelated;

    protected MonthlyCycle $assignedCycle;

    protected MonthlyCycle $unrelatedCycle;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->executive = User::factory()->seoExecutive()->create();
        $this->assigned = Project::factory()->ownedBy($this->executive)->create();
        $this->unrelated = Project::factory()->create();
        $this->assignedCycle = app(CreateMonthlyCycleAction::class)->handle($this->assigned, new CyclePeriod(2026, 9));
        $this->unrelatedCycle = app(CreateMonthlyCycleAction::class)->handle($this->unrelated, new CyclePeriod(2026, 9));

        GscMonthlyMetric::factory()->forCycle($this->assignedCycle)->create(['clicks' => 111]);
        GscMonthlyMetric::factory()->forCycle($this->unrelatedCycle)->create(['clicks' => 999]);
    }

    public function test_super_admin_and_seo_manager_manage_analytics_on_any_project(): void
    {
        foreach ([
            User::factory()->superAdmin()->create(),
            User::factory()->seoManager()->create(),
        ] as $user) {
            $this->actingAs($user);

            $this->get(ProjectResource::getUrl('analytics', ['record' => $this->unrelated]))
                ->assertOk()
                ->assertSee('data-gsc-clicks="'.$this->unrelatedCycle->gscMonthlyMetric()->value('clicks').'"', false);

            Livewire::test(ProjectAnalytics::class, ['record' => $this->unrelated->getRouteKey()])
                ->assertSet('selectedCycle', (string) $this->unrelatedCycle->id)
                ->assertActionVisible('editGscSummary')
                ->assertActionVisible('editAuthority')
                ->callAction('editGscSummary', data: ['clicks' => 500 + $user->id, 'impressions' => 9000, 'ctr' => 5.5, 'average_position' => 9.9])
                ->assertHasNoFormErrors()
                ->assertNotified('Search Console summary saved')
                ->callAction('editAuthority', data: ['moz_domain_authority' => 50, 'ahrefs_domain_rating' => 55.5])
                ->assertNotified('Site authority saved');

            $this->assertSame(500 + $user->id, $this->unrelatedCycle->gscMonthlyMetric()->value('clicks'));
            $this->assertSame($user->id, $this->unrelatedCycle->gscMonthlyMetric()->value('entered_by'));
            $this->assertTrue($user->can('manageAnalytics', $this->unrelated));
            $this->assertTrue($user->can('manageAnalytics', $this->unrelatedCycle));
        }

        $this->assertSame(1, $this->unrelatedCycle->authorityMetric()->count());
    }

    public function test_executive_manages_analytics_for_an_assigned_project(): void
    {
        $this->actingAs($this->executive);

        $this->get(ProjectResource::getUrl('analytics', ['record' => $this->assigned]))
            ->assertOk()
            ->assertSee('data-gsc-clicks="111"', false);

        Livewire::test(ProjectAnalytics::class, ['record' => $this->assigned->getRouteKey()])
            ->callAction('editGa4Summary', data: ['active_users' => 800, 'sessions' => 1200, 'engagement_rate' => 60])
            ->assertHasNoFormErrors()
            ->assertNotified('Google Analytics summary saved')
            ->callAction('editGa4Countries', data: ['rows' => [
                ['country' => 'Spain', 'active_users' => 300],
                ['country' => 'Portugal', 'active_users' => 120],
            ]])
            ->assertHasNoFormErrors()
            ->assertNotified('Audience by country saved');

        $this->assertSame(800, $this->assignedCycle->ga4MonthlyMetric()->value('active_users'));
        $this->assertSame($this->executive->id, $this->assignedCycle->ga4MonthlyMetric()->value('entered_by'));
        $this->assertSame(2, $this->assignedCycle->ga4CountryMetrics()->count());
        $this->assertTrue($this->executive->can('manageAnalytics', $this->assignedCycle));
        $this->assertSame(1, GscMonthlyMetric::query()->accessibleBy($this->executive)->count());
    }

    public function test_executive_cannot_access_analytics_of_an_unrelated_project(): void
    {
        $this->actingAs($this->executive);

        $this->get(ProjectResource::getUrl('analytics', ['record' => $this->unrelated]))->assertNotFound();

        try {
            Livewire::test(ProjectAnalytics::class, ['record' => $this->unrelated->getRouteKey()]);
            $this->fail('Expected the project to be outside the scoped resource query.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertFalse($this->executive->can('manageAnalytics', $this->unrelated));
        $this->assertFalse($this->executive->can('manageAnalytics', $this->unrelatedCycle));
        $this->assertFalse($this->executive->can('view', $this->unrelatedCycle));
        $this->assertFalse(GscMonthlyMetric::query()->accessibleBy($this->executive)->forCycle($this->unrelatedCycle)->exists());
    }

    public function test_crafted_cycle_ids_and_cross_project_pages_cannot_bypass_authorization(): void
    {
        $foreignPage = Page::factory()->forProject($this->unrelated)->create();

        $this->actingAs($this->executive);

        // Binding another project's cycle id to the page property resolves to nothing:
        // no data is shown and every edit action disappears.
        $component = Livewire::test(ProjectAnalytics::class, ['record' => $this->assigned->getRouteKey()])
            ->set('selectedCycle', (string) $this->unrelatedCycle->id)
            ->assertDontSee('data-gsc-clicks="999"', false)
            ->assertDontSee('data-selected-cycle="'.$this->unrelatedCycle->id.'"', false);

        foreach (['editGscSummary', 'editGscQueries', 'editGscPages', 'editGa4Summary', 'editGa4Countries', 'editAuthority'] as $action) {
            $component->assertActionHidden($action);
        }

        $this->assertNull($component->instance()->getSelectedCycle());
        $this->assertSame(999, $this->unrelatedCycle->gscMonthlyMetric()->value('clicks'));

        // A page from another project cannot be mapped, even with a crafted id.
        Livewire::test(ProjectAnalytics::class, ['record' => $this->assigned->getRouteKey()])
            ->callAction('editGscPages', data: ['rows' => [
                ['page_url' => 'https://leak.example/a', 'page_id' => $foreignPage->id, 'clicks' => 1, 'impressions' => 1],
            ]])
            ->assertHasFormErrors(['rows.0.page_id']);

        $this->assertSame(0, $this->assignedCycle->gscPageMetrics()->count());
        $this->assertSame(0, $this->unrelatedCycle->gscPageMetrics()->count());
    }

    public function test_guests_inactive_users_and_the_sidebar_are_handled(): void
    {
        $this->get(ProjectResource::getUrl('analytics', ['record' => $this->assigned]))
            ->assertRedirect(Filament::getLoginUrl());

        $inactive = User::factory()->seoManager()->inactive()->create();

        $this->actingAs($inactive)
            ->get(ProjectResource::getUrl('analytics', ['record' => $this->assigned]))
            ->assertForbidden();

        $this->assertSame(0, GscMonthlyMetric::query()->accessibleBy($inactive)->count());
        $this->assertSame(0, GscMonthlyMetric::query()->accessibleBy(null)->count());

        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin);

        $labels = collect(Filament::getPanel('admin')->getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->map(fn ($item) => $item->getLabel())
            ->all();

        foreach (['Analytics', 'GSC', 'GA4', 'Google Search Console', 'Google Analytics', 'Authority'] as $label) {
            $this->assertNotContains($label, $labels);
        }

        $this->get('/admin')->assertOk()->assertDontSee('/admin/analytics');
        $this->get(ProjectResource::getUrl('view', ['record' => $this->assigned]))
            ->assertOk()
            ->assertSee(ProjectResource::getUrl('analytics', ['record' => $this->assigned]));
    }
}
