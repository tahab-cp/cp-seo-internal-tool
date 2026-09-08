<?php

namespace Tests\Feature\Tasks;

use App\Actions\Tasks\CreateTaskAction;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class TaskModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_task_template_has_many_items_in_order(): void
    {
        $template = TaskTemplate::factory()->withItems()->create();

        $this->assertSame(4, $template->items()->count());
        $this->assertSame('Configure Google Search Console', $template->items->first()->title);
        $this->assertTrue($template->items->first()->template->is($template));
    }

    public function test_a_task_belongs_to_a_project_and_may_belong_to_a_cycle(): void
    {
        $project = Project::factory()->create();
        $cycle = MonthlyCycle::factory()->forProject($project)->create();

        $projectLevel = Task::factory()->forProject($project)->create();
        $monthly = Task::factory()->forCycle($cycle)->create();

        $this->assertTrue($projectLevel->project->is($project));
        $this->assertNull($projectLevel->monthly_cycle_id);
        $this->assertTrue($projectLevel->isProjectLevel());

        $this->assertTrue($monthly->project->is($project));
        $this->assertTrue($monthly->monthlyCycle->is($cycle));
        $this->assertFalse($monthly->isProjectLevel());

        $this->assertSame(2, $project->tasks()->count());
        $this->assertSame(1, $cycle->tasks()->count());
    }

    public function test_a_task_cannot_reference_a_monthly_cycle_from_another_project(): void
    {
        $projectA = Project::factory()->create(['name' => 'A']);
        $projectB = Project::factory()->create(['name' => 'B']);
        $cycleB = MonthlyCycle::factory()->forProject($projectB)->create();
        $creator = User::factory()->seoManager()->create();

        try {
            app(CreateTaskAction::class)->handle($projectA, [
                'title' => 'Cross-project',
                'monthly_cycle_id' => $cycleB->id,
            ], $creator);
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('does not belong to project "A"', $exception->getMessage());
        }

        $this->assertSame(0, Task::query()->count());
    }

    public function test_statuses_and_priorities_use_the_documented_values(): void
    {
        $this->assertSame(
            ['pending', 'in_progress', 'blocked', 'completed', 'cancelled'],
            array_map(fn (TaskStatus $status): string => $status->value, TaskStatus::cases()),
        );
        $this->assertSame(
            ['low', 'normal', 'high', 'urgent'],
            array_map(fn (TaskPriority $priority): string => $priority->value, TaskPriority::cases()),
        );

        $task = Task::factory()->status(TaskStatus::Blocked)->create(['priority' => TaskPriority::Urgent]);

        $this->assertSame(TaskStatus::Blocked, $task->fresh()->status);
        $this->assertSame(TaskPriority::Urgent, $task->fresh()->priority);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'blocked', 'priority' => 'urgent']);
        $this->assertTrue(TaskStatus::Blocked->isOpen());
        $this->assertFalse(TaskStatus::Cancelled->isOpen());
        $this->assertFalse(TaskStatus::Cancelled->isCompleted());
    }

    public function test_one_template_item_generates_at_most_one_task_per_project_but_manual_tasks_are_unlimited(): void
    {
        $project = Project::factory()->create();
        $item = TaskTemplate::factory()->withItems()->create()->items->first();

        // Unlimited manual tasks (NULL template item) on the same project.
        Task::factory()->count(3)->forProject($project)->create();
        $this->assertSame(3, $project->tasks()->count());

        // The same item may generate a task on another project.
        Task::factory()->fromTemplateItem($item)->create();
        Task::factory()->forProject($project)->fromTemplateItem($item)->create();

        $this->expectException(UniqueConstraintViolationException::class);

        Task::factory()->forProject($project)->fromTemplateItem($item)->create();
    }

    public function test_relationships_from_users_and_template_items(): void
    {
        $assignee = User::factory()->seoExecutive()->create();
        $creator = User::factory()->seoManager()->create();
        $item = TaskTemplate::factory()->withItems()->create()->items->first();
        $task = Task::factory()->assignedTo($assignee)->createdBy($creator)->fromTemplateItem($item)->create();

        $this->assertTrue($task->assignee->is($assignee));
        $this->assertTrue($task->creator->is($creator));
        $this->assertTrue($task->templateItem->is($item));
        $this->assertTrue($assignee->assignedTasks->contains($task));
        $this->assertTrue($item->generatedTasks->contains($task));
    }
}
