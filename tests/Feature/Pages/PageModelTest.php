<?php

namespace Tests\Feature\Pages;

use App\Actions\Pages\CreatePageAction;
use App\Actions\Pages\SetPageStatusAction;
use App\Actions\Pages\UpdatePageAction;
use App\Enums\PageStatus;
use App\Models\Page;
use App\Models\PageOptimization;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class PageModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_project_has_many_pages_and_a_page_belongs_to_one_project(): void
    {
        $project = Project::factory()->create();
        $pages = Page::factory()->count(3)->forProject($project)->create();

        $this->assertSame(3, $project->pages()->count());
        $this->assertTrue($pages->first()->project->is($project));
        $this->assertSame($project->id, $pages->first()->project_id);
    }

    public function test_page_url_is_unique_within_a_project_but_may_repeat_across_projects(): void
    {
        $projectA = Project::factory()->create(['name' => 'A']);
        $projectB = Project::factory()->create(['name' => 'B']);
        $url = 'https://example.com/services/seo';

        app(CreatePageAction::class)->handle($projectA, ['url' => $url]);
        app(CreatePageAction::class)->handle($projectB, ['url' => $url]);

        $this->assertSame(1, $projectA->pages()->count());
        $this->assertSame(1, $projectB->pages()->count());

        try {
            app(CreatePageAction::class)->handle($projectA, ['url' => $url]);
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('already exists in project "A"', $exception->getMessage());
        }

        $this->expectException(UniqueConstraintViolationException::class);

        Page::factory()->forProject($projectA)->create(['url' => $url]);
    }

    public function test_page_uses_the_documented_status_values_and_starts_active(): void
    {
        $this->assertSame(
            ['active', 'draft', 'redirected', 'removed'],
            array_map(fn (PageStatus $status): string => $status->value, PageStatus::cases()),
        );

        $page = app(CreatePageAction::class)->handle(Project::factory()->create(), ['url' => 'https://example.com/a']);

        $this->assertSame(PageStatus::Active, $page->fresh()->status);
        $this->assertSame('/a', $page->path);
        $this->assertDatabaseHas('pages', ['id' => $page->id, 'status' => 'active']);

        app(SetPageStatusAction::class)->handle($page, 'redirected');
        $this->assertSame(PageStatus::Redirected, $page->fresh()->status);
    }

    public function test_page_validation_in_the_domain_layer(): void
    {
        $project = Project::factory()->create();

        foreach ([
            ['url' => ''],
            ['url' => 'not a url'],
            ['url' => 'ftp://example.com/file'],
            ['url' => 'https://example.com/'.str_repeat('x', 500)],
            ['url' => 'https://example.com/ok', 'title' => str_repeat('t', 256)],
            ['url' => 'https://example.com/ok', 'page_type' => str_repeat('p', 101)],
        ] as $attributes) {
            try {
                app(CreatePageAction::class)->handle($project, $attributes);
                $this->fail('Expected InvalidArgumentException for '.json_encode($attributes));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, $project->pages()->count());
    }

    public function test_update_page_action_changes_master_data_and_respects_uniqueness(): void
    {
        $project = Project::factory()->create();
        $page = app(CreatePageAction::class)->handle($project, ['url' => 'https://example.com/a', 'title' => 'A']);
        $other = app(CreatePageAction::class)->handle($project, ['url' => 'https://example.com/b']);

        app(UpdatePageAction::class)->handle($page, ['title' => 'Renamed', 'page_type' => 'service', 'path' => '/a-new', 'status' => 'draft']);

        $fresh = $page->fresh();
        $this->assertSame('Renamed', $fresh->title);
        $this->assertSame('service', $fresh->page_type);
        $this->assertSame('/a-new', $fresh->path);
        $this->assertSame(PageStatus::Draft, $fresh->status);

        // Same URL as itself is fine; another page's URL is not.
        app(UpdatePageAction::class)->handle($page, ['url' => 'https://example.com/a']);

        $this->expectException(InvalidArgumentException::class);
        app(UpdatePageAction::class)->handle($page, ['url' => $other->url]);
    }

    public function test_a_removed_page_keeps_its_optimisation_history_and_is_not_soft_deleted(): void
    {
        $page = Page::factory()->create();
        PageOptimization::factory()->count(2)->forPage($page)->create();

        app(SetPageStatusAction::class)->handle($page, PageStatus::Removed);

        $this->assertTrue($page->fresh()->isRemoved());
        $this->assertNotSoftDeleted($page);
        $this->assertSame(2, $page->optimizations()->count());
        $this->assertDatabaseCount('page_optimizations', 2);
    }

    public function test_soft_deleting_a_page_does_not_cascade_to_its_optimisations_and_hard_delete_is_blocked(): void
    {
        $page = Page::factory()->create();
        $optimization = PageOptimization::factory()->forPage($page)->create();

        $page->delete();

        $this->assertSoftDeleted($page);
        $this->assertDatabaseHas('page_optimizations', ['id' => $optimization->id, 'page_id' => $page->id]);
        $this->assertTrue($optimization->fresh()->page->is($page));

        $this->expectException(QueryException::class);

        $page->forceDelete();
    }
}
