<?php

namespace Tests\Feature\Packages;

use App\Filament\Resources\Packages\Pages\CreatePackage;
use App\Filament\Resources\Packages\Pages\EditPackage;
use App\Filament\Resources\Packages\Pages\ListPackages;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PackageManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->superAdmin()->create());
    }

    public function test_super_admin_can_create_a_package_with_targets(): void
    {
        Livewire::test(CreatePackage::class)
            ->fillForm([
                'name' => 'Growth+',
                'description' => 'Mid-tier monthly package.',
                'is_active' => true,
                'targets' => [
                    ['label' => 'Backlinks', 'target_key' => 'backlinks', 'target_value' => 50, 'sort_order' => 0],
                    ['label' => 'Blogs', 'target_key' => 'blogs', 'target_value' => 8, 'sort_order' => 1],
                    ['label' => 'Guest Posts', 'target_key' => 'guest_posts', 'target_value' => 8, 'sort_order' => 2],
                    ['label' => 'Pages Optimised', 'target_key' => 'pages_optimized', 'target_value' => 8, 'sort_order' => 3],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $package = Package::query()->where('name', 'Growth+')->firstOrFail();

        $this->assertTrue($package->is_active);
        $this->assertSame(['backlinks', 'blogs', 'guest_posts', 'pages_optimized'], $package->targetKeys());
        $this->assertSame(50, $package->targets->firstWhere('target_key', 'backlinks')->target_value);
    }

    public function test_package_validation_rejects_bad_keys_duplicates_and_negative_values(): void
    {
        $component = Livewire::test(CreatePackage::class)
            ->fillForm([
                'name' => str_repeat('x', 101),
                'targets' => [
                    ['label' => '', 'target_key' => 'Bad Key!', 'target_value' => -1],
                    ['label' => 'Dup', 'target_key' => 'blogs', 'target_value' => 1],
                    ['label' => 'Dup again', 'target_key' => 'blogs', 'target_value' => 2],
                ],
            ]);

        [$first, $second, $third] = array_keys($component->get('data.targets'));

        $component
            ->call('create')
            ->assertHasFormErrors([
                'name' => 'max',
                "targets.{$first}.label" => 'required',
                "targets.{$first}.target_key" => 'regex',
                "targets.{$first}.target_value" => 'min',
                // Filament's repeater-level distinct check reports under its own rule name.
                "targets.{$second}.target_key",
                "targets.{$third}.target_key",
            ]);

        $this->assertDatabaseCount('packages', 0);
    }

    public function test_editing_a_package_adds_updates_and_removes_targets(): void
    {
        $package = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 50],
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 8],
        ])->create(['name' => 'Starter']);

        Livewire::test(EditPackage::class, ['record' => $package->getRouteKey()])
            ->assertFormSet(['name' => 'Starter'])
            ->fillForm([
                'name' => 'Starter v2',
                'targets' => [
                    ['label' => 'Backlinks', 'target_key' => 'backlinks', 'target_value' => 60, 'sort_order' => 1],
                    ['label' => 'Guest Posts', 'target_key' => 'guest_posts', 'target_value' => 4, 'sort_order' => 0],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $package->refresh();

        $this->assertSame('Starter v2', $package->name);
        $this->assertSame(['guest_posts', 'backlinks'], $package->targetKeys());
        $this->assertSame(60, $package->targets->firstWhere('target_key', 'backlinks')->target_value);
        $this->assertDatabaseMissing('package_targets', ['package_id' => $package->id, 'target_key' => 'blogs']);
    }

    public function test_deactivate_and_activate_from_the_list(): void
    {
        $package = Package::factory()->withTargets()->create();
        $project = Project::factory()->withPackage($package)->create();

        Livewire::test(ListPackages::class)
            ->assertTableActionVisible('deactivate', $package)
            ->assertTableActionHidden('activate', $package)
            ->callTableAction('deactivate', $package)
            ->assertNotified('Package deactivated');

        $package->refresh();

        $this->assertFalse($package->is_active);
        $this->assertSame($package->id, $project->fresh()->package_id);
        $this->assertSame(4, $package->targets()->count());

        Livewire::test(ListPackages::class)
            ->assertTableActionHidden('deactivate', $package)
            ->assertTableActionVisible('activate', $package)
            ->callTableAction('activate', $package)
            ->assertNotified('Package activated');

        $this->assertTrue($package->fresh()->is_active);
    }

    public function test_deactivate_is_available_on_the_edit_page(): void
    {
        $package = Package::factory()->create();

        Livewire::test(EditPackage::class, ['record' => $package->getRouteKey()])
            ->assertActionVisible('deactivate')
            ->callAction('deactivate')
            ->assertNotified('Package deactivated');

        $this->assertFalse($package->fresh()->is_active);
    }

    public function test_there_is_no_delete_action_anywhere_in_the_package_ui(): void
    {
        $package = Package::factory()->create();

        Livewire::test(ListPackages::class)
            ->assertTableActionDoesNotExist('delete', record: $package)
            ->assertTableBulkActionDoesNotExist('delete');

        Livewire::test(EditPackage::class, ['record' => $package->getRouteKey()])
            ->assertActionDoesNotExist('delete');
    }

    public function test_the_list_shows_targets_summary_and_counts(): void
    {
        $package = Package::factory()->withTargets()->create(['name' => 'Growth+']);
        Project::factory()->count(2)->withPackage($package)->create();

        Livewire::test(ListPackages::class)
            ->assertCanSeeTableRecords([$package])
            ->assertSee('Backlinks 50')
            ->assertSee('Pages Optimised 8');
    }
}
