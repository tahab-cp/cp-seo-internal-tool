<?php

namespace Tests\Feature\Packages;

use App\Enums\Permission;
use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Resources\Packages\Pages\CreatePackage;
use App\Filament\Resources\Packages\Pages\EditPackage;
use App\Filament\Resources\Packages\Pages\ListPackages;
use App\Models\Package;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Settings → Packages is Super Admin only, enforced through direct URLs and
 * Livewire page mounts, not just navigation.
 */
class PackageAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected(): void
    {
        $this->get(PackageResource::getUrl('index'))
            ->assertRedirect(Filament::getLoginUrl());
    }

    public function test_super_admin_can_manage_packages(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $package = Package::factory()->withTargets()->create();

        $this->get(PackageResource::getUrl('index'))->assertOk();
        $this->get(PackageResource::getUrl('create'))->assertOk();
        $this->get(PackageResource::getUrl('edit', ['record' => $package]))->assertOk();
        $this->get('/admin')->assertSee(PackageResource::getUrl('index'));

        Livewire::test(ListPackages::class)->assertOk()->assertCanSeeTableRecords([$package]);
        Livewire::test(CreatePackage::class)->assertOk();
        Livewire::test(EditPackage::class, ['record' => $package->getRouteKey()])->assertOk();

        $this->assertTrue(PackageResource::canViewAny());
        $this->assertTrue(PackageResource::canCreate());
        $this->assertTrue(PackageResource::canEdit($package));
    }

    public function test_seo_manager_cannot_access_package_administration(): void
    {
        $manager = User::factory()->seoManager()->create();
        $package = Package::factory()->create();

        $this->actingAs($manager);

        $this->assertDeniedEverywhere($package);

        $this->assertFalse($manager->can(Permission::ManagePackages->value));
        $this->assertFalse($manager->can('viewAny', Package::class));
        $this->assertFalse($manager->can('create', Package::class));
        $this->assertFalse($manager->can('update', $package));
        $this->assertFalse($manager->can('deactivate', $package));
    }

    public function test_seo_executive_cannot_access_package_administration(): void
    {
        $executive = User::factory()->seoExecutive()->create();
        $package = Package::factory()->create();

        $this->actingAs($executive);

        $this->assertDeniedEverywhere($package);

        $this->assertFalse($executive->can('viewAny', Package::class));
        $this->assertFalse($executive->can('update', $package));
    }

    public function test_nobody_may_delete_packages_through_the_policy(): void
    {
        $package = Package::factory()->create();

        foreach ([
            User::factory()->superAdmin()->create(),
            User::factory()->seoManager()->create(),
            User::factory()->seoExecutive()->create(),
        ] as $user) {
            $this->assertFalse($user->can('delete', $package));
            $this->assertFalse($user->can('deleteAny', Package::class));
        }
    }

    protected function assertDeniedEverywhere(Package $package): void
    {
        $this->get(PackageResource::getUrl('index'))->assertForbidden();
        $this->get(PackageResource::getUrl('create'))->assertForbidden();
        $this->get(PackageResource::getUrl('edit', ['record' => $package]))->assertForbidden();
        $this->get('/admin')->assertOk()->assertDontSee(PackageResource::getUrl('index'));

        Livewire::test(ListPackages::class)->assertForbidden();
        Livewire::test(CreatePackage::class)->assertForbidden();
        Livewire::test(EditPackage::class, ['record' => $package->getRouteKey()])->assertForbidden();

        $this->assertFalse(PackageResource::canViewAny());
        $this->assertFalse(PackageResource::canAccess());
    }
}
