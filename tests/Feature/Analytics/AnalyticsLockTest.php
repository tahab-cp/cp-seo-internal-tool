<?php

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\SaveAuthorityMetricsAction;
use App\Actions\Analytics\SaveGa4CountryMetricsAction;
use App\Actions\Analytics\SaveGa4MonthlyMetricsAction;
use App\Actions\Analytics\SaveGscMonthlyMetricsAction;
use App\Actions\Analytics\SaveGscPageMetricsAction;
use App\Actions\Analytics\SaveGscQueryMetricsAction;
use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Enums\MonthlyCycleStatus;
use App\Exceptions\LockedMonthlyCycleException;
use App\Filament\Resources\Projects\Pages\ProjectAnalytics;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class AnalyticsLockTest extends TestCase
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
        $this->project = Project::factory()->create();
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
    }

    /**
     * @return list<callable>
     */
    protected function allWrites(User $user): array
    {
        return [
            fn () => app(SaveGscMonthlyMetricsAction::class)->handle($this->september, ['clicks' => 1, 'impressions' => 2], $user),
            fn () => app(SaveGa4MonthlyMetricsAction::class)->handle($this->september, ['sessions' => 3], $user),
            fn () => app(SaveAuthorityMetricsAction::class)->handle($this->september, ['moz_domain_authority' => 4], $user),
            fn () => app(SaveGscQueryMetricsAction::class)->handle($this->september, [['query' => 'q', 'clicks' => 1, 'impressions' => 1]], $user),
            fn () => app(SaveGscPageMetricsAction::class)->handle($this->september, [['page_url' => 'https://site.example/p', 'clicks' => 1, 'impressions' => 1]], $user),
            fn () => app(SaveGa4CountryMetricsAction::class)->handle($this->september, [['country' => 'Spain']], $user),
        ];
    }

    public function test_open_and_reporting_cycles_accept_creation_and_edits(): void
    {
        foreach ($this->allWrites($this->manager) as $write) {
            $write();
        }

        $this->assertSame(1, $this->september->gscMonthlyMetric()->value('clicks'));

        $this->september->forceFill(['status' => MonthlyCycleStatus::Reporting])->save();
        $this->september->refresh();

        app(SaveGscMonthlyMetricsAction::class)->handle($this->september, ['clicks' => 50, 'impressions' => 60], $this->manager);
        app(SaveGa4MonthlyMetricsAction::class)->handle($this->september, ['sessions' => 70], $this->manager);
        app(SaveAuthorityMetricsAction::class)->handle($this->september, ['moz_domain_authority' => 80], $this->manager);
        app(SaveGscQueryMetricsAction::class)->handle($this->september, [['query' => 'q', 'clicks' => 9, 'impressions' => 9], ['query' => 'r', 'clicks' => 1, 'impressions' => 1]], $this->manager);
        app(SaveGa4CountryMetricsAction::class)->handle($this->september, [], $this->manager);

        $this->assertSame(50, $this->september->gscMonthlyMetric()->value('clicks'));
        $this->assertSame(70, $this->september->ga4MonthlyMetric()->value('sessions'));
        $this->assertSame(80, $this->september->authorityMetric()->value('moz_domain_authority'));
        $this->assertSame(2, $this->september->gscQueryMetrics()->count());
        $this->assertSame(0, $this->september->ga4CountryMetrics()->count());
        $this->assertTrue($this->manager->can('manageAnalytics', $this->september));
    }

    public function test_locked_cycles_reject_every_analytics_write_for_everyone(): void
    {
        foreach ($this->allWrites($this->manager) as $write) {
            $write();
        }

        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now(), 'locked_by' => $this->manager->id])->save();
        $this->september->refresh();

        $admin = User::factory()->superAdmin()->create();
        $executive = User::factory()->seoExecutive()->create();
        $this->project->teamMembers()->attach($executive);

        foreach ([$admin, $this->manager, $executive] as $user) {
            $this->assertFalse($user->can('manageAnalytics', $this->september));
            $this->assertTrue($user->can('view', $this->september));

            foreach ($this->allWrites($user) as $write) {
                try {
                    $write();
                    $this->fail('Expected LockedMonthlyCycleException.');
                } catch (LockedMonthlyCycleException) {
                    $this->addToAssertionCount(1);
                }
            }
        }

        // Nothing changed, including the detail sets (no adds, no removals).
        $this->assertSame(1, $this->september->gscMonthlyMetric()->value('clicks'));
        $this->assertSame(3, $this->september->ga4MonthlyMetric()->value('sessions'));
        $this->assertSame(4, $this->september->authorityMetric()->value('moz_domain_authority'));
        $this->assertSame(1, $this->september->gscQueryMetrics()->count());
        $this->assertSame(1, $this->september->gscPageMetrics()->count());
        $this->assertSame(1, $this->september->ga4CountryMetrics()->count());
        $this->assertTrue($this->september->gscMonthlyMetric->isLocked());
        $this->assertTrue($this->september->gscQueryMetrics->first()->isLocked());

        // Removing rows is a write too.
        try {
            app(SaveGscQueryMetricsAction::class)->handle($this->september, [], $admin);
            $this->fail('Expected LockedMonthlyCycleException.');
        } catch (LockedMonthlyCycleException) {
            $this->assertSame(1, $this->september->gscQueryMetrics()->count());
        }
    }

    public function test_locked_month_is_read_only_in_the_ui_while_other_months_stay_editable(): void
    {
        app(SaveGscMonthlyMetricsAction::class)->handle($this->september, ['clicks' => 42, 'impressions' => 420], $this->manager);
        $october = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 10));
        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();

        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(ProjectAnalytics::class, ['record' => $this->project->getRouteKey()])
            ->set('selectedCycle', (string) $this->september->id)
            ->assertSee('Locked')
            ->assertSee('data-gsc-clicks="42"', false)
            ->assertActionHidden('editGscSummary')
            ->assertActionHidden('editGscQueries')
            ->assertActionHidden('editGscPages')
            ->assertActionHidden('editGa4Summary')
            ->assertActionHidden('editGa4Countries')
            ->assertActionHidden('editAuthority');

        $this->assertFalse(User::query()->latest('id')->first()->can('manageAnalytics', $this->september->fresh()));
        $this->assertSame(42, $this->september->gscMonthlyMetric()->value('clicks'));
        $this->assertSame(0, $this->september->gscQueryMetrics()->count());

        Livewire::test(ProjectAnalytics::class, ['record' => $this->project->getRouteKey()])
            ->set('selectedCycle', (string) $october->id)
            ->assertActionVisible('editGscSummary')
            ->callAction('editGscSummary', data: ['clicks' => 7, 'impressions' => 70])
            ->assertNotified('Search Console summary saved');

        $this->assertSame(7, $october->gscMonthlyMetric()->value('clicks'));
        $this->assertSame(42, $this->september->gscMonthlyMetric()->value('clicks'));
    }
}
