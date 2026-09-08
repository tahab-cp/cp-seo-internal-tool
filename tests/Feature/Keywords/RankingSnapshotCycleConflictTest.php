<?php

namespace Tests\Feature\Keywords;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Rankings\RecordRankingSnapshotAction;
use App\Actions\Rankings\RecordRankingSnapshotsAction;
use App\Actions\Rankings\UpdateRankingSnapshotAction;
use App\Enums\MonthlyCycleStatus;
use App\Exceptions\LockedMonthlyCycleException;
use App\Exceptions\RankingSnapshotCycleConflictException;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\RankingSnapshot;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Historical-integrity invariant: the duplicate-upsert path may correct
 * position and URL of an existing observation, but never silently moves it
 * to another reporting month.
 */
class RankingSnapshotCycleConflictTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected Keyword $alpha;

    protected Keyword $beta;

    protected MonthlyCycle $september;

    protected MonthlyCycle $october;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05 10:00:00');

        $this->project = Project::factory()->create();
        $this->alpha = Keyword::factory()->forProject($this->project)->create(['keyword' => 'alpha']);
        $this->beta = Keyword::factory()->forProject($this->project)->create(['keyword' => 'beta']);
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
        $this->october = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 10));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function record(array $overrides = []): RankingSnapshot
    {
        return app(RecordRankingSnapshotAction::class)->handle($this->project, $overrides + [
            'keyword_id' => $this->alpha->id,
            'monthly_cycle_id' => $this->september->id,
            'checked_at' => '2026-09-30 09:00:00',
            'source' => 'manual',
            'position' => 19,
        ]);
    }

    public function test_same_identity_and_same_cycle_updates_position_and_url_deterministically(): void
    {
        $first = $this->record(['position' => 19, 'ranking_url' => 'https://a.example/1']);
        $second = $this->record(['position' => 12, 'ranking_url' => 'https://a.example/2']);
        $third = $this->record(['position' => null, 'ranking_url' => null]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->id, $third->id);
        $this->assertSame(1, RankingSnapshot::query()->count());

        $fresh = $first->fresh();
        $this->assertNull($fresh->position);
        $this->assertNull($fresh->ranking_url);
        $this->assertSame($this->september->id, $fresh->monthly_cycle_id);
    }

    public function test_same_identity_against_a_different_cycle_is_rejected_and_the_original_stays_put(): void
    {
        $original = $this->record(['position' => 19, 'ranking_url' => 'https://a.example/1']);

        try {
            $this->record(['monthly_cycle_id' => $this->october->id, 'position' => 3, 'ranking_url' => 'https://a.example/x']);
            $this->fail('Expected RankingSnapshotCycleConflictException.');
        } catch (RankingSnapshotCycleConflictException $exception) {
            $this->assertInstanceOf(InvalidArgumentException::class, $exception);
            $this->assertStringContainsString('already exists in September 2026', $exception->getMessage());
            $this->assertStringContainsString('October 2026', $exception->getMessage());
        }

        $fresh = $original->fresh();

        $this->assertSame($this->september->id, $fresh->monthly_cycle_id);
        $this->assertSame(19, $fresh->position);
        $this->assertSame('https://a.example/1', $fresh->ranking_url);
        $this->assertSame(1, RankingSnapshot::query()->count());
        $this->assertSame(0, $this->october->rankingSnapshots()->count());
    }

    public function test_bulk_entry_is_atomic_when_the_conflict_occurs(): void
    {
        $original = $this->record(['position' => 19]);

        try {
            app(RecordRankingSnapshotsAction::class)->handle($this->project, [
                'monthly_cycle_id' => $this->october->id,
                'checked_at' => '2026-09-30 09:00:00',
                'source' => 'manual',
            ], [
                ['keyword_id' => $this->beta->id, 'position' => 5],
                ['keyword_id' => $this->alpha->id, 'position' => 3],
            ]);
            $this->fail('Expected RankingSnapshotCycleConflictException.');
        } catch (RankingSnapshotCycleConflictException) {
            $this->addToAssertionCount(1);
        }

        // Neither the conflicting row nor the valid beta row survived.
        $this->assertSame(1, RankingSnapshot::query()->count());
        $this->assertSame(0, $this->beta->rankingSnapshots()->count());
        $this->assertSame($this->september->id, $original->fresh()->monthly_cycle_id);
        $this->assertSame(19, $original->fresh()->position);

        // The same batch against the observation's own month updates it and adds beta.
        $snapshots = app(RecordRankingSnapshotsAction::class)->handle($this->project, [
            'monthly_cycle_id' => $this->september->id,
            'checked_at' => '2026-09-30 09:00:00',
            'source' => 'manual',
        ], [
            ['keyword_id' => $this->beta->id, 'position' => 5],
            ['keyword_id' => $this->alpha->id, 'position' => 3],
        ]);

        $this->assertCount(2, $snapshots);
        $this->assertSame(2, RankingSnapshot::query()->count());
        $this->assertSame(3, $original->fresh()->position);
    }

    public function test_moving_between_months_is_only_possible_through_the_deliberate_correction_path(): void
    {
        $original = $this->record(['position' => 19]);

        app(UpdateRankingSnapshotAction::class)->handle($original, ['monthly_cycle_id' => $this->october->id]);

        $this->assertSame($this->october->id, $original->fresh()->monthly_cycle_id);

        // A foreign project's cycle is refused, as is a uniqueness collision.
        $foreignCycle = MonthlyCycle::factory()->create();

        try {
            app(UpdateRankingSnapshotAction::class)->handle($original, ['monthly_cycle_id' => $foreignCycle->id]);
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $other = $this->record(['checked_at' => '2026-09-01 09:00:00', 'position' => 30]);

        try {
            app(UpdateRankingSnapshotAction::class)->handle($other, ['checked_at' => '2026-09-30 09:00:00']);
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('2026-09-01 09:00:00', $other->fresh()->checked_at->toDateTimeString());
    }

    public function test_locked_cycle_behaviour_is_unchanged(): void
    {
        $original = $this->record(['position' => 19]);
        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();

        // Re-recording a locked observation is refused regardless of the cycle sent.
        foreach ([$this->september->id, $this->october->id] as $cycleId) {
            try {
                $this->record(['monthly_cycle_id' => $cycleId, 'position' => 1]);
                $this->fail('Expected LockedMonthlyCycleException.');
            } catch (LockedMonthlyCycleException) {
                $this->addToAssertionCount(1);
            }
        }

        // The deliberate path refuses a locked source and a locked destination.
        try {
            app(UpdateRankingSnapshotAction::class)->handle($original->fresh(), ['monthly_cycle_id' => $this->october->id]);
            $this->fail('Expected LockedMonthlyCycleException.');
        } catch (LockedMonthlyCycleException) {
            $this->addToAssertionCount(1);
        }

        $openOctober = $this->record(['monthly_cycle_id' => $this->october->id, 'checked_at' => '2026-10-02 09:00:00', 'position' => 8]);

        try {
            app(UpdateRankingSnapshotAction::class)->handle($openOctober, ['monthly_cycle_id' => $this->september->id]);
            $this->fail('Expected LockedMonthlyCycleException.');
        } catch (LockedMonthlyCycleException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(19, $original->fresh()->position);
        $this->assertSame($this->september->id, $original->fresh()->monthly_cycle_id);
        $this->assertSame($this->october->id, $openOctober->fresh()->monthly_cycle_id);
    }
}
