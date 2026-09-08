<?php

namespace Tests\Feature\Tasks;

use App\Actions\TaskTemplates\SyncTaskTemplateItemsAction;
use App\Filament\Resources\TaskTemplates\Pages\CreateTaskTemplate;
use App\Filament\Resources\TaskTemplates\Pages\EditTaskTemplate;
use App\Filament\Resources\TaskTemplates\Pages\ListTaskTemplates;
use App\Filament\Resources\TaskTemplates\TaskTemplateResource;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class TaskTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_and_seo_manager_can_manage_task_templates(): void
    {
        foreach ([
            User::factory()->superAdmin()->create(),
            User::factory()->seoManager()->create(),
        ] as $user) {
            $this->actingAs($user);
            $template = TaskTemplate::factory()->withItems()->create();

            $this->get(TaskTemplateResource::getUrl('index'))->assertOk();
            $this->get(TaskTemplateResource::getUrl('create'))->assertOk();
            $this->get(TaskTemplateResource::getUrl('edit', ['record' => $template]))->assertOk();
            $this->get('/admin')->assertSee(TaskTemplateResource::getUrl('index'));

            $this->assertTrue(TaskTemplateResource::canViewAny());
            $this->assertTrue(TaskTemplateResource::canCreate());
            $this->assertTrue(TaskTemplateResource::canEdit($template));
            $this->assertTrue($user->can('deactivate', $template));
        }
    }

    public function test_seo_executive_cannot_access_task_template_administration(): void
    {
        $executive = User::factory()->seoExecutive()->create();
        $template = TaskTemplate::factory()->create();

        $this->actingAs($executive);

        $this->get(TaskTemplateResource::getUrl('index'))->assertForbidden();
        $this->get(TaskTemplateResource::getUrl('create'))->assertForbidden();
        $this->get(TaskTemplateResource::getUrl('edit', ['record' => $template]))->assertForbidden();
        $this->get('/admin')->assertOk()->assertDontSee(TaskTemplateResource::getUrl('index'));

        Livewire::test(ListTaskTemplates::class)->assertForbidden();
        Livewire::test(CreateTaskTemplate::class)->assertForbidden();
        Livewire::test(EditTaskTemplate::class, ['record' => $template->getRouteKey()])->assertForbidden();

        $this->assertFalse($executive->can('viewAny', TaskTemplate::class));
        $this->assertFalse($executive->can('update', $template));

        $this->get(TaskTemplateResource::getUrl('index'))->assertForbidden();
        $this->assertSame(Filament::getLoginUrl(), $this->get(TaskTemplateResource::getUrl('index'))->isForbidden() ? Filament::getLoginUrl() : null);
    }

    public function test_a_template_can_be_created_with_items_through_the_form(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());

        Livewire::test(CreateTaskTemplate::class)
            ->fillForm([
                'name' => 'Standard onboarding',
                'description' => 'Every new SEO client.',
                'is_active' => true,
                'items' => [
                    ['phase' => 'Setup', 'title' => 'GSC setup', 'category' => 'Technical', 'default_due_days' => 3, 'sort_order' => 1],
                    ['phase' => 'Setup', 'title' => 'GA4 setup', 'category' => 'Technical', 'default_due_days' => 3, 'sort_order' => 0],
                    ['phase' => 'Discovery', 'title' => 'Keyword research', 'default_due_days' => null, 'sort_order' => 2],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $template = TaskTemplate::query()->where('name', 'Standard onboarding')->firstOrFail();

        $this->assertTrue($template->is_active);
        $this->assertSame(['GA4 setup', 'GSC setup', 'Keyword research'], $template->items->pluck('title')->all());
        $this->assertNull($template->items->firstWhere('title', 'Keyword research')->default_due_days);
    }

    public function test_template_validation_rejects_missing_titles_and_negative_due_days(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $component = Livewire::test(CreateTaskTemplate::class)
            ->fillForm([
                'name' => str_repeat('x', 101),
                'items' => [
                    ['title' => '', 'default_due_days' => -1, 'phase' => str_repeat('p', 101)],
                ],
            ]);

        [$first] = array_keys($component->get('data.items'));

        $component->call('create')->assertHasFormErrors([
            'name' => 'max',
            "items.{$first}.title" => 'required',
            "items.{$first}.default_due_days" => 'min',
            "items.{$first}.phase" => 'max',
        ]);

        $this->assertDatabaseCount('task_templates', 0);

        // The domain layer rejects the same input regardless of caller.
        $this->expectException(InvalidArgumentException::class);

        app(SyncTaskTemplateItemsAction::class)->handle(
            TaskTemplate::factory()->create(),
            [['title' => 'Ok', 'default_due_days' => -5]],
        );
    }

    public function test_editing_updates_existing_items_in_place_and_keeps_generated_task_links(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());
        $template = TaskTemplate::factory()->withItems([
            ['title' => 'GSC setup', 'default_due_days' => 3],
            ['title' => 'Old item'],
        ])->create(['name' => 'Onboarding']);
        [$gsc, $old] = $template->items->all();
        $generated = Task::factory()->fromTemplateItem($gsc)->create();

        Livewire::test(EditTaskTemplate::class, ['record' => $template->getRouteKey()])
            ->assertFormSet(['name' => 'Onboarding'])
            ->fillForm([
                'name' => 'Onboarding v2',
                'items' => [
                    ['id' => $gsc->id, 'title' => 'GSC setup (renamed)', 'default_due_days' => 5, 'sort_order' => 1],
                    ['title' => 'Brand new item', 'sort_order' => 0],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $template->refresh();

        $this->assertSame('Onboarding v2', $template->name);
        $this->assertSame(['Brand new item', 'GSC setup (renamed)'], $template->items->pluck('title')->all());
        $this->assertSame(5, $gsc->fresh()->default_due_days);
        $this->assertDatabaseMissing('task_template_items', ['id' => $old->id]);
        // The generated task still points at the (renamed) item and keeps its own title.
        $this->assertSame($gsc->id, $generated->fresh()->task_template_item_id);
        $this->assertSame('GSC setup', $generated->fresh()->title);
    }

    public function test_deactivate_and_activate_and_no_delete_in_the_ui(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $template = TaskTemplate::factory()->withItems()->create();

        Livewire::test(ListTaskTemplates::class)
            ->assertCanSeeTableRecords([$template])
            ->assertTableActionDoesNotExist('delete', record: $template)
            ->assertTableBulkActionDoesNotExist('delete')
            ->assertTableActionVisible('deactivate', $template)
            ->callTableAction('deactivate', $template)
            ->assertNotified('Template deactivated');

        $this->assertFalse($template->fresh()->is_active);
        $this->assertSame(4, $template->items()->count());

        Livewire::test(EditTaskTemplate::class, ['record' => $template->getRouteKey()])
            ->assertActionDoesNotExist('delete')
            ->assertActionVisible('activate')
            ->callAction('activate')
            ->assertNotified('Template activated');

        $this->assertTrue($template->fresh()->is_active);
        $this->assertFalse(User::factory()->superAdmin()->create()->can('delete', $template));
    }
}
