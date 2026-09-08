<?php

namespace Tests\Feature\Tasks;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Filament\Resources\Projects\Pages\ProjectTasks;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectTasksViewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');
    }

    public function test_the_project_task_views_filter_correctly(): void
    {
        $manager = User::factory()->seoManager()->create();
        $project = Project::factory()->create();
        $september = app(CreateMonthlyCycleAction::class)->handle($project, new CyclePeriod(2026, 9));
        $august = app(CreateMonthlyCycleAction::class)->handle($project, new CyclePeriod(2026, 8));

        $thisMonth = Task::factory()->forCycle($september)->create(['title' => 'This month']);
        $lastMonth = Task::factory()->forCycle($august)->create(['title' => 'Last month']);
        $mine = Task::factory()->forProject($project)->assignedTo($manager)->create(['title' => 'Mine']);
        $overdue = Task::factory()->forProject($project)->overdue()->create(['title' => 'Overdue']);
        $done = Task::factory()->forProject($project)->completed()->create(['title' => 'Done']);

        $this->actingAs($manager);

        $page = Livewire::test(ProjectTasks::class, ['record' => $project->getRouteKey()]);

        $page->assertCanSeeTableRecords([$thisMonth, $lastMonth, $mine, $overdue, $done]);

        $page->filterTable('view', 'current_month')
            ->assertCanSeeTableRecords([$thisMonth])
            ->assertCanNotSeeTableRecords([$lastMonth, $mine, $overdue, $done]);

        $page->filterTable('view', 'mine')
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$thisMonth, $overdue, $done]);

        $page->filterTable('view', 'overdue')
            ->assertCanSeeTableRecords([$overdue])
            ->assertCanNotSeeTableRecords([$thisMonth, $mine, $done]);

        $page->filterTable('view', 'completed')
            ->assertCanSeeTableRecords([$done])
            ->assertCanNotSeeTableRecords([$thisMonth, $mine, $overdue]);

        $page->filterTable('view', 'all')
            ->assertCanSeeTableRecords([$thisMonth, $lastMonth, $mine, $overdue, $done]);
    }

    public function test_monthly_context_defaults_new_tasks_to_the_selected_cycle(): void
    {
        $manager = User::factory()->seoManager()->create();
        $project = Project::factory()->create();
        $cycle = app(CreateMonthlyCycleAction::class)->handle($project, new CyclePeriod(2026, 9));

        $this->actingAs($manager);

        $this->get(ProjectResource::getUrl('tasks', ['record' => $project, 'cycle' => $cycle->id]))
            ->assertOk()
            ->assertSee('September 2026');

        $this->get(ProjectResource::getUrl('monthly-cycles', ['record' => $project]))
            ->assertOk()
            ->assertSee('Tasks for September 2026');

        // A foreign cycle id is ignored.
        $foreign = app(CreateMonthlyCycleAction::class)->handle(Project::factory()->create(), new CyclePeriod(2026, 9));

        $this->get(ProjectResource::getUrl('tasks', ['record' => $project, 'cycle' => $foreign->id]))
            ->assertOk()
            ->assertDontSee(' — September 2026');
    }

    public function test_a_project_level_task_can_be_created_from_the_page(): void
    {
        $manager = User::factory()->seoManager()->create();
        $project = Project::factory()->create();

        $this->actingAs($manager);

        Livewire::test(ProjectTasks::class, ['record' => $project->getRouteKey()])
            ->callAction('createTask', data: [
                'title' => 'Technical audit',
                'status' => 'pending',
                'priority' => 'high',
                'monthly_cycle_id' => null,
                'category' => 'Technical',
                'due_date' => '2026-09-30',
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Task added');

        $task = Task::query()->where('title', 'Technical audit')->firstOrFail();

        $this->assertNull($task->monthly_cycle_id);
        $this->assertSame('2026-09-30', $task->due_date->toDateString());
        $this->assertTrue($task->creator->is($manager));
    }
}
