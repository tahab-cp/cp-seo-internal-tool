<?php

namespace Tests\Feature\Tasks;

use App\Enums\TaskStatus;
use App\Filament\Resources\Projects\Pages\ProjectTasks;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Task access follows project access, through URLs, the table query,
 * Livewire actions and crafted requests.
 */
class TaskAccessTest extends TestCase
{
    use RefreshDatabase;

    protected User $executive;

    protected Project $assigned;

    protected Project $unrelated;

    protected Task $assignedTask;

    protected Task $unrelatedTask;

    protected function setUp(): void
    {
        parent::setUp();

        $this->executive = User::factory()->seoExecutive()->create();
        $this->assigned = Project::factory()->ownedBy($this->executive)->create();
        $this->unrelated = Project::factory()->create();
        $this->assignedTask = Task::factory()->forProject($this->assigned)->create(['title' => 'Visible task']);
        $this->unrelatedTask = Task::factory()->forProject($this->unrelated)->create(['title' => 'Secret task']);
    }

    public function test_executive_can_view_and_manage_tasks_for_an_assigned_project(): void
    {
        $this->actingAs($this->executive);

        $this->get(ProjectResource::getUrl('tasks', ['record' => $this->assigned]))
            ->assertOk()
            ->assertSee('Visible task');

        Livewire::test(ProjectTasks::class, ['record' => $this->assigned->getRouteKey()])
            ->assertCanSeeTableRecords([$this->assignedTask])
            ->assertActionVisible('createTask')
            ->assertActionHidden('generateOnboarding')
            ->callAction('createTask', data: [
                'title' => 'Executive task',
                'status' => TaskStatus::Pending->value,
                'priority' => 'normal',
                'assigned_user_id' => $this->executive->id,
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Task added')
            ->callTableAction('start', $this->assignedTask)
            ->callTableAction('edit', $this->assignedTask, data: ['title' => 'Visible task (edited)', 'status' => 'in_progress', 'priority' => 'high'])
            ->assertNotified('Task updated')
            ->callTableAction('complete', $this->assignedTask);

        $this->assertDatabaseHas('tasks', ['title' => 'Executive task', 'project_id' => $this->assigned->id, 'assigned_user_id' => $this->executive->id, 'created_by' => $this->executive->id]);
        $this->assertSame('Visible task (edited)', $this->assignedTask->fresh()->title);
        $this->assertSame(TaskStatus::Completed, $this->assignedTask->fresh()->status);
        $this->assertTrue($this->executive->can('update', $this->assignedTask));
    }

    public function test_member_executive_also_has_access(): void
    {
        $member = User::factory()->seoExecutive()->create();
        $project = Project::factory()->withTeam([$member])->create();
        $task = Task::factory()->forProject($project)->create();

        $this->actingAs($member);

        Livewire::test(ProjectTasks::class, ['record' => $project->getRouteKey()])
            ->assertCanSeeTableRecords([$task]);

        $this->assertTrue($member->can('view', $task));
    }

    public function test_executive_cannot_access_tasks_for_an_unrelated_project(): void
    {
        $this->actingAs($this->executive);

        $this->get(ProjectResource::getUrl('tasks', ['record' => $this->unrelated]))->assertNotFound();

        try {
            Livewire::test(ProjectTasks::class, ['record' => $this->unrelated->getRouteKey()]);
            $this->fail('Expected the project to be outside the scoped resource query.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertFalse($this->executive->can('view', $this->unrelatedTask));
        $this->assertFalse($this->executive->can('update', $this->unrelatedTask));
        $this->assertSame([$this->assignedTask->id], Task::query()->accessibleBy($this->executive)->pluck('id')->all());
    }

    public function test_crafted_actions_on_an_unrelated_task_do_nothing(): void
    {
        $this->actingAs($this->executive);

        // The unrelated task is outside this page's table query, so it can
        // neither be mounted nor acted upon.
        Livewire::test(ProjectTasks::class, ['record' => $this->assigned->getRouteKey()])
            ->assertCanNotSeeTableRecords([$this->unrelatedTask])
            ->mountTableAction('complete', $this->unrelatedTask)
            ->callMountedTableAction()
            ->mountTableAction('edit', $this->unrelatedTask)
            ->callMountedTableAction();

        $fresh = $this->unrelatedTask->fresh();

        $this->assertSame(TaskStatus::Pending, $fresh->status);
        $this->assertSame('Secret task', $fresh->title);
        $this->assertNull($fresh->completed_at);
    }

    public function test_executive_cannot_assign_a_task_to_an_outsider_through_the_form(): void
    {
        $outsider = User::factory()->seoExecutive()->create();

        $this->actingAs($this->executive);

        Livewire::test(ProjectTasks::class, ['record' => $this->assigned->getRouteKey()])
            ->callAction('createTask', data: [
                'title' => 'Leak',
                'status' => 'pending',
                'priority' => 'normal',
                'assigned_user_id' => $outsider->id,
            ])
            ->assertHasFormErrors(['assigned_user_id']);

        $this->assertDatabaseMissing('tasks', ['title' => 'Leak']);
    }

    public function test_executive_cannot_create_a_task_in_another_projects_cycle_through_the_form(): void
    {
        $foreignCycle = MonthlyCycle::factory()->forProject($this->unrelated)->create();

        $this->actingAs($this->executive);

        Livewire::test(ProjectTasks::class, ['record' => $this->assigned->getRouteKey()])
            ->callAction('createTask', data: [
                'title' => 'Wrong cycle',
                'status' => 'pending',
                'priority' => 'normal',
                'monthly_cycle_id' => $foreignCycle->id,
            ])
            ->assertHasFormErrors(['monthly_cycle_id']);

        $this->assertDatabaseMissing('tasks', ['title' => 'Wrong cycle']);
    }

    public function test_admin_and_manager_manage_tasks_across_projects(): void
    {
        foreach ([
            User::factory()->superAdmin()->create(),
            User::factory()->seoManager()->create(),
        ] as $user) {
            $this->actingAs($user);

            foreach ([$this->assigned, $this->unrelated] as $project) {
                $this->get(ProjectResource::getUrl('tasks', ['record' => $project]))->assertOk();
            }

            Livewire::test(ProjectTasks::class, ['record' => $this->unrelated->getRouteKey()])
                ->assertCanSeeTableRecords([$this->unrelatedTask])
                ->assertActionVisible('createTask')
                ->assertActionVisible('generateOnboarding')
                ->assertTableActionVisible('edit', $this->unrelatedTask)
                ->callTableAction('setStatus', $this->unrelatedTask, data: ['status' => 'blocked']);

            $this->assertSame(TaskStatus::Blocked, $this->unrelatedTask->fresh()->status);
            $this->assertTrue($user->can('update', $this->unrelatedTask));
            $this->assertSame(2, Task::query()->accessibleBy($user)->count());
        }
    }

    public function test_guests_and_inactive_users_are_blocked(): void
    {
        $this->get(ProjectResource::getUrl('tasks', ['record' => $this->assigned]))
            ->assertRedirect(Filament::getLoginUrl());

        $inactive = User::factory()->seoManager()->inactive()->create();

        $this->actingAs($inactive)
            ->get(ProjectResource::getUrl('tasks', ['record' => $this->assigned]))
            ->assertForbidden();

        $this->assertSame(0, Task::query()->accessibleBy($inactive)->count());
        $this->assertSame(0, Task::query()->accessibleBy(null)->count());
    }

    public function test_the_project_view_links_to_the_tasks_page(): void
    {
        $this->actingAs($this->executive);

        $this->get(ProjectResource::getUrl('view', ['record' => $this->assigned]))
            ->assertOk()
            ->assertSee(ProjectResource::getUrl('tasks', ['record' => $this->assigned]));
    }

    public function test_nobody_may_delete_tasks_through_the_policy_or_ui(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->assertFalse($admin->can('delete', $this->assignedTask));
        $this->assertFalse($admin->can('forceDelete', $this->assignedTask));

        $this->actingAs($admin);

        Livewire::test(ProjectTasks::class, ['record' => $this->assigned->getRouteKey()])
            ->assertTableActionDoesNotExist('delete', record: $this->assignedTask)
            ->assertTableBulkActionDoesNotExist('delete');
    }
}
