<?php

namespace Tests\Feature\Tasks;

use App\Actions\Tasks\GenerateOnboardingTasksAction;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Resources\Projects\Pages\ProjectTasks;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class OnboardingGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected TaskTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->manager = User::factory()->seoManager()->create();
        $this->template = TaskTemplate::factory()->withItems()->create();
    }

    public function test_generation_creates_one_project_level_task_per_item_with_copied_fields(): void
    {
        $project = Project::factory()->create(['start_date' => null]);

        $created = app(GenerateOnboardingTasksAction::class)->handle($project, $this->template, $this->manager);

        $this->assertCount(4, $created);
        $this->assertSame(4, $project->tasks()->count());

        foreach ($this->template->items as $item) {
            $task = $project->tasks()->where('task_template_item_id', $item->id)->firstOrFail();

            $this->assertNull($task->monthly_cycle_id);
            $this->assertSame($item->title, $task->title);
            $this->assertSame($item->description, $task->description);
            $this->assertSame($item->category, $task->category);
            $this->assertSame(TaskStatus::Pending, $task->status);
            $this->assertSame(TaskPriority::Normal, $task->priority);
            $this->assertNull($task->completed_at);
            $this->assertTrue($task->creator->is($this->manager));
        }
    }

    public function test_tasks_are_assigned_to_an_active_primary_owner_or_left_unassigned(): void
    {
        $owner = User::factory()->seoExecutive()->create();
        $withOwner = Project::factory()->ownedBy($owner)->create();
        $noOwner = Project::factory()->create();
        $inactiveOwner = User::factory()->seoExecutive()->create();
        $withInactiveOwner = Project::factory()->ownedBy($inactiveOwner)->create();
        $inactiveOwner->forceFill(['is_active' => false])->save();

        app(GenerateOnboardingTasksAction::class)->handle($withOwner, $this->template, $this->manager);
        app(GenerateOnboardingTasksAction::class)->handle($noOwner, $this->template, $this->manager);
        app(GenerateOnboardingTasksAction::class)->handle($withInactiveOwner, $this->template, $this->manager);

        $this->assertSame([$owner->id], $withOwner->tasks()->distinct()->pluck('assigned_user_id')->all());
        $this->assertSame([null], $noOwner->tasks()->distinct()->pluck('assigned_user_id')->all());
        $this->assertSame([null], $withInactiveOwner->tasks()->distinct()->pluck('assigned_user_id')->all());
    }

    public function test_due_dates_use_the_project_start_date_or_fall_back_to_the_generation_date(): void
    {
        $withStart = Project::factory()->create(['start_date' => '2026-10-01']);
        $withoutStart = Project::factory()->create(['start_date' => null]);

        app(GenerateOnboardingTasksAction::class)->handle($withStart, $this->template, $this->manager);
        app(GenerateOnboardingTasksAction::class)->handle($withoutStart, $this->template, $this->manager);

        $dueFor = fn (Project $project, string $title): ?string => $project->tasks()->where('title', $title)->firstOrFail()->due_date?->toDateString();

        $this->assertSame('2026-10-04', $dueFor($withStart, 'Configure Google Search Console'));
        $this->assertSame('2026-10-15', $dueFor($withStart, 'Initial technical audit'));
        $this->assertNull($dueFor($withStart, 'Keyword research'));

        $this->assertSame('2026-09-18', $dueFor($withoutStart, 'Configure Google Search Console'));
        $this->assertSame('2026-09-29', $dueFor($withoutStart, 'Initial technical audit'));
        $this->assertNull($dueFor($withoutStart, 'Keyword research'));
    }

    public function test_generation_is_idempotent_and_only_adds_tasks_for_new_items(): void
    {
        $project = Project::factory()->create();
        $action = app(GenerateOnboardingTasksAction::class);

        $first = $action->handle($project, $this->template, $this->manager);
        $second = $action->handle($project, $this->template, $this->manager);

        $this->assertCount(4, $first);
        $this->assertCount(0, $second);
        $this->assertSame(4, $project->tasks()->count());

        $newItem = $this->template->items()->create(['title' => 'Competitor research', 'sort_order' => 10]);

        $third = $action->handle($project, $this->template->fresh(), $this->manager);

        $this->assertCount(1, $third);
        $this->assertSame($newItem->id, $third->first()->task_template_item_id);
        $this->assertSame(5, $project->tasks()->count());
    }

    public function test_existing_generated_tasks_are_operational_snapshots_not_overwritten_by_template_edits(): void
    {
        $project = Project::factory()->ownedBy(User::factory()->seoExecutive()->create())->create();
        $action = app(GenerateOnboardingTasksAction::class);
        $action->handle($project, $this->template, $this->manager);

        $item = $this->template->items->first();
        $task = $project->tasks()->where('task_template_item_id', $item->id)->firstOrFail();
        $task->forceFill(['status' => TaskStatus::InProgress, 'assigned_user_id' => null, 'due_date' => '2026-12-31'])->save();

        $item->update(['title' => 'Renamed in template', 'description' => 'New description', 'default_due_days' => 30]);
        $action->handle($project, $this->template->fresh(), $this->manager);

        $fresh = $task->fresh();

        $this->assertSame('Configure Google Search Console', $fresh->title);
        $this->assertSame('Verify the property and submit the sitemap.', $fresh->description);
        $this->assertSame(TaskStatus::InProgress, $fresh->status);
        $this->assertNull($fresh->assigned_user_id);
        $this->assertSame('2026-12-31', $fresh->due_date->toDateString());
        $this->assertSame(4, $project->tasks()->count());
    }

    public function test_soft_deleted_generated_tasks_are_not_regenerated(): void
    {
        $project = Project::factory()->create();
        $action = app(GenerateOnboardingTasksAction::class);
        $action->handle($project, $this->template, $this->manager);

        $project->tasks()->first()->delete();

        $this->assertCount(0, $action->handle($project, $this->template, $this->manager));
        $this->assertSame(4, $project->tasks()->withTrashed()->count());
    }

    public function test_an_inactive_template_cannot_be_used(): void
    {
        $inactive = TaskTemplate::factory()->inactive()->withItems()->create();
        $project = Project::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        app(GenerateOnboardingTasksAction::class)->handle($project, $inactive, $this->manager);
    }

    public function test_managers_can_generate_from_the_tasks_page_and_executives_cannot(): void
    {
        $executive = User::factory()->seoExecutive()->create();
        $project = Project::factory()->ownedBy($executive)->create();
        $inactive = TaskTemplate::factory()->inactive()->withItems()->create();

        $this->actingAs($executive);

        $this->assertFalse($executive->can('generateOnboarding', $project));
        $this->assertFalse($executive->can('generateOnboarding', Project::class));

        Livewire::test(ProjectTasks::class, ['record' => $project->getRouteKey()])
            ->assertActionHidden('generateOnboarding')
            ->mountAction('generateOnboarding')
            ->callMountedAction();

        $this->assertSame(0, $project->tasks()->count());

        $this->actingAs($this->manager);

        Livewire::test(ProjectTasks::class, ['record' => $project->getRouteKey()])
            ->assertActionVisible('generateOnboarding')
            ->callAction('generateOnboarding', data: ['task_template_id' => $inactive->id])
            ->assertHasFormErrors(['task_template_id']);

        $this->assertSame(0, $project->tasks()->count());

        Livewire::test(ProjectTasks::class, ['record' => $project->getRouteKey()])
            ->callAction('generateOnboarding', data: ['task_template_id' => $this->template->id])
            ->assertHasNoFormErrors()
            ->assertNotified('4 onboarding task(s) generated');

        Livewire::test(ProjectTasks::class, ['record' => $project->getRouteKey()])
            ->callAction('generateOnboarding', data: ['task_template_id' => $this->template->id])
            ->assertNotified('0 onboarding task(s) generated');

        $this->assertSame(4, $project->tasks()->count());
        $this->assertSame([$executive->id], $project->tasks()->distinct()->pluck('assigned_user_id')->all());
        $this->assertTrue(Task::query()->where('project_id', $project->id)->get()->every(fn (Task $task): bool => $task->creator->is($this->manager)));
    }
}
