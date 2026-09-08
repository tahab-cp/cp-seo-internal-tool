<?php

namespace Tests\Feature\Reports;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Reports\CreateMonthlyReportAction;
use App\Actions\Reports\EnsureMonthlyReportAction;
use App\Actions\Reports\EnsureProjectReportSectionsAction;
use App\Actions\Reports\UpdateProjectReportSectionsAction;
use App\Enums\ReportSectionKey;
use App\Models\MonthlyCycle;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportSection;
use App\Models\Project;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * EnsureMonthlyReportAction must be idempotent and race-safe: the unique
 * index is the last line of defence, never the caller's problem.
 */
class MonthlyReportConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected MonthlyCycle $september;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->project = Project::factory()->create();
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
    }

    /**
     * A CreateMonthlyReportAction whose creation is pre-empted by another
     * "process" (the callback) between the existence check and the insert.
     */
    protected function racingCreator(callable $winnerWork): CreateMonthlyReportAction
    {
        return new class($winnerWork) extends CreateMonthlyReportAction
        {
            /** @var callable */
            private $winnerWork;

            public function __construct(callable $winnerWork)
            {
                parent::__construct(app(EnsureProjectReportSectionsAction::class));

                $this->winnerWork = $winnerWork;
            }

            public function handle(MonthlyCycle $cycle): MonthlyReport
            {
                ($this->winnerWork)();

                return parent::handle($cycle);
            }
        };
    }

    /**
     * @return array<string, int>
     */
    protected function sectionIds(MonthlyReport $report): array
    {
        return $report->sections()->get()->mapWithKeys(fn (MonthlyReportSection $s): array => [$s->section_key->value => $s->id])->all();
    }

    public function test_ensuring_twice_returns_the_same_report_with_unchanged_section_ids(): void
    {
        $first = app(EnsureMonthlyReportAction::class)->handle($this->september);
        $ids = $this->sectionIds($first);

        $this->assertCount(10, $ids);

        $second = app(EnsureMonthlyReportAction::class)->handle($this->september->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertFalse($second->wasRecentlyCreated);
        $this->assertSame($ids, $this->sectionIds($second));
        $this->assertSame(1, MonthlyReport::query()->count());
        $this->assertSame(10, MonthlyReportSection::query()->count());
    }

    public function test_losing_the_creation_race_returns_the_winners_report_untouched(): void
    {
        // The project template is customised so we can prove the loser does
        // not re-snapshot or rewrite the winner's rows.
        app(UpdateProjectReportSectionsAction::class)->handle($this->project, [
            ['section_key' => 'backlinks', 'title' => 'Link Building', 'is_enabled' => true, 'is_required' => false],
        ]);

        $winner = null;

        $creator = $this->racingCreator(function () use (&$winner): void {
            // Another process creates the report (and its snapshot) first…
            $winner = app(EnsureMonthlyReportAction::class)->handle($this->september);

            // …and the project template changes before the loser retries, so a
            // re-snapshot by the loser would be detectable.
            app(UpdateProjectReportSectionsAction::class)->handle($this->project, [
                ['section_key' => 'backlinks', 'title' => 'Links (changed)', 'is_enabled' => false, 'is_required' => true],
            ]);
        });

        $winnerIds = null;

        $loser = (new EnsureMonthlyReportAction($creator))->handle($this->september);

        $this->assertNotNull($winner);
        $this->assertSame($winner->id, $loser->id);
        $this->assertFalse($loser->wasRecentlyCreated);

        // Exactly one report and one row per section key.
        $this->assertSame(1, MonthlyReport::query()->count());
        $this->assertSame(1, MonthlyReport::query()->forCycle($this->september)->count());
        $this->assertSame(10, MonthlyReportSection::query()->count());
        $this->assertSame(
            10,
            MonthlyReportSection::query()->where('monthly_report_id', $winner->id)->distinct()->count('section_key'),
        );

        // The winner's snapshot survived intact: same ids, original titles/flags.
        $this->assertSame($this->sectionIds($winner), $this->sectionIds($loser));
        $backlinks = $loser->sections()->where('section_key', ReportSectionKey::Backlinks->value)->firstOrFail();
        $this->assertSame('Link Building', $backlinks->title);
        $this->assertTrue($backlinks->is_enabled);
        $this->assertFalse($backlinks->is_required);
        $this->assertSame(10, $backlinks->sort_order);

        // The loser's own partial work was rolled back; the template change is unrelated to the snapshot.
        $this->assertSame('Links (changed)', $this->project->reportSections()->where('section_key', 'backlinks')->value('title'));
    }

    public function test_a_collision_on_the_project_template_alone_is_retried_not_surfaced(): void
    {
        // Another process only initialised the project's section template
        // (e.g. opened the settings page) between the check and the insert.
        $creator = $this->racingCreator(function (): void {
            $this->project->reportSections()->create(ReportSectionKey::ExecutiveSummary->defaults());
        });

        $report = (new EnsureMonthlyReportAction($creator))->handle($this->september);

        $this->assertTrue($report->exists);
        $this->assertSame(1, MonthlyReport::query()->count());
        $this->assertSame(10, $report->sections()->count());
        $this->assertSame(10, $this->project->reportSections()->count());
    }

    public function test_the_database_rejects_a_duplicate_report_outside_the_action(): void
    {
        app(EnsureMonthlyReportAction::class)->handle($this->september);

        $this->expectException(UniqueConstraintViolationException::class);

        app(CreateMonthlyReportAction::class)->handle($this->september->fresh());
    }
}
