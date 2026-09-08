<?php

namespace Tests\Feature\Keywords;

use App\Actions\Keywords\UpdateKeywordAction;
use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Rankings\RecordRankingSnapshotAction;
use App\Actions\Rankings\UpdateRankingSnapshotAction;
use App\Enums\MonthlyCycleStatus;
use App\Enums\RankingSource;
use App\Exceptions\LockedMonthlyCycleException;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\Page;
use App\Models\Project;
use App\Models\RankingSnapshot;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class RankingSnapshotActionsTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected Keyword $keyword;

    protected MonthlyCycle $september;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->project = Project::factory()->create(['name' => 'Casa Botanica']);
        $this->keyword = Keyword::factory()->forProject($this->project)->create(['keyword' => 'luxury villas london']);
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function record(array $overrides = []): RankingSnapshot
    {
        return app(RecordRankingSnapshotAction::class)->handle($this->project, $overrides + [
            'keyword_id' => $this->keyword->id,
            'monthly_cycle_id' => $this->september->id,
            'checked_at' => '2026-09-08 09:00:00',
            'position' => 19,
        ]);
    }

    public function test_a_snapshot_belongs_to_its_keyword_and_cycle_with_manual_as_the_default_source(): void
    {
        $snapshot = $this->record();

        $this->assertTrue($snapshot->keyword->is($this->keyword));
        $this->assertTrue($snapshot->monthlyCycle->is($this->september));
        $this->assertSame(RankingSource::Manual, $snapshot->source);
        $this->assertSame(19, $snapshot->position);
        $this->assertNull($snapshot->ranking_url);
        $this->assertNotNull($snapshot->created_at);
        $this->assertTrue($this->keyword->rankingSnapshots->contains($snapshot));
        $this->assertTrue($this->september->rankingSnapshots->contains($snapshot));
    }

    public function test_keyword_and_cycle_must_belong_to_the_same_project(): void
    {
        $foreignKeyword = Keyword::factory()->create();
        $foreignCycle = MonthlyCycle::factory()->create();

        foreach ([
            ['keyword_id' => $foreignKeyword->id],
            ['monthly_cycle_id' => $foreignCycle->id],
        ] as $attributes) {
            try {
                $this->record($attributes);
                $this->fail('Expected InvalidArgumentException.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('does not belong to project "Casa Botanica"', $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('ranking_snapshots', 0);

        $snapshot = $this->record();

        $this->expectException(InvalidArgumentException::class);
        app(UpdateRankingSnapshotAction::class)->handle($snapshot, ['monthly_cycle_id' => $foreignCycle->id]);
    }

    public function test_position_rules(): void
    {
        $this->assertSame(1, $this->record(['position' => 1, 'checked_at' => '2026-09-01 09:00'])->position);
        $this->assertSame(250, $this->record(['position' => '250', 'checked_at' => '2026-09-02 09:00'])->position);

        foreach ([null, '', 'Not Ranking', 'not ranking'] as $notRanking) {
            $snapshot = $this->record(['position' => $notRanking, 'checked_at' => '2026-09-03 09:00']);

            $this->assertNull($snapshot->fresh()->position);
            $this->assertFalse($snapshot->isRanking());
            $this->assertSame('Not Ranking', $snapshot->positionLabel());
        }

        foreach ([0, '0', -3, 2.5, 'first'] as $invalid) {
            try {
                $this->record(['position' => $invalid, 'checked_at' => '2026-09-04 09:00']);
                $this->fail("Expected position [{$invalid}] to be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, RankingSnapshot::query()->where('position', 0)->count());
    }

    public function test_multiple_snapshots_at_different_times_and_sources_are_allowed(): void
    {
        $this->record(['checked_at' => '2026-09-01 09:00', 'position' => 24]);
        $this->record(['checked_at' => '2026-09-08 09:00', 'position' => 19]);
        $this->record(['checked_at' => '2026-09-15 09:00', 'position' => 12]);
        $this->record(['checked_at' => '2026-09-15 09:00', 'position' => 11, 'source' => 'semrush']);
        $this->record(['checked_at' => '2026-09-22 09:00', 'position' => 8, 'source' => RankingSource::CsvImport]);

        $this->assertSame(5, $this->keyword->rankingSnapshots()->count());
        $this->assertSame([8, 11, 12, 19, 24], $this->keyword->rankingSnapshots->pluck('position')->all());
        $this->assertSame(RankingSource::CsvImport, $this->keyword->rankingSnapshots->first()->source);
    }

    public function test_recording_the_same_keyword_moment_and_source_updates_that_observation(): void
    {
        $first = $this->record(['position' => 19, 'ranking_url' => 'https://casa.example/villas']);
        $second = $this->record(['position' => 17, 'ranking_url' => null]);

        $this->assertSame($first->id, $second->id);
        $this->assertFalse($second->wasRecentlyCreated);
        $this->assertSame(17, $first->fresh()->position);
        $this->assertNull($first->fresh()->ranking_url);
        $this->assertSame(1, $this->keyword->rankingSnapshots()->count());

        // A different source at the same moment is a separate observation.
        $this->record(['position' => 18, 'source' => 'ahrefs']);
        $this->assertSame(2, $this->keyword->rankingSnapshots()->count());

        // Correcting one onto another observation's identity is refused.
        $this->expectException(InvalidArgumentException::class);
        app(UpdateRankingSnapshotAction::class)->handle($first->fresh(), ['source' => 'ahrefs']);
    }

    public function test_unknown_sources_are_rejected_and_reserved_ones_are_representable(): void
    {
        foreach (RankingSource::cases() as $index => $source) {
            $snapshot = $this->record(['source' => $source->value, 'checked_at' => '2026-09-0'.($index + 1).' 09:00']);
            $this->assertSame($source, $snapshot->fresh()->source);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->record(['source' => 'google_sheets']);
    }

    public function test_ranking_url_is_optional_and_may_differ_from_the_target_page(): void
    {
        $page = Page::factory()->forProject($this->project)->create(['url' => 'https://casa.example/villas']);
        app(UpdateKeywordAction::class)->handle($this->keyword, ['target_page_id' => $page->id]);

        $snapshot = $this->record(['ranking_url' => 'https://casa.example/blog/villas-guide']);

        $this->assertSame('https://casa.example/blog/villas-guide', $snapshot->ranking_url);
        $this->assertNotSame($page->url, $snapshot->ranking_url);

        $this->expectException(InvalidArgumentException::class);
        $this->record(['ranking_url' => 'not a url', 'checked_at' => '2026-09-09 09:00']);
    }

    public function test_open_and_reporting_cycles_accept_and_allow_correcting_snapshots(): void
    {
        $open = $this->record(['checked_at' => '2026-09-01 09:00']);
        $this->assertTrue($open->exists);

        $this->september->forceFill(['status' => MonthlyCycleStatus::Reporting])->save();

        $reporting = $this->record(['checked_at' => '2026-09-08 09:00', 'position' => 30]);
        app(UpdateRankingSnapshotAction::class)->handle($reporting, ['position' => 21, 'ranking_url' => 'https://casa.example/x']);

        $this->assertSame(21, $reporting->fresh()->position);
        $this->assertTrue(User::factory()->seoManager()->create()->can('update', $reporting->fresh()));
    }

    public function test_locked_cycles_refuse_new_and_edited_snapshots_for_everyone(): void
    {
        $existing = $this->record(['checked_at' => '2026-09-01 09:00', 'position' => 24]);
        $october = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 10));

        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();
        $existing->refresh();

        foreach ([User::factory()->superAdmin()->create(), User::factory()->seoManager()->create()] as $user) {
            $this->assertFalse($user->can('update', $existing));
            $this->assertTrue($user->can('view', $existing));
        }

        $attempts = [
            fn () => $this->record(['checked_at' => '2026-09-20 09:00', 'position' => 5]),
            // Same identity as the existing (locked) observation: the upsert must refuse too.
            fn () => $this->record(['checked_at' => '2026-09-01 09:00', 'position' => 5, 'monthly_cycle_id' => $october->id]),
            fn () => app(UpdateRankingSnapshotAction::class)->handle($existing, ['position' => 1]),
            fn () => app(UpdateRankingSnapshotAction::class)->handle($existing, ['ranking_url' => 'https://x.example']),
            fn () => app(UpdateRankingSnapshotAction::class)->handle($existing, ['monthly_cycle_id' => $october->id]),
            fn () => app(UpdateRankingSnapshotAction::class)->handle($existing, ['checked_at' => '2026-09-02 09:00']),
            // Nor may an open observation be moved into the locked month.
            fn () => app(UpdateRankingSnapshotAction::class)->handle(
                $this->record(['checked_at' => '2026-10-02 09:00', 'monthly_cycle_id' => $october->id]),
                ['monthly_cycle_id' => $this->september->id],
            ),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('Expected LockedMonthlyCycleException.');
            } catch (LockedMonthlyCycleException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(24, $existing->fresh()->position);
        $this->assertSame($this->september->id, $existing->fresh()->monthly_cycle_id);
        $this->assertSame(1, $this->september->rankingSnapshots()->count());
    }

    public function test_keyword_master_data_stays_editable_when_historical_cycles_are_locked(): void
    {
        $this->record();
        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();

        $this->assertTrue(User::factory()->seoExecutive()->create()->can('viewAny', Keyword::class));

        app(UpdateKeywordAction::class)->handle($this->keyword, [
            'search_volume' => 900,
            'keyword_difficulty' => 55,
            'search_intent' => 'transactional',
            'is_branded' => true,
            'status' => 'paused',
            'target_page_id' => Page::factory()->forProject($this->project)->create()->id,
        ]);

        $fresh = $this->keyword->fresh();

        $this->assertSame(900, $fresh->search_volume);
        $this->assertSame(55, $fresh->keyword_difficulty);
        $this->assertTrue($fresh->is_branded);
        $this->assertSame('paused', $fresh->status->value);
        $this->assertNotNull($fresh->target_page_id);
    }
}
