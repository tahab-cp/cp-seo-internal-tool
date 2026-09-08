<?php

namespace Tests\Feature\Tasks;

use App\Actions\Tasks\GenerateOnboardingTasksAction;
use App\Actions\TaskTemplates\SyncTaskTemplateItemsAction;
use App\Enums\TaskStatus;
use App\Filament\Resources\TaskTemplates\Pages\EditTaskTemplate;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Historical-data invariant: template items are configuration, generated
 * tasks are operational history. Removing an item never destroys or
 * rewrites the tasks it produced; the link is simply nulled by the
 * ON DELETE SET NULL foreign key in create_tasks_table.
 */
class TemplateItemRemovalTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->manager = User::factory()->seoManager()->create();
    }

    public function test_the_foreign_key_sets_null_on_delete_rather_than_cascading(): void
    {
        $rule = collect(DB::select(
            'SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            ['tasks', 'tasks_task_template_item_id_foreign'],
        ))->first()?->DELETE_RULE;

        $this->assertSame('SET NULL', $rule);
    }

    public function test_removing_a_template_item_keeps_the_generated_task_and_its_snapshot_intact(): void
    {
        $owner = User::factory()->seoExecutive()->create();
        $project = Project::factory()->ownedBy($owner)->create(['start_date' => '2026-10-01']);
        $template = TaskTemplate::factory()->withItems([
            ['title' => 'Configure Google Search Console', 'description' => 'Verify the property.', 'category' => 'Technical', 'default_due_days' => 3],
            ['title' => 'Keyword research', 'category' => 'Research'],
        ])->create();
        [$gsc, $research] = $template->items->all();

        // 1. Generate tasks from the template.
        app(GenerateOnboardingTasksAction::class)->handle($project, $template, $this->manager);

        $task = $project->tasks()->where('task_template_item_id', $gsc->id)->firstOrFail();
        $task->forceFill(['status' => TaskStatus::InProgress])->save();

        // Raw column values, so the comparison below is exact.
        $snapshotColumns = [
            'id', 'project_id', 'monthly_cycle_id', 'assigned_user_id', 'created_by',
            'title', 'description', 'category', 'status', 'priority', 'due_date', 'completed_at', 'created_at',
        ];
        $snapshot = collect($task->fresh()->getAttributes())->only($snapshotColumns)->all();

        // 2. Remove that item from the template (keeping the other one).
        app(SyncTaskTemplateItemsAction::class)->handle($template, [
            ['id' => $research->id, 'title' => $research->title, 'category' => $research->category],
        ]);

        $this->assertDatabaseMissing('task_template_items', ['id' => $gsc->id]);

        // 3. The generated task still exists, 5. nothing cascaded.
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'deleted_at' => null]);
        $this->assertSame(2, $project->tasks()->count());
        $this->assertSame(2, Task::query()->count());

        // 4. Its operational snapshot is unchanged; only the link was nulled.
        $fresh = $task->fresh();

        $this->assertNull($fresh->task_template_item_id);
        $this->assertSame($snapshot, collect($fresh->getAttributes())->only($snapshotColumns)->all());
        $this->assertSame('Configure Google Search Console', $fresh->title);
        $this->assertSame('Verify the property.', $fresh->description);
        $this->assertSame('Technical', $fresh->category);
        $this->assertSame(TaskStatus::InProgress, $fresh->status);
        $this->assertTrue($fresh->assignee->is($owner));
        $this->assertSame('2026-10-04', $fresh->due_date->toDateString());

        // The surviving item's task keeps its link.
        $this->assertSame($research->id, $project->tasks()->where('title', 'Keyword research')->value('task_template_item_id'));
    }

    public function test_removing_an_item_through_the_template_form_behaves_the_same(): void
    {
        $this->actingAs($this->manager);
        $project = Project::factory()->create();
        $template = TaskTemplate::factory()->withItems([
            ['title' => 'Item to remove', 'category' => 'Setup'],
            ['title' => 'Item to keep'],
        ])->create();
        [$removed, $kept] = $template->items->all();

        app(GenerateOnboardingTasksAction::class)->handle($project, $template, $this->manager);
        $task = $project->tasks()->where('task_template_item_id', $removed->id)->firstOrFail();

        Livewire::test(EditTaskTemplate::class, ['record' => $template->getRouteKey()])
            ->fillForm([
                'items' => [
                    ['id' => $kept->id, 'title' => 'Item to keep', 'sort_order' => 0],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseMissing('task_template_items', ['id' => $removed->id]);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'title' => 'Item to remove', 'category' => 'Setup', 'task_template_item_id' => null, 'deleted_at' => null]);
        $this->assertSame(2, $project->tasks()->count());
    }

    public function test_deleting_a_whole_template_still_preserves_generated_tasks(): void
    {
        $project = Project::factory()->create();
        $template = TaskTemplate::factory()->withItems()->create();
        app(GenerateOnboardingTasksAction::class)->handle($project, $template, $this->manager);

        // Not a normal workflow (templates are deactivated), but even a hard
        // delete cascades only to items; tasks are merely unlinked.
        $template->delete();

        $this->assertDatabaseCount('task_template_items', 0);
        $this->assertSame(4, $project->tasks()->count());
        $this->assertSame(4, $project->tasks()->whereNull('task_template_item_id')->count());
    }

    public function test_an_unlinked_task_does_not_block_regeneration_of_a_re_added_item(): void
    {
        $project = Project::factory()->create();
        $template = TaskTemplate::factory()->withItems([['title' => 'GSC setup']])->create();
        $action = app(GenerateOnboardingTasksAction::class);

        $action->handle($project, $template, $this->manager);
        app(SyncTaskTemplateItemsAction::class)->handle($template, []);
        app(SyncTaskTemplateItemsAction::class)->handle($template, [['title' => 'GSC setup']]);

        // The re-added item is a new configuration row; the old task is history.
        $created = $action->handle($project, $template->fresh(), $this->manager);

        $this->assertCount(1, $created);
        $this->assertSame(2, $project->tasks()->where('title', 'GSC setup')->count());
    }
}
