<?php

namespace Tests\Feature\Notes;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Reports\EnsureMonthlyReportAction;
use App\Enums\MonthlyCycleStatus;
use App\Enums\MonthlyNoteType;
use App\Filament\Resources\Projects\Pages\ProjectMonthlyWork;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Client;
use App\Models\MonthlyCycle;
use App\Models\MonthlyNote;
use App\Models\Project;
use App\Models\User;
use App\Services\Reports\ReportReadinessService;
use App\Support\MonthlyCycles\CyclePeriod;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Presentation of the redesigned Project → Monthly Work screen. Notes come
 * from the existing records and actions; only their placement, grouping
 * and wording are asserted.
 */
class ProjectMonthlyWorkLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected User $executive;

    protected Project $project;

    protected MonthlyCycle $september;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->manager = User::factory()->seoManager()->create();
        $this->executive = User::factory()->seoExecutive()->create(['name' => 'Emma Executive']);
        $client = Client::factory()->create(['name' => 'BrightNest Interiors']);
        $this->project = Project::factory()->forClient($client)->ownedBy($this->executive)->create([
            'name' => 'BrightNest Manchester SEO', 'website_url' => 'https://www.example.com', 'target_location' => 'Manchester, UK',
        ]);
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
    }

    protected function url(Project $project, array $extra = []): string
    {
        return ProjectResource::getUrl('monthly-work', ['record' => $project] + $extra);
    }

    protected function page()
    {
        return Livewire::test(ProjectMonthlyWork::class, ['record' => $this->project->getRouteKey()]);
    }

    protected function note(MonthlyNoteType $type, string $body, ?string $title = null): MonthlyNote
    {
        return MonthlyNote::factory()->forCycle($this->september)->type($type)->by($this->executive)->create(['title' => $title, 'body' => $body]);
    }

    public function test_the_workspace_header_module_title_and_month_controls_render(): void
    {
        $this->actingAs($this->manager);
        $html = $this->get($this->url($this->project))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<h1[^>]*>\s*BrightNest Manchester SEO\s*<\/h1>/s', $html));
        $this->assertStringContainsString('BrightNest Interiors • Manchester, UK', $html);
        $this->assertSame(1, preg_match('/aria-current="page"[^>]*data-project-module="monthly-work"|data-project-module="monthly-work"[^>]*aria-current="page"/', $html), 'Monthly work is the active module');
        $this->assertSame(1, preg_match('/data-monthly-work-header>.*?<h2[^>]*>Monthly work<\/h2>.*?Capture wins, challenges, observations and recommendations throughout the month\./s', $html));
        $this->assertStringNotContainsString('Back to project', $html);
        $this->assertStringNotContainsString('narrative', $html);

        $this->assertStringContainsString('data-selected-cycle="'.$this->september->id.'"', $html);
        $this->assertStringContainsString('data-notes-cycle-status="open"', $html);
        $this->assertSame(1, preg_match('/<label for="monthly-work-cycle"[^>]*>Reporting month<\/label>\s*<div style="min-width: 12rem">/s', $html), 'the month selector is compact');
        $this->assertSame(1, preg_match('/data-monthly-work-add>.*?Add note/s', $html), 'a page-level Add note button is offered');
        $this->assertStringContainsString('These notes help prepare the Executive Summary, Recommendations and Next Month Focus sections of the monthly report.', $html);

        $this->page()->assertActionVisible('addNote')->assertActionDoesNotExist('viewProject');
    }

    public function test_summary_cards_and_lanes_group_every_note_type(): void
    {
        $this->note(MonthlyNoteType::Win, 'Three commercial keywords moved onto page one.', 'Improved keyword visibility');
        $this->note(MonthlyNoteType::Win, 'All priority service pages were updated.');
        $this->note(MonthlyNoteType::Challenge, 'The September guide remained in review longer than planned.', 'Content approval was delayed');
        $this->note(MonthlyNoteType::Observation, 'Kitchen-related keywords are improving faster than bedroom terms.');
        $this->note(MonthlyNoteType::Observation, 'Branded searches rose after the PR feature.');
        $this->note(MonthlyNoteType::Recommendation, 'Continue link building to the Interior Design service page.');
        $this->note(MonthlyNoteType::NextMonthFocus, 'Publish two supporting blogs and improve Bedroom Design content.');

        $this->actingAs($this->manager);
        $html = $this->get($this->url($this->project))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<div\b[^>]*data-notes-summary[^>]*>/s', $html, $summary));
        $this->assertStringContainsString('--cols-xl: repeat(4', $summary[0]);
        $this->assertSame(1, preg_match('/data-notes-card="wins".*?data-notes-count="2"/s', $html));
        $this->assertSame(1, preg_match('/data-notes-card="challenges".*?data-notes-count="3"/s', $html));
        $this->assertSame(1, preg_match('/data-notes-card="recommendations".*?data-notes-count="1"/s', $html));
        $this->assertSame(1, preg_match('/data-notes-card="focus".*?data-notes-count="1"/s', $html));

        // Each lane holds only its own types, with the type badge on every card.
        $this->assertSame(1, preg_match('/<ul[^>]*data-lane="wins">(.*?)<\/ul>/s', $html, $wins));
        $this->assertSame(2, substr_count($wins[1], 'data-note-type="win"'));
        $this->assertStringContainsString('Improved keyword visibility', $wins[1]);
        $this->assertStringContainsString('Three commercial keywords moved onto page one.', $wins[1]);

        $this->assertSame(1, preg_match('/<ul[^>]*data-lane="challenges">(.*?)<\/ul>/s', $html, $challenges));
        $this->assertSame(1, substr_count($challenges[1], 'data-note-type="challenge"'));
        $this->assertSame(2, substr_count($challenges[1], 'data-note-type="observation"'));
        $this->assertSame(1, preg_match('/>\s*Challenge\s*</', $challenges[1]));
        $this->assertSame(1, preg_match('/>\s*Observation\s*</', $challenges[1]));

        $this->assertSame(1, preg_match('/<ul[^>]*data-lane="recommendations">(.*?)<\/ul>/s', $html, $recommendations));
        $this->assertStringContainsString('Continue link building to the Interior Design service page.', $recommendations[1]);
        $this->assertStringNotContainsString('data-note-type="next_month_focus"', $recommendations[1], 'next month focus has its own lane');

        $this->assertSame(1, preg_match('/<ul[^>]*data-lane="focus">(.*?)<\/ul>/s', $html, $focus));
        $this->assertStringContainsString('Publish two supporting blogs and improve Bedroom Design content.', $focus[1]);

        $this->assertStringNotContainsString('data-empty-lane', $html);
        $this->assertSame(1, preg_match('/<div\b[^>]*data-notes-lanes[^>]*>/s', $html, $lanes));
        $this->assertStringContainsString('--cols-xl: repeat(2', $lanes[0], 'lanes sit in a two-column grid on wide screens');
        $this->assertSame(1, preg_match('/data-notes-lanes.*?data-lane="wins".*?data-lane="challenges".*?data-lane="recommendations".*?data-lane="focus"/s', $html), 'wins and challenges on the left, recommendations and focus on the right');
    }

    public function test_note_cards_show_title_body_and_quiet_metadata_and_skip_blank_titles(): void
    {
        $titled = $this->note(MonthlyNoteType::Win, 'Three commercial keywords moved onto page one.', 'Improved keyword visibility');
        $untitled = $this->note(MonthlyNoteType::Observation, 'Kitchen-related keywords are improving faster than bedroom terms.');

        $this->actingAs($this->manager);
        $html = $this->get($this->url($this->project))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<li[^>]*data-note="'.$titled->id.'"[^>]*>(.*?)<\/li>/s', $html, $card));
        $this->assertSame(1, preg_match('/data-note-title>Improved keyword visibility</', $card[1]));
        $this->assertSame(1, preg_match('/data-note-body>Three commercial keywords moved onto page one\.</', $card[1]));
        $this->assertSame(1, preg_match('/data-note-meta>Emma Executive • 15 Sep 2026</', $card[1]));
        $this->assertStringContainsString('data-note-actions', $card[1]);
        $this->assertStringNotContainsString('created_by', $card[1]);

        $this->assertSame(1, preg_match('/<li[^>]*data-note="'.$untitled->id.'"[^>]*>(.*?)<\/li>/s', $html, $plain));
        $this->assertStringNotContainsString('data-note-title', $plain[1], 'a blank title renders no title line');
        $this->assertStringContainsString('Kitchen-related keywords are improving faster than bedroom terms.', $plain[1]);
    }

    public function test_lane_actions_preselect_the_type_and_use_the_existing_workflow(): void
    {
        $this->actingAs($this->manager);
        $html = $this->get($this->url($this->project))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/data-lane-actions="wins">.*?Add win/s', $html));
        $this->assertSame(1, preg_match('/data-lane-actions="challenges">.*?Add challenge.*?Add observation/s', $html));
        $this->assertSame(1, preg_match('/data-lane-actions="recommendations">.*?Add recommendation/s', $html));
        $this->assertSame(1, preg_match('/data-lane-actions="focus">.*?Add next month focus/s', $html));

        $component = $this->page()->mountAction('addLaneNote', arguments: ['type' => 'next_month_focus']);
        $this->assertSame('next_month_focus', $component->instance()->mountedActions[0]['data']['type'] ?? null, 'the lane link preselects its type');

        $component
            ->setActionData(['body' => 'Improve Bedroom Design content.'])
            ->callMountedAction()
            ->assertNotified('Note added');

        $this->assertSame(1, $this->september->monthlyNotes()->ofType(MonthlyNoteType::NextMonthFocus)->count());

        // The page-level button is the same workflow with the type chosen in the form.
        $this->page()
            ->callAction('addNote', data: ['type' => 'win', 'body' => 'Page-one win.'])
            ->assertNotified('Note added');

        $this->assertSame(1, $this->september->monthlyNotes()->ofType(MonthlyNoteType::Win)->count());
    }

    public function test_empty_states_are_compact_and_specific(): void
    {
        $this->actingAs($this->manager);
        $html = $this->get($this->url($this->project))->assertOk()->getContent();

        foreach ([
            'wins' => ['No wins recorded yet', 'Add notable results or positive progress from this month.'],
            'challenges' => ['No challenges or observations yet', 'Capture anything that affected progress or is worth remembering.'],
            'recommendations' => ['No recommendations yet', 'Add recommendations to help prepare the monthly report.'],
            'focus' => ['No next-month focus yet', 'Add the main priorities for the next reporting period.'],
        ] as $lane => [$heading, $help]) {
            $this->assertSame(1, preg_match('/data-empty-lane="'.$lane.'">.*?'.preg_quote($heading, '/').'.*?'.preg_quote($help, '/').'/s', $html), $lane);
        }
        $this->assertSame(4, substr_count($html, 'data-notes-count="0"'));

        $bare = Project::factory()->create();
        $this->get($this->url($bare))->assertOk()->assertSee('data-notes-no-cycles', false)->assertDontSee('data-notes-summary', false);
    }

    public function test_locked_months_keep_notes_visible_and_hide_every_writing_action(): void
    {
        $frozen = $this->note(MonthlyNoteType::Recommendation, 'Frozen recommendation');
        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();

        $this->actingAs($this->manager);
        $html = $this->get($this->url($this->project))->assertOk()->getContent();

        $this->assertStringContainsString('data-notes-cycle-status="locked"', $html);
        $this->assertStringContainsString('This reporting month is locked. Monthly notes are read-only.', $html);
        $this->assertStringContainsString('September 2026 (locked)', $html);
        $this->assertStringContainsString('Frozen recommendation', $html);
        $this->assertStringNotContainsString('data-monthly-work-add', $html);
        $this->assertStringNotContainsString('data-lane-actions', $html);
        $this->assertStringNotContainsString('data-note-actions', $html);

        $this->page()
            ->assertActionHidden('addNote')
            ->assertActionHidden('addLaneNote')
            ->assertActionHidden('editNote')
            ->assertActionHidden('deleteNote')
            ->mountAction('deleteNote', arguments: ['note' => $frozen->id])
            ->callMountedAction();

        $this->assertNotNull($frozen->fresh());
    }

    public function test_report_readiness_is_unchanged_by_the_presentation(): void
    {
        $this->actingAs($this->manager);
        $report = app(EnsureMonthlyReportAction::class)->handle($this->september);
        $service = app(ReportReadinessService::class);

        $before = $service->evaluate($report->fresh())->toArray();
        $this->get($this->url($this->project))->assertOk();
        $this->assertSame($before, $service->evaluate($report->fresh())->toArray(), 'viewing the screen changes nothing');

        $this->page()->callAction('addLaneNote', data: ['type' => 'recommendation', 'body' => 'Continue link building.'], arguments: ['type' => 'recommendation']);
        $after = $service->evaluate($report->fresh())->toArray();

        $this->assertNotEquals($before, $after, 'adding a recommendation still changes readiness exactly as before');
    }

    public function test_visibility_and_navigation_scope_are_unchanged(): void
    {
        $outsider = User::factory()->seoExecutive()->create();
        $this->actingAs($outsider);
        $this->get($this->url($this->project))->assertNotFound();

        $this->actingAs($this->executive);
        $this->get($this->url($this->project))->assertOk()->assertSee('data-project-module="monthly-work"', false);

        $labels = collect(Filament::getPanel('admin')->getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->map(fn ($item) => $item->getLabel())
            ->all();
        $this->assertNotContains('Monthly work', $labels);
        $this->assertNotContains('Monthly Work', $labels);
    }
}
