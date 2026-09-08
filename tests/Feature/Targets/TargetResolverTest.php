<?php

namespace Tests\Feature\Targets;

use App\Models\Package;
use App\Models\Project;
use App\Services\MonthlyCycles\TargetResolver;
use App\Support\Targets\ResolvedTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TargetResolverTest extends TestCase
{
    use RefreshDatabase;

    protected TargetResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(TargetResolver::class);
    }

    public function test_project_override_wins_over_package_default(): void
    {
        $growth = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 50],
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 8],
        ])->create(['name' => 'Growth+']);
        $casa = Project::factory()->withPackage($growth)->create(['name' => 'Casa Botanica']);
        $casa->targetOverrides()->create(['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 40]);

        $resolved = $this->resolver->resolve($casa);

        $backlinks = $resolved->firstWhere('targetKey', 'backlinks');

        $this->assertInstanceOf(ResolvedTarget::class, $backlinks);
        $this->assertSame(50, $backlinks->packageValue);
        $this->assertSame(40, $backlinks->overrideValue);
        $this->assertSame(40, $backlinks->resolvedValue());
        $this->assertTrue($backlinks->isOverridden());
        $this->assertSame('Backlinks', $backlinks->label);
    }

    public function test_non_overridden_targets_use_the_package_value(): void
    {
        $growth = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 50],
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 8],
        ])->create();
        $project = Project::factory()->withPackage($growth)->create();
        $project->targetOverrides()->create(['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 40]);

        $blogs = $this->resolver->resolve($project)->firstWhere('targetKey', 'blogs');

        $this->assertSame(8, $blogs->packageValue);
        $this->assertNull($blogs->overrideValue);
        $this->assertSame(8, $blogs->resolvedValue());
        $this->assertFalse($blogs->isOverridden());

        $this->assertSame(['backlinks' => 40, 'blogs' => 8], $this->resolver->resolveValues($project));
    }

    public function test_package_target_order_is_preserved(): void
    {
        $package = Package::factory()->withTargets([
            ['target_key' => 'pages_optimized', 'label' => 'Pages Optimised', 'target_value' => 8, 'sort_order' => 3],
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 50, 'sort_order' => 0],
            ['target_key' => 'guest_posts', 'label' => 'Guest Posts', 'target_value' => 8, 'sort_order' => 2],
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 8, 'sort_order' => 1],
        ])->create();
        $project = Project::factory()->withPackage($package)->create();

        $this->assertSame(
            ['backlinks', 'blogs', 'guest_posts', 'pages_optimized'],
            $this->resolver->resolve($project)->map(fn (ResolvedTarget $target): string => $target->targetKey)->all(),
        );
    }

    public function test_project_without_package_resolves_to_no_targets(): void
    {
        $project = Project::factory()->create();

        $this->assertTrue($this->resolver->resolve($project)->isEmpty());
        $this->assertSame([], $this->resolver->resolveValues($project));
    }

    public function test_inactive_package_still_resolves(): void
    {
        $package = Package::factory()->inactive()->withTargets()->create();
        $project = Project::factory()->withPackage($package)->create();
        $project->targetOverrides()->create(['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 6]);

        $this->assertSame(
            ['backlinks' => 50, 'blogs' => 6, 'guest_posts' => 8, 'pages_optimized' => 8],
            $this->resolver->resolveValues($project),
        );
    }

    public function test_overrides_for_keys_the_package_no_longer_defines_are_ignored(): void
    {
        $package = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 50],
        ])->create();
        $project = Project::factory()->withPackage($package)->create();
        $project->targetOverrides()->create(['target_key' => 'ghost', 'label' => 'Ghost', 'target_value' => 1]);

        $this->assertSame(['backlinks' => 50], $this->resolver->resolveValues($project));
    }

    public function test_resolved_target_exposes_metadata_as_an_array(): void
    {
        $package = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 50, 'sort_order' => 4],
        ])->create();
        $project = Project::factory()->withPackage($package)->create();
        $project->targetOverrides()->create(['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 40]);

        $this->assertSame([
            'target_key' => 'backlinks',
            'label' => 'Backlinks',
            'package_value' => 50,
            'override_value' => 40,
            'resolved_value' => 40,
            'sort_order' => 4,
        ], $this->resolver->resolve($project)->first()->toArray());
    }
}
