<?php

namespace Tests\Feature\Tasks;

use App\Enums\TaskStatus;
use App\Filament\Pages\MyTasks;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class MyTasksTest extends TestCase
{
    use RefreshDatabase;

    protected User $executive;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        // A Tuesday, so "this week" spans Mon 14 – Sun 20 September 2026.
        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->executive = User::factory()->seoExecutive()->create();
        $this->project = Project::factory()->ownedBy($this->executive)->create();
    }

    public function test_my_tasks_contains_only_tasks_assigned_to_the_current_user(): void
    {
        $mine = Task::factory()->forProject($this->project)->assignedTo($this->executive)->create(['title' => 'Mine']);
        $someoneElses = Task::factory()->forProject($this->project)->assignedTo(User::factory()->seoManager()->create())->create();
        $unassigned = Task::factory()->forProject($this->project)->create();

        $this->actingAs($this->executive);

        $this->get(MyTasks::getUrl())->assertOk()->assertSee('Mine');

        Livewire::test(MyTasks::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$someoneElses, $unassigned]);
    }

    public function test_my_tasks_does_not_leak_tasks_from_projects_the_user_cannot_access(): void
    {
        $unrelated = Project::factory()->create();
        // Inserted directly: the actions would refuse this assignment.
        $leaked = Task::factory()->forProject($unrelated)->assignedTo($this->executive)->create(['title' => 'Leaked task']);
        $mine = Task::factory()->forProject($this->project)->assignedTo($this->executive)->create();

        $this->actingAs($this->executive);

        Livewire::test(MyTasks::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$leaked])
            ->mountTableAction('complete', $leaked)
            ->callMountedTableAction();

        $this->assertSame(TaskStatus::Pending, $leaked->fresh()->status);
        $this->get(MyTasks::getUrl())->assertDontSee('Leaked task');

        // Removing the user from the project removes the tasks from the view.
        $this->project->forceFill(['primary_seo_user_id' => null])->save();

        Livewire::test(MyTasks::class)->assertCanNotSeeTableRecords([$mine]);
    }

    public function test_quick_filters_behave_correctly(): void
    {
        $factory = fn () => Task::factory()->forProject($this->project)->assignedTo($this->executive);

        $overdue = $factory()->due('2026-09-10')->create(['title' => 'Overdue']);
        $today = $factory()->due('2026-09-15')->create(['title' => 'Today']);
        $thisWeek = $factory()->due('2026-09-19')->create(['title' => 'This week']);
        $nextWeek = $factory()->due('2026-09-22')->create(['title' => 'Next week']);
        $noDue = $factory()->create(['title' => 'No due date']);
        $completedOverdue = $factory()->due('2026-09-10')->completed()->create(['title' => 'Completed overdue']);
        $cancelled = $factory()->due('2026-09-15')->status(TaskStatus::Cancelled)->create(['title' => 'Cancelled today']);

        $this->actingAs($this->executive);

        $page = Livewire::test(MyTasks::class);

        // Default view: all open.
        $page->assertCanSeeTableRecords([$overdue, $today, $thisWeek, $nextWeek, $noDue])
            ->assertCanNotSeeTableRecords([$completedOverdue, $cancelled]);

        $page->filterTable('view', 'overdue')
            ->assertCanSeeTableRecords([$overdue])
            ->assertCanNotSeeTableRecords([$today, $thisWeek, $nextWeek, $noDue, $completedOverdue, $cancelled]);

        $page->filterTable('view', 'due_today')
            ->assertCanSeeTableRecords([$today])
            ->assertCanNotSeeTableRecords([$overdue, $thisWeek, $nextWeek, $noDue, $cancelled]);

        $page->filterTable('view', 'this_week')
            ->assertCanSeeTableRecords([$today, $thisWeek])
            ->assertCanNotSeeTableRecords([$overdue, $nextWeek, $noDue, $completedOverdue, $cancelled]);

        $page->filterTable('view', 'completed')
            ->assertCanSeeTableRecords([$completedOverdue])
            ->assertCanNotSeeTableRecords([$overdue, $today, $thisWeek, $nextWeek, $noDue, $cancelled]);
    }

    public function test_tasks_can_be_started_and_completed_from_my_tasks(): void
    {
        $task = Task::factory()->forProject($this->project)->assignedTo($this->executive)->create();

        $this->actingAs($this->executive);

        Livewire::test(MyTasks::class)
            ->assertTableActionVisible('start', $task)
            ->callTableAction('start', $task);

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);

        Livewire::test(MyTasks::class)
            ->assertTableActionHidden('start', $task)
            ->callTableAction('complete', $task);

        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_my_tasks_is_in_navigation_for_every_role_and_blocked_for_guests(): void
    {
        $this->get(MyTasks::getUrl())->assertRedirect(Filament::getLoginUrl());

        foreach ([
            User::factory()->superAdmin()->create(),
            User::factory()->seoManager()->create(),
            $this->executive,
        ] as $user) {
            $this->actingAs($user)
                ->get('/admin')
                ->assertOk()
                ->assertSee(MyTasks::getUrl());
        }
    }
}
