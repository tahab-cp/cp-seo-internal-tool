<?php

namespace Tests\Feature\Keywords;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Pages\RecordPageOptimizationAction;
use App\Actions\Rankings\RecordRankingSnapshotAction;
use App\Actions\Tasks\CreateTaskAction;
use App\Actions\Tasks\SetTaskStatusAction;
use App\Enums\TaskStatus;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\Page;
use App\Models\Project;
use App\Models\RankingSnapshot;
use App\Models\User;
use App\Services\Rankings\RankingMovementService;
use App\Support\MonthlyCycles\CyclePeriod;
use App\Support\Rankings\RankingMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RankingMovementTest extends TestCase
{
    use RefreshDatabase;

    protected RankingMovementService $movement;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-25 10:00:00');

        $this->movement = app(RankingMovementService::class);
    }

    public function test_pairwise_movement_semantics(): void
    {
        $improved = RankingMovement::between(24, 8);
        $this->assertSame(RankingMovement::IMPROVED, $improved->direction);
        $this->assertSame(16, $improved->absoluteChange);
        $this->assertSame('Improved 16 positions', $improved->label());
        $this->assertSame('24 → 8', $improved->transition());
        $this->assertTrue($improved->isImprovement());

        $declined = RankingMovement::between(8, 14);
        $this->assertSame(RankingMovement::DECLINED, $declined->direction);
        $this->assertSame(6, $declined->absoluteChange);
        $this->assertSame('Declined 6 positions', $declined->label());
        $this->assertTrue($declined->isDecline());

        $this->assertSame('Improved 1 position', RankingMovement::between(2, 1)->label());
        $this->assertSame('Unchanged', RankingMovement::between(5, 5)->label());

        $entered = RankingMovement::between(null, 20);
        $this->assertSame(RankingMovement::ENTERED, $entered->direction);
        $this->assertNull($entered->absoluteChange);
        $this->assertSame('Now ranking at 20', $entered->label());
        $this->assertSame('Not Ranking → 20', $entered->transition());

        $dropped = RankingMovement::between(20, null);
        $this->assertSame(RankingMovement::DROPPED, $dropped->direction);
        $this->assertSame('No longer ranking', $dropped->label());

        $none = RankingMovement::between(null, null);
        $this->assertSame(RankingMovement::NOT_RANKING, $none->direction);
        $this->assertSame('Not ranking', $none->label());
        $this->assertNull($none->absoluteChange);

        $this->assertSame([
            'previous_position' => 24,
            'current_position' => 8,
            'direction' => 'improved',
            'absolute_change' => 16,
            'label' => 'Improved 16 positions',
        ], $improved->toArray());

        // Never a bare signed number.
        $this->assertStringNotContainsString('+', $improved->label());
        $this->assertStringNotContainsString('-', $declined->label());
    }

    public function test_monthly_summary_uses_the_earliest_and_latest_snapshots_of_the_selected_cycle(): void
    {
        $project = Project::factory()->create();
        $keyword = Keyword::factory()->forProject($project)->create();
        $september = app(CreateMonthlyCycleAction::class)->handle($project, new CyclePeriod(2026, 9));
        $august = app(CreateMonthlyCycleAction::class)->handle($project, new CyclePeriod(2026, 8));

        $record = fn (MonthlyCycle $cycle, string $at, ?int $position) => app(RecordRankingSnapshotAction::class)->handle($project, [
            'keyword_id' => $keyword->id,
            'monthly_cycle_id' => $cycle->id,
            'checked_at' => $at,
            'position' => $position,
        ]);

        $record($august, '2026-08-20 09:00', 40);
        $record($september, '2026-09-02 09:00', 24);
        $record($september, '2026-09-10 09:00', 18);
        $record($september, '2026-09-22 09:00', 8);

        $summary = $this->movement->monthlySummary($keyword, $september);

        $this->assertSame(3, $summary->snapshotCount);
        $this->assertSame(24, $summary->monthStartPosition());
        $this->assertSame(8, $summary->latestPosition());
        $this->assertTrue($summary->hasComparison());
        $this->assertSame('Improved 16 positions', $summary->movementLabel());
        $this->assertSame('24', $summary->monthStartLabel());
        $this->assertSame('8', $summary->latestLabel());

        // August is its own story.
        $augustSummary = $this->movement->monthlySummary($keyword, $august);
        $this->assertSame(1, $augustSummary->snapshotCount);
        $this->assertSame(40, $augustSummary->latestPosition());
    }

    public function test_a_single_snapshot_month_does_not_invent_movement(): void
    {
        $project = Project::factory()->create();
        $keyword = Keyword::factory()->forProject($project)->create();
        $september = app(CreateMonthlyCycleAction::class)->handle($project, new CyclePeriod(2026, 9));
        RankingSnapshot::factory()->forKeyword($keyword)->forCycle($september)->at('2026-09-10 09:00', 12)->create();

        $summary = $this->movement->monthlySummary($keyword, $september);

        $this->assertSame(1, $summary->snapshotCount);
        $this->assertFalse($summary->hasComparison());
        $this->assertNull($summary->movement());
        $this->assertSame('12', $summary->latestLabel());
        $this->assertSame('No comparison yet', $summary->movementLabel());

        $empty = $this->movement->monthlySummary($keyword, app(CreateMonthlyCycleAction::class)->handle($project, new CyclePeriod(2026, 10)));
        $this->assertSame(0, $empty->snapshotCount);
        $this->assertSame('—', $empty->latestLabel());
        $this->assertSame('No data', $empty->movementLabel());
    }

    public function test_not_ranking_renders_as_not_ranking_never_zero(): void
    {
        $project = Project::factory()->create();
        $keyword = Keyword::factory()->forProject($project)->create();
        $september = app(CreateMonthlyCycleAction::class)->handle($project, new CyclePeriod(2026, 9));
        RankingSnapshot::factory()->forKeyword($keyword)->forCycle($september)->at('2026-09-02 09:00', null)->create();
        RankingSnapshot::factory()->forKeyword($keyword)->forCycle($september)->at('2026-09-20 09:00', 20)->create();

        $summary = $this->movement->monthlySummary($keyword, $september);

        $this->assertSame('Not Ranking', $summary->monthStartLabel());
        $this->assertSame('Now ranking at 20', $summary->movementLabel());
        $this->assertStringNotContainsString('0 →', $summary->movement()->transition());
    }

    public function test_latest_rank_and_previous_are_derived_from_snapshots(): void
    {
        $project = Project::factory()->create();
        $keyword = Keyword::factory()->forProject($project)->create();
        RankingSnapshot::factory()->forKeyword($keyword)->at('2026-09-01 09:00', 24)->create();
        $latest = RankingSnapshot::factory()->forKeyword($keyword)->at('2026-09-15 09:00', 12)->create();
        RankingSnapshot::factory()->forKeyword($keyword)->at('2026-09-08 09:00', 19)->create();

        $this->assertTrue($keyword->fresh()->latestSnapshot->is($latest));
        $this->assertSame(12, Keyword::query()->with('latestSnapshot')->find($keyword->id)->latestSnapshot->position);

        $this->assertSame(19, $this->movement->latestSnapshotBefore($keyword, Carbon::parse('2026-09-15 09:00'))->position);
        $this->assertNull($this->movement->latestSnapshotBefore($keyword, Carbon::parse('2026-09-01 09:00')));
        $this->assertSame('Improved 7 positions', $this->movement->movementFor($latest)->label());
        $this->assertNull(Keyword::factory()->create()->latestSnapshot);
    }

    public function test_tasks_and_page_optimisations_have_no_effect_on_rankings(): void
    {
        $manager = User::factory()->seoManager()->create();
        $project = Project::factory()->create();
        $keyword = Keyword::factory()->forProject($project)->create();
        $september = app(CreateMonthlyCycleAction::class)->handle($project, new CyclePeriod(2026, 9));
        RankingSnapshot::factory()->forKeyword($keyword)->forCycle($september)->at('2026-09-02 09:00', 24)->create();
        RankingSnapshot::factory()->forKeyword($keyword)->forCycle($september)->at('2026-09-20 09:00', 8)->create();

        $before = $keyword->rankingSnapshots()->get(['id', 'position', 'checked_at'])->toArray();

        $task = app(CreateTaskAction::class)->handle($project, ['title' => 'Improve rankings', 'monthly_cycle_id' => $september->id], $manager);
        app(SetTaskStatusAction::class)->handle($task, TaskStatus::Completed);

        $page = Page::factory()->forProject($project)->create();
        app(RecordPageOptimizationAction::class)->handle($project, [
            'page_id' => $page->id,
            'monthly_cycle_id' => $september->id,
            'content_updated' => true,
        ], $manager);

        $this->assertSame($before, $keyword->rankingSnapshots()->get(['id', 'position', 'checked_at'])->toArray());
        $this->assertSame('Improved 16 positions', $this->movement->monthlySummary($keyword, $september)->movementLabel());
    }
}
