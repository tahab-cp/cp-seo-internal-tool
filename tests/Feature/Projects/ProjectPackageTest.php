<?php

namespace Tests\Feature\Projects;

use App\Actions\Projects\ChangeProjectPackageAction;
use App\Enums\ProjectStatus;
use App\Exceptions\InactivePackageAssignmentException;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Client;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectPackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_seo_manager_can_assign_an_active_package_to_a_project(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());
        $package = Package::factory()->withTargets()->create(['name' => 'Growth+']);
        $project = Project::factory()->create();

        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
            ->fillForm(['package_id' => $package->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($project->fresh()->package->is($package));
    }

    public function test_a_new_project_requires_a_package(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());

        Livewire::test(CreateProject::class)
            ->fillForm([
                'client_id' => Client::factory()->create()->id,
                'name' => 'No package',
                'website_url' => 'https://nopackage.example',
                'status' => ProjectStatus::Active->value,
                'package_id' => null,
            ])
            ->call('create')
            ->assertHasFormErrors(['package_id' => 'required']);

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_a_project_can_be_created_with_a_package_and_overrides(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $package = Package::factory()->withTargets()->create();

        Livewire::test(CreateProject::class)
            ->fillForm([
                'client_id' => Client::factory()->create()->id,
                'name' => 'Casa Botanica',
                'website_url' => 'https://casabotanica.example',
                'status' => ProjectStatus::Onboarding->value,
                'package_id' => $package->id,
                'target_overrides' => [
                    ['target_key' => 'backlinks', 'target_value' => 40],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $project = Project::query()->where('name', 'Casa Botanica')->firstOrFail();

        $this->assertTrue($project->package->is($package));
        $this->assertSame(['backlinks' => 40], $project->targetOverrides()->pluck('target_value', 'target_key')->all());
    }

    public function test_seo_executive_cannot_change_a_project_package(): void
    {
        $executive = User::factory()->seoExecutive()->create();
        $package = Package::factory()->create();
        $project = Project::factory()->ownedBy($executive)->create();

        $this->actingAs($executive);

        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])->assertForbidden();

        $this->assertFalse($executive->can('assignPackage', $project));
        $this->assertNull($project->fresh()->package_id);
        $this->assertNotNull($package);
    }

    public function test_an_inactive_package_is_unavailable_for_new_assignment(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());
        $inactive = Package::factory()->inactive()->create();
        $project = Project::factory()->create();

        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
            ->fillForm(['package_id' => $inactive->id])
            ->call('save')
            ->assertHasFormErrors(['package_id']);

        $this->assertNull($project->fresh()->package_id);

        // Domain layer refuses too, and refuses unknown packages.
        foreach ([$inactive->id, 999999] as $packageId) {
            try {
                app(ChangeProjectPackageAction::class)->handle($project, $packageId);
                $this->fail('Expected InactivePackageAssignmentException.');
            } catch (InactivePackageAssignmentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertNull($project->fresh()->package_id);
    }

    public function test_a_project_on_a_package_that_became_inactive_keeps_it_and_stays_editable(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());
        $package = Package::factory()->withTargets()->create(['name' => 'Legacy+']);
        $project = Project::factory()->withPackage($package)->create(['name' => 'Old']);
        $project->targetOverrides()->create(['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 6]);

        $package->forceFill(['is_active' => false])->save();

        // View page still shows the package (marked inactive) and resolved targets.
        $this->get(ProjectResource::getUrl('view', ['record' => $project]))
            ->assertOk()
            ->assertSee('Legacy+ (inactive)');

        Livewire::test(ListProjects::class)
            ->assertCanSeeTableRecords([$project])
            ->assertSee('Inactive package');

        // Edit page opens with the inactive package selected and saves unrelated changes without error.
        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
            ->assertFormSet(['package_id' => $package->id])
            ->fillForm(['name' => 'Renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $project->refresh();

        $this->assertSame('Renamed', $project->name);
        $this->assertSame($package->id, $project->package_id);
        $this->assertSame(['blogs' => 6], $project->targetOverrides()->pluck('target_value', 'target_key')->all());
    }

    public function test_changing_the_package_clears_stale_overrides(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());
        $old = Package::factory()->withTargets()->create();
        $new = Package::factory()->withTargets()->create();
        $project = Project::factory()->withPackage($old)->create();
        $project->targetOverrides()->create(['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 40]);

        // Through the form.
        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
            ->fillForm(['package_id' => $new->id, 'target_overrides' => []])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($project->fresh()->package->is($new));
        $this->assertSame(0, $project->targetOverrides()->count());

        // Through the domain action.
        $project->refresh();
        $project->targetOverrides()->create(['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 5]);
        app(ChangeProjectPackageAction::class)->handle($project, $old->id);

        $this->assertTrue($project->fresh()->package->is($old));
        $this->assertSame(0, $project->targetOverrides()->count());

        // Keeping the same package leaves overrides alone.
        $project->targetOverrides()->create(['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 5]);
        app(ChangeProjectPackageAction::class)->handle($project, $old->id);

        $this->assertSame(1, $project->targetOverrides()->count());
    }

    public function test_changing_the_package_and_setting_new_overrides_in_one_save_keeps_only_the_new_ones(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $old = Package::factory()->withTargets()->create();
        $new = Package::factory()->withTargets([
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 12],
        ])->create();
        $project = Project::factory()->withPackage($old)->create();
        $project->targetOverrides()->create(['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 40]);

        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
            ->fillForm([
                'package_id' => $new->id,
                'target_overrides' => [
                    ['target_key' => 'blogs', 'target_value' => 10],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['blogs' => 10], $project->targetOverrides()->pluck('target_value', 'target_key')->all());
    }

    public function test_the_project_list_shows_and_filters_by_package(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $growth = Package::factory()->create(['name' => 'Growth+']);
        $starter = Package::factory()->create(['name' => 'Starter']);
        $a = Project::factory()->withPackage($growth)->create();
        $b = Project::factory()->withPackage($starter)->create();
        $c = Project::factory()->create();

        Livewire::test(ListProjects::class)
            ->assertCanSeeTableRecords([$a, $b, $c])
            ->assertSee('Growth+')
            ->assertSee('No package')
            ->filterTable('package_id', $growth->id)
            ->assertCanSeeTableRecords([$a])
            ->assertCanNotSeeTableRecords([$b, $c]);
    }

    public function test_the_view_page_shows_the_package_and_resolved_targets(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());
        $package = Package::factory()->withTargets()->create(['name' => 'Growth+']);
        $project = Project::factory()->withPackage($package)->create();
        $project->targetOverrides()->create(['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 40]);

        Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
            ->assertOk()
            ->assertSee('Growth+')
            ->assertSee('Backlinks')
            ->assertSee('40')
            ->assertSee('Pages Optimised');
    }
}
