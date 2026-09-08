<?php

namespace Tests\Feature\Keywords;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Rankings\RecordRankingSnapshotsAction;
use App\Enums\KeywordStatus;
use App\Enums\MonthlyCycleStatus;
use App\Enums\RankingSource;
use App\Exceptions\LockedMonthlyCycleException;
use App\Filament\Resources\Projects\Pages\ProjectKeywords;
use App\Filament\Resources\Projects\Schemas\RankingForms;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\RankingSnapshot;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class BulkRankingEntryTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected MonthlyCycle $september;

    protected Keyword $alpha;

    protected Keyword $beta;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->project = Project::factory()->create(['name' => 'Casa Botanica']);
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
        $this->alpha = Keyword::factory()->forProject($this->project)->create(['keyword' => 'alpha villas']);
        $this->beta = Keyword::factory()->forProject($this->project)->create(['keyword' => 'beta villas']);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $context
     */
    protected function bulk(array $rows, array $context = [])
    {
        return app(RecordRankingSnapshotsAction::class)->handle($this->project, $context + [
            'monthly_cycle_id' => $this->september->id,
            'checked_at' => '2026-09-15 09:00:00',
            'source' => 'manual',
        ], $rows);
    }

    public function test_bulk_entry_creates_snapshots_for_multiple_keywords_and_maps_not_ranking_to_null(): void
    {
        $snapshots = $this->bulk([
            ['keyword_id' => $this->alpha->id, 'position' => 12, 'ranking_url' => 'https://casa.example/alpha'],
            ['keyword_id' => $this->beta->id, 'position' => ''],
        ]);

        $this->assertCount(2, $snapshots);
        $this->assertSame(12, $this->alpha->rankingSnapshots()->first()->position);
        $this->assertNull($this->beta->rankingSnapshots()->first()->position);
        $this->assertSame(0, RankingSnapshot::query()->where('position', 0)->count());
        $this->assertTrue($snapshots->every(fn (RankingSnapshot $s): bool => $s->monthly_cycle_id === $this->september->id
            && $s->source === RankingSource::Manual
            && $s->checked_at->toDateTimeString() === '2026-09-15 09:00:00'));

        // Re-submitting the same moment updates rather than duplicates.
        $this->bulk([
            ['keyword_id' => $this->alpha->id, 'position' => 10],
            ['keyword_id' => $this->beta->id, 'position' => 30],
        ]);

        $this->assertSame(2, RankingSnapshot::query()->count());
        $this->assertSame(10, $this->alpha->rankingSnapshots()->first()->position);
        $this->assertSame(30, $this->beta->rankingSnapshots()->first()->position);
    }

    public function test_bulk_entry_is_atomic_and_rejects_foreign_keywords_cycles_and_bad_positions(): void
    {
        $foreignKeyword = Keyword::factory()->create();
        $foreignCycle = MonthlyCycle::factory()->create();

        $attempts = [
            [[['keyword_id' => $this->alpha->id, 'position' => 5], ['keyword_id' => $foreignKeyword->id, 'position' => 9]], []],
            [[['keyword_id' => $this->alpha->id, 'position' => 5]], ['monthly_cycle_id' => $foreignCycle->id]],
            [[['keyword_id' => $this->alpha->id, 'position' => 5], ['keyword_id' => $this->beta->id, 'position' => 0]], []],
            [[['keyword_id' => $this->alpha->id, 'position' => 5], ['keyword_id' => $this->beta->id, 'position' => -2]], []],
            [[['keyword_id' => $this->alpha->id, 'position' => 5], ['keyword_id' => $this->alpha->id, 'position' => 6]], []],
            [[['keyword_id' => $this->alpha->id, 'position' => 5]], ['source' => 'magic']],
            [[['keyword_id' => $this->alpha->id, 'position' => 5]], ['checked_at' => null]],
        ];

        foreach ($attempts as [$rows, $context]) {
            try {
                $this->bulk($rows, $context);
                $this->fail('Expected InvalidArgumentException for '.json_encode([$rows, $context]));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }

            // Nothing from the batch survived.
            $this->assertSame(0, RankingSnapshot::query()->count());
        }
    }

    public function test_bulk_entry_rejects_a_locked_cycle(): void
    {
        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();

        try {
            $this->bulk([['keyword_id' => $this->alpha->id, 'position' => 5]]);
            $this->fail('Expected LockedMonthlyCycleException.');
        } catch (LockedMonthlyCycleException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, RankingSnapshot::query()->count());
    }

    public function test_bulk_rows_list_active_keywords_with_derived_read_only_previous(): void
    {
        RankingSnapshot::factory()->forKeyword($this->alpha)->forCycle($this->september)->at('2026-09-08 09:00', 19)->create();
        Keyword::factory()->forProject($this->project)->status(KeywordStatus::Archived)->create(['keyword' => 'zzz archived']);
        Keyword::factory()->forProject($this->project)->status(KeywordStatus::Paused)->create(['keyword' => 'zzz paused']);

        $rows = RankingForms::bulkRows($this->project);

        $this->assertSame([$this->alpha->id, $this->beta->id], array_column($rows, 'keyword_id'));
        $this->assertSame('19 · 8 Sep', $rows[0]['previous']);
        $this->assertSame('—', $rows[1]['previous']);
        $this->assertNull($rows[0]['position']);

        // "Previous" is a derived display value; the batch ignores anything sent for it.
        $this->bulk([
            ['keyword_id' => $this->alpha->id, 'position' => 15, 'previous' => '1'],
            ['keyword_id' => $this->beta->id, 'position' => 7, 'previous' => '1'],
        ]);

        $this->assertSame([15, 19], $this->alpha->rankingSnapshots()->pluck('position')->all());
        $this->assertSame(0, RankingSnapshot::query()->where('position', 1)->count());
    }

    public function test_update_rankings_from_the_keywords_page(): void
    {
        $manager = User::factory()->seoManager()->create();
        $this->actingAs($manager);
        RankingSnapshot::factory()->forKeyword($this->alpha)->forCycle($this->september)->at('2026-09-08 09:00', 19)->create();

        $page = Livewire::test(ProjectKeywords::class, ['record' => $this->project->getRouteKey()])
            ->assertActionVisible('updateRankings')
            ->mountAction('updateRankings');

        // Rows are prefilled with active keywords and their derived previous.
        $rows = array_values($page->instance()->mountedActions[0]['data']['rows'] ?? []);
        $this->assertSame([$this->alpha->id, $this->beta->id], array_map(fn (array $row): int => (int) $row['keyword_id'], $rows));
        $this->assertSame('19 · 8 Sep', $rows[0]['previous']);

        $keys = array_keys($page->instance()->mountedActions[0]['data']['rows']);

        $page->setActionData([
            'monthly_cycle_id' => $this->september->id,
            'checked_at' => '2026-09-15 09:00',
            'source' => 'manual',
            'rows' => [
                $keys[0] => ['keyword_id' => $this->alpha->id, 'position' => 12, 'ranking_url' => 'https://casa.example/alpha'],
                $keys[1] => ['keyword_id' => $this->beta->id, 'position' => null, 'ranking_url' => null],
            ],
        ])
            ->callMountedAction()
            ->assertHasNoFormErrors()
            ->assertNotified('2 ranking observation(s) recorded');

        $this->assertSame([12, 19], $this->alpha->rankingSnapshots()->pluck('position')->all());
        $this->assertNull($this->beta->rankingSnapshots()->first()->position);
        $this->assertSame(3, RankingSnapshot::query()->count());
    }

    public function test_update_rankings_form_rejects_a_locked_month_and_position_zero(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $october = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 10));
        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();

        $page = Livewire::test(ProjectKeywords::class, ['record' => $this->project->getRouteKey()])->mountAction('updateRankings');
        $keys = array_keys($page->instance()->mountedActions[0]['data']['rows']);

        $page->setActionData([
            'monthly_cycle_id' => $this->september->id,
            'checked_at' => '2026-09-15 09:00',
            'source' => 'manual',
            'rows' => [
                $keys[0] => ['keyword_id' => $this->alpha->id, 'position' => 0],
                $keys[1] => ['keyword_id' => $this->beta->id, 'position' => 3],
            ],
        ])
            ->callMountedAction()
            ->assertHasFormErrors(['monthly_cycle_id', "rows.{$keys[0]}.position"]);

        $this->assertSame(0, RankingSnapshot::query()->count());

        // October is open: the same batch lands, with alpha as "not ranking".
        $page = Livewire::test(ProjectKeywords::class, ['record' => $this->project->getRouteKey()])->mountAction('updateRankings');
        $keys = array_keys($page->instance()->mountedActions[0]['data']['rows']);

        $page->setActionData([
            'monthly_cycle_id' => $october->id,
            'checked_at' => '2026-10-02 09:00',
            'source' => 'manual',
            'rows' => [
                $keys[0] => ['keyword_id' => $this->alpha->id, 'position' => null],
                $keys[1] => ['keyword_id' => $this->beta->id, 'position' => 3],
            ],
        ])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertSame(2, $october->rankingSnapshots()->count());
    }
}
