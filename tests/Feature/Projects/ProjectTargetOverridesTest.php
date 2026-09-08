<?php

namespace Tests\Feature\Projects;

use App\Actions\Projects\SyncProjectTargetOverridesAction;
use App\Exceptions\UnknownTargetKeyException;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectTargetOverridesTest extends TestCase
{
    use RefreshDatabase;

    public function test_seo_manager_and_super_admin_can_create_overrides_through_the_form(): void
    {
        foreach ([
            User::factory()->seoManager()->create(),
            User::factory()->superAdmin()->create(),
        ] as $user) {
            $this->actingAs($user);
            $package = Package::factory()->withTargets()->create();
            $project = Project::factory()->withPackage($package)->create();

            Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
                ->fillForm([
                    'target_overrides' => [
                        ['target_key' => 'backlinks', 'target_value' => 40],
                        ['target_key' => 'blogs', 'target_value' => 6],
                    ],
                ])
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertSame(
                ['backlinks' => 40, 'blogs' => 6],
                $project->targetOverrides()->orderBy('target_key')->pluck('target_value', 'target_key')->all(),
            );
            $this->assertSame('Backlinks', $project->targetOverrides()->where('target_key', 'backlinks')->value('label'));
            $this->assertTrue($user->can('manageTargets', $project));
        }
    }

    public function test_editing_overrides_updates_and_removes_them(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());
        $package = Package::factory()->withTargets()->create();
        $project = Project::factory()->withPackage($package)->create();
        $project->targetOverrides()->create(['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 40]);
        $project->targetOverrides()->create(['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 6]);

        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
            ->fillForm([
                'target_overrides' => [
                    ['target_key' => 'backlinks', 'target_value' => 45],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['backlinks' => 45], $project->targetOverrides()->pluck('target_value', 'target_key')->all());
    }

    public function test_seo_executive_cannot_create_or_change_overrides(): void
    {
        $executive = User::factory()->seoExecutive()->create();
        $package = Package::factory()->withTargets()->create();
        $project = Project::factory()->withPackage($package)->ownedBy($executive)->create();

        $this->actingAs($executive);

        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])->assertForbidden();

        $this->assertFalse($executive->can('manageTargets', $project));
        $this->assertSame(0, $project->targetOverrides()->count());
    }

    public function test_form_rejects_duplicate_keys_unknown_keys_and_negative_values(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $package = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 50],
        ])->create();
        $project = Project::factory()->withPackage($package)->create();

        $component = Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
            ->fillForm([
                'target_overrides' => [
                    ['target_key' => 'backlinks', 'target_value' => -1],
                    ['target_key' => 'backlinks', 'target_value' => 5],
                    ['target_key' => 'not_in_package', 'target_value' => 5],
                ],
            ]);

        [$first, $second, $third] = array_keys($component->get('data.target_overrides'));

        $component
            ->call('save')
            ->assertHasFormErrors([
                "target_overrides.{$first}.target_value" => 'min',
                // Filament's repeater-level distinct check reports under its own rule name.
                "target_overrides.{$first}.target_key",
                "target_overrides.{$second}.target_key",
                "target_overrides.{$third}.target_key" => 'in',
            ]);

        $this->assertSame(0, $project->targetOverrides()->count());
    }

    public function test_duplicate_override_for_the_same_key_is_prevented_by_the_database(): void
    {
        $project = Project::factory()->withPackage(Package::factory()->withTargets()->create())->create();
        $project->targetOverrides()->create(['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 40]);

        $this->expectException(UniqueConstraintViolationException::class);

        $project->targetOverrides()->create(['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 41]);
    }

    public function test_sync_action_rejects_keys_not_defined_by_the_package(): void
    {
        $package = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 50],
        ])->create();
        $project = Project::factory()->withPackage($package)->create();

        try {
            app(SyncProjectTargetOverridesAction::class)->handle($project, ['backlinks' => 40, 'blogs' => 8]);
            $this->fail('Expected UnknownTargetKeyException.');
        } catch (UnknownTargetKeyException $exception) {
            $this->assertStringContainsString('blogs', $exception->getMessage());
        }

        $this->assertSame(0, $project->targetOverrides()->count());
    }

    public function test_sync_action_rejects_overrides_for_a_project_without_a_package(): void
    {
        $project = Project::factory()->create();

        $this->expectException(UnknownTargetKeyException::class);

        app(SyncProjectTargetOverridesAction::class)->handle($project, ['backlinks' => 40]);
    }

    public function test_sync_action_rejects_negative_or_fractional_values(): void
    {
        $project = Project::factory()->withPackage(Package::factory()->withTargets()->create())->create();

        foreach ([['backlinks' => -1], ['backlinks' => 1.5], ['backlinks' => 'lots']] as $overrides) {
            try {
                app(SyncProjectTargetOverridesAction::class)->handle($project, $overrides);
                $this->fail('Expected InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, $project->targetOverrides()->count());
    }

    public function test_sync_action_upserts_and_removes_overrides(): void
    {
        $project = Project::factory()->withPackage(Package::factory()->withTargets()->create())->create();
        $action = app(SyncProjectTargetOverridesAction::class);

        $action->handle($project, ['backlinks' => 40, 'blogs' => '6']);
        $this->assertSame(['backlinks' => 40, 'blogs' => 6], $project->targetOverrides()->orderBy('target_key')->pluck('target_value', 'target_key')->all());

        $action->handle($project, ['blogs' => 7]);
        $this->assertSame(['blogs' => 7], $project->targetOverrides()->pluck('target_value', 'target_key')->all());

        $action->handle($project, []);
        $this->assertSame(0, $project->targetOverrides()->count());
    }
}
