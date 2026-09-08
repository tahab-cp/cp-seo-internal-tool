<?php

namespace Tests\Feature\Packages;

use App\Actions\Packages\CreatePackageAction;
use App\Actions\Packages\SetPackageActiveStatusAction;
use App\Actions\Packages\SyncPackageTargetsAction;
use App\Models\Package;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class PackageModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_package_can_have_multiple_targets_in_order(): void
    {
        $package = Package::factory()->withTargets()->create();

        $this->assertSame(4, $package->targets()->count());
        $this->assertSame(['backlinks', 'blogs', 'guest_posts', 'pages_optimized'], $package->targetKeys());
    }

    public function test_target_key_is_unique_within_one_package(): void
    {
        $package = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 50],
        ])->create();

        $this->expectException(UniqueConstraintViolationException::class);

        $package->targets()->create(['target_key' => 'backlinks', 'label' => 'Again', 'target_value' => 1]);
    }

    public function test_two_packages_may_use_the_same_target_key(): void
    {
        $starter = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 20],
        ])->create();
        $growth = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 50],
        ])->create();

        $this->assertSame(20, $starter->targets->first()->target_value);
        $this->assertSame(50, $growth->targets->first()->target_value);
        $this->assertDatabaseCount('package_targets', 2);
    }

    public function test_create_package_action_validates_targets_in_the_domain_layer(): void
    {
        foreach ([
            [['target_key' => 'Bad Key', 'label' => 'x', 'target_value' => 1]],
            [['target_key' => 'blogs', 'label' => '', 'target_value' => 1]],
            [['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => -5]],
            [['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 1.5]],
            [
                ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 1],
                ['target_key' => 'blogs', 'label' => 'Blogs again', 'target_value' => 2],
            ],
        ] as $targets) {
            try {
                app(CreatePackageAction::class)->handle(['name' => 'Bad'], $targets);
                $this->fail('Expected InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertDatabaseCount('packages', 0);
    }

    public function test_sync_targets_prunes_project_overrides_for_removed_keys(): void
    {
        $package = Package::factory()->withTargets()->create();
        $project = Project::factory()->withPackage($package)->create();
        $project->targetOverrides()->create(['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 40]);
        $project->targetOverrides()->create(['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 6]);

        app(SyncPackageTargetsAction::class)->handle($package, [
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 10],
        ]);

        $this->assertSame(['blogs'], $package->fresh()->targetKeys());
        $this->assertSame(['blogs'], $project->targetOverrides()->pluck('target_key')->all());
    }

    public function test_deactivating_keeps_projects_and_targets(): void
    {
        $package = Package::factory()->withTargets()->create();
        $project = Project::factory()->withPackage($package)->create();

        app(SetPackageActiveStatusAction::class)->handle($package, false);

        $this->assertFalse($package->fresh()->is_active);
        $this->assertSame($package->id, $project->fresh()->package_id);
        $this->assertTrue($project->fresh()->package->is($package));
        $this->assertSame(4, $package->targets()->count());
    }

    public function test_a_package_in_use_cannot_be_hard_deleted(): void
    {
        $package = Package::factory()->create();
        Project::factory()->withPackage($package)->create();

        $this->expectException(QueryException::class);

        $package->delete();
    }
}
