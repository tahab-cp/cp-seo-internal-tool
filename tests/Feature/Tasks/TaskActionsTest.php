<?php

namespace Tests\Feature\Tasks;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Tasks\CreateTaskAction;
use App\Actions\Tasks\SetTaskStatusAction;
use App\Actions\Tasks\UpdateTaskAction;
use App\Enums\MonthlyCycleStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Exceptions\InactiveUserAssignmentException;
use App\Exceptions\LockedMonthlyCycleException;
use App\Exceptions\TaskAssignmentException;
use App\Models\MonthlyCycle;
use App\Models\Package;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TaskActionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->manager = User::factory()->seoManager()->create();
        $this->project = Project::factory()->create();
    }

    // -- Assignment rules --------------------------------------------------

    public function test_an_inactive_user_cannot_be_assigned(): void
    {
        $inactive = User::factory()->seoManager()->inactive()->create();

        $this->expectException(InactiveUserAssignmentException::class);

        app(CreateTaskAction::class)->handle($this->project, ['title' => 'T', 'assigned_user_id' => $inactive->id], $this->manager);
    }

    public function test_an_executive_not_on_the_project_cannot_be_assigned(): void
    {
        $outsider = User::factory()->seoExecutive()->create();
        $task = Task::factory()->forProject($this->project)->create();

        try {
            app(CreateTaskAction::class)->handle($this->project, ['title' => 'T', 'assigned_user_id' => $outsider->id], $this->manager);
            $this->fail('Expected TaskAssignmentException.');
        } catch (TaskAssignmentException) {
            $this->addToAssertionCount(1);
        }

        try {
            app(UpdateTaskAction::class)->handle($task, ['assigned_user_id' => $outsider->id]);
            $this->fail('Expected TaskAssignmentException.');
        } catch (TaskAssignmentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertNull($task->fresh()->assigned_user_id);
        $this->assertSame(1, Task::query()->count());
    }

    public function test_active_users_who_can_access_the_project_may_be_assigned(): void
    {
        $owner = User::factory()->seoExecutive()->create();
        $member = User::factory()->seoExecutive()->create();
        $project = Project::factory()->ownedBy($owner)->withTeam([$member])->create();
        $admin = User::factory()->superAdmin()->create();

        foreach ([$owner, $member, $this->manager, $admin] as $assignee) {
            $task = app(CreateTaskAction::class)->handle($project, ['title' => "For {$assignee->name}", 'assigned_user_id' => $assignee->id], $this->manager);

            $this->assertTrue($task->assignee->is($assignee));
        }

        $task = app(UpdateTaskAction::class)->handle($task, ['assigned_user_id' => null]);
        $this->assertNull($task->assigned_user_id);
    }

    // -- Completion timestamps ---------------------------------------------

    public function test_completed_sets_completed_at_and_reopening_clears_it(): void
    {
        $task = app(CreateTaskAction::class)->handle($this->project, ['title' => 'Audit'], $this->manager);

        $this->assertSame(TaskStatus::Pending, $task->status);
        $this->assertNull($task->completed_at);

        app(SetTaskStatusAction::class)->handle($task, TaskStatus::Completed);
        $this->assertTrue($task->fresh()->completed_at->equalTo(Carbon::now()));

        // Staying completed keeps the original timestamp.
        Carbon::setTestNow('2026-09-16 10:00:00');
        app(UpdateTaskAction::class)->handle($task, ['status' => 'completed', 'title' => 'Audit (edited)']);
        $this->assertSame('2026-09-15 10:00:00', $task->fresh()->completed_at->toDateTimeString());

        foreach ([TaskStatus::Pending, TaskStatus::InProgress, TaskStatus::Blocked] as $reopened) {
            app(SetTaskStatusAction::class)->handle($task, TaskStatus::Completed);
            app(SetTaskStatusAction::class)->handle($task, $reopened);

            $this->assertSame($reopened, $task->fresh()->status);
            $this->assertNull($task->fresh()->completed_at);
        }
    }

    public function test_cancelled_is_not_completed(): void
    {
        $task = Task::factory()->forProject($this->project)->completed()->create();

        app(SetTaskStatusAction::class)->handle($task, TaskStatus::Cancelled);

        $this->assertSame(TaskStatus::Cancelled, $task->fresh()->status);
        $this->assertNull($task->fresh()->completed_at);
        $this->assertFalse($task->fresh()->status->isCompleted());
        $this->assertSame(0, Task::query()->completed()->count());

        // Creating directly as completed also stamps the timestamp.
        $done = app(CreateTaskAction::class)->handle($this->project, ['title' => 'Done', 'status' => TaskStatus::Completed, 'priority' => 'high'], $this->manager);
        $this->assertNotNull($done->completed_at);
        $this->assertSame(TaskPriority::High, $done->priority);
    }

    // -- Locked monthly cycles ---------------------------------------------

    protected function lockedCycle(): MonthlyCycle
    {
        $cycle = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 8));
        $cycle->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now(), 'locked_by' => $this->manager->id])->save();

        return $cycle->fresh();
    }

    public function test_creating_a_task_in_a_locked_cycle_is_denied(): void
    {
        $locked = $this->lockedCycle();

        $this->expectException(LockedMonthlyCycleException::class);

        app(CreateTaskAction::class)->handle($this->project, ['title' => 'Late', 'monthly_cycle_id' => $locked->id], $this->manager);
    }

    public function test_editing_and_status_changes_in_a_locked_cycle_are_denied(): void
    {
        $open = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
        $task = Task::factory()->forCycle($open)->create(['title' => 'August work']);
        $open->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();
        $task->refresh();

        $this->assertTrue($task->isLocked());
        $this->assertFalse($this->manager->can('update', $task));
        $this->assertFalse($this->manager->can('setStatus', $task));
        $this->assertFalse(User::factory()->superAdmin()->create()->can('update', $task));

        foreach ([
            fn () => app(UpdateTaskAction::class)->handle($task, ['title' => 'Changed']),
            fn () => app(SetTaskStatusAction::class)->handle($task, TaskStatus::Completed),
            fn () => app(SetTaskStatusAction::class)->handle($task, TaskStatus::Cancelled),
            fn () => app(SetTaskStatusAction::class)->handle($task, TaskStatus::InProgress),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected LockedMonthlyCycleException.');
            } catch (LockedMonthlyCycleException) {
                $this->addToAssertionCount(1);
            }
        }

        $fresh = $task->fresh();
        $this->assertSame('August work', $fresh->title);
        $this->assertSame(TaskStatus::Pending, $fresh->status);
        $this->assertNull($fresh->completed_at);
    }

    public function test_a_task_cannot_be_moved_into_a_locked_cycle(): void
    {
        $locked = $this->lockedCycle();
        $task = Task::factory()->forProject($this->project)->create();

        $this->expectException(LockedMonthlyCycleException::class);

        app(UpdateTaskAction::class)->handle($task, ['monthly_cycle_id' => $locked->id]);
    }

    public function test_project_level_tasks_stay_editable_regardless_of_locked_cycles(): void
    {
        $this->lockedCycle();
        $task = Task::factory()->forProject($this->project)->create(['title' => 'Setup']);

        $this->assertFalse($task->isLocked());
        $this->assertTrue($this->manager->can('update', $task));

        app(UpdateTaskAction::class)->handle($task, ['title' => 'Setup (done)', 'priority' => TaskPriority::Urgent]);
        app(SetTaskStatusAction::class)->handle($task, TaskStatus::Completed);

        $this->assertSame('Setup (done)', $task->fresh()->title);
        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
    }

    // -- Tasks never touch targets -----------------------------------------

    public function test_task_completion_does_not_alter_monthly_cycle_targets(): void
    {
        $project = Project::factory()->withPackage(Package::factory()->withTargets()->create())->create();
        $cycle = app(CreateMonthlyCycleAction::class)->handle($project, new CyclePeriod(2026, 9));
        $before = $cycle->targets()->orderBy('id')->get(['id', 'target_key', 'target_value', 'updated_at'])->toArray();

        $task = app(CreateTaskAction::class)->handle($project, ['title' => 'Publish 3 blogs', 'monthly_cycle_id' => $cycle->id, 'category' => 'Content'], $this->manager);
        app(SetTaskStatusAction::class)->handle($task, TaskStatus::Completed);

        $this->assertSame($before, $cycle->targets()->orderBy('id')->get(['id', 'target_key', 'target_value', 'updated_at'])->toArray());
        $this->assertSame(['backlinks' => 50, 'blogs' => 8, 'guest_posts' => 8, 'pages_optimized' => 8], $cycle->targets()->pluck('target_value', 'target_key')->all());
    }
}
