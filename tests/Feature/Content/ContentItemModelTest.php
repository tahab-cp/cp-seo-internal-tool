<?php

namespace Tests\Feature\Content;

use App\Actions\Content\CreateContentItemAction;
use App\Actions\Content\SetContentStatusAction;
use App\Actions\Content\UpdateContentItemAction;
use App\Actions\Keywords\SetKeywordStatusAction;
use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Enums\KeywordStatus;
use App\Exceptions\InactiveUserAssignmentException;
use App\Exceptions\UnauthorizedProjectUserException;
use App\Models\ContentItem;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class ContentItemModelTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected Project $project;

    protected MonthlyCycle $september;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->manager = User::factory()->seoManager()->create();
        $this->project = Project::factory()->create(['name' => 'Casa Botanica']);
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function create(array $overrides = []): ContentItem
    {
        return app(CreateContentItemAction::class)->handle($this->project, $overrides + [
            'title' => 'SEO pricing guide',
            'content_type' => 'blog',
            'status' => 'planned',
        ]);
    }

    public function test_relationships_and_enums(): void
    {
        $keyword = Keyword::factory()->forProject($this->project)->create();
        $projectLevel = $this->create();
        $monthly = $this->create([
            'title' => 'September post',
            'monthly_cycle_id' => $this->september->id,
            'target_keyword_id' => $keyword->id,
            'assigned_user_id' => $this->manager->id,
            'planned_publish_date' => '2026-09-20',
            'notes' => 'Draft in Google Docs',
        ]);

        $this->assertTrue($projectLevel->project->is($this->project));
        $this->assertNull($projectLevel->monthly_cycle_id);
        $this->assertTrue($projectLevel->isProjectLevel());

        $this->assertTrue($monthly->monthlyCycle->is($this->september));
        $this->assertTrue($monthly->targetKeyword->is($keyword));
        $this->assertTrue($monthly->assignee->is($this->manager));
        $this->assertSame('2026-09-20', $monthly->planned_publish_date->toDateString());
        $this->assertSame(2, $this->project->contentItems()->count());
        $this->assertSame(1, $this->september->contentItems()->count());
        $this->assertTrue($keyword->contentItems->contains($monthly));

        $this->assertSame(
            ['blog', 'landing_page', 'service_page', 'location_page', 'guest_content', 'other'],
            array_map(fn (ContentType $t): string => $t->value, ContentType::cases()),
        );
        $this->assertSame(
            ['idea', 'planned', 'writing', 'review', 'approved', 'published', 'cancelled'],
            array_map(fn (ContentStatus $s): string => $s->value, ContentStatus::cases()),
        );
        $this->assertDatabaseHas('content_items', ['id' => $monthly->id, 'content_type' => 'blog', 'status' => 'planned']);
    }

    public function test_cycle_and_keyword_must_belong_to_the_same_project(): void
    {
        $foreignCycle = MonthlyCycle::factory()->create();
        $foreignKeyword = Keyword::factory()->create();

        foreach ([
            ['monthly_cycle_id' => $foreignCycle->id],
            ['target_keyword_id' => $foreignKeyword->id],
        ] as $attributes) {
            try {
                $this->create($attributes);
                $this->fail('Expected InvalidArgumentException.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('does not belong to project "Casa Botanica"', $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('content_items', 0);

        $item = $this->create();

        foreach ([
            ['monthly_cycle_id' => $foreignCycle->id],
            ['target_keyword_id' => $foreignKeyword->id],
        ] as $attributes) {
            try {
                app(UpdateContentItemAction::class)->handle($item, $attributes);
                $this->fail('Expected InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertNull($item->fresh()->monthly_cycle_id);
        $this->assertNull($item->fresh()->target_keyword_id);
    }

    public function test_assignee_rules(): void
    {
        $inactive = User::factory()->seoManager()->inactive()->create();
        $outsider = User::factory()->seoExecutive()->create();
        $member = User::factory()->seoExecutive()->create();
        $this->project->teamMembers()->attach($member);

        try {
            $this->create(['assigned_user_id' => $inactive->id]);
            $this->fail('Expected InactiveUserAssignmentException.');
        } catch (InactiveUserAssignmentException) {
            $this->addToAssertionCount(1);
        }

        try {
            $this->create(['assigned_user_id' => $outsider->id]);
            $this->fail('Expected UnauthorizedProjectUserException.');
        } catch (UnauthorizedProjectUserException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseCount('content_items', 0);

        foreach ([$member, $this->manager, User::factory()->superAdmin()->create()] as $assignee) {
            $this->assertTrue($this->create(['title' => 'For '.$assignee->id, 'assigned_user_id' => $assignee->id])->assignee->is($assignee));
        }
    }

    public function test_an_existing_inactive_assignee_stays_referenced_and_other_edits_still_work(): void
    {
        $writer = User::factory()->seoExecutive()->create();
        $this->project->teamMembers()->attach($writer);
        $item = $this->create(['assigned_user_id' => $writer->id]);

        $writer->forceFill(['is_active' => false])->save();

        // Editing unrelated fields (assignee unchanged) is fine.
        app(UpdateContentItemAction::class)->handle($item, ['title' => 'Renamed', 'assigned_user_id' => $writer->id]);
        $this->assertSame('Renamed', $item->fresh()->title);
        $this->assertSame($writer->id, $item->fresh()->assigned_user_id);

        // Re-assigning to the inactive user afresh is refused.
        $other = $this->create(['title' => 'Other']);

        $this->expectException(InactiveUserAssignmentException::class);
        app(UpdateContentItemAction::class)->handle($other, ['assigned_user_id' => $writer->id]);
    }

    public function test_non_published_items_need_no_publish_details_but_published_items_do(): void
    {
        $draft = $this->create(['status' => 'review']);
        $this->assertNull($draft->published_at);
        $this->assertNull($draft->published_url);

        $base = ['title' => 'Published post', 'status' => 'published', 'monthly_cycle_id' => $this->september->id, 'published_at' => '2026-09-10 09:00', 'published_url' => 'https://site.example/blog/post'];

        foreach ([
            ['monthly_cycle_id' => null, 'message' => 'reporting month'],
            ['published_at' => null, 'message' => 'published date'],
            ['published_url' => null, 'message' => 'published URL'],
            ['published_url' => 'not a url', 'message' => 'valid http(s) URL'],
            ['published_url' => 'ftp://x.example/post', 'message' => 'valid http(s) URL'],
        ] as $case) {
            $message = $case['message'];
            unset($case['message']);

            try {
                $this->create($case + $base);
                $this->fail("Expected rejection for {$message}.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }

        $this->assertSame(1, ContentItem::query()->count());

        $published = $this->create($base);
        $this->assertSame('2026-09-10 09:00:00', $published->published_at->toDateTimeString());
        $this->assertSame('https://site.example/blog/post', $published->published_url);
    }

    public function test_leaving_published_clears_publish_details_and_cancelled_never_soft_deletes(): void
    {
        $item = $this->create(['status' => 'published', 'monthly_cycle_id' => $this->september->id, 'published_at' => '2026-09-10 09:00', 'published_url' => 'https://site.example/blog/post']);

        app(SetContentStatusAction::class)->handle($item, ContentStatus::Review);

        $fresh = $item->fresh();
        $this->assertSame(ContentStatus::Review, $fresh->status);
        $this->assertNull($fresh->published_at);
        $this->assertNull($fresh->published_url);

        // Forward and backward moves are fine; publishing again needs details.
        app(SetContentStatusAction::class)->handle($item, 'writing');
        app(SetContentStatusAction::class)->handle($item, 'approved');
        app(SetContentStatusAction::class)->handle($item, ContentStatus::Published, ['published_url' => 'https://site.example/blog/post-v2']);

        $fresh = $item->fresh();
        $this->assertTrue($fresh->isPublished());
        $this->assertNotNull($fresh->published_at);
        $this->assertSame('https://site.example/blog/post-v2', $fresh->published_url);

        app(SetContentStatusAction::class)->handle($item, ContentStatus::Cancelled);
        $this->assertSame(ContentStatus::Cancelled, $item->fresh()->status);
        $this->assertNotSoftDeleted($item);

        app(SetContentStatusAction::class)->handle($item, 'planned');
        $this->assertSame(ContentStatus::Planned, $item->fresh()->status);

        foreach ([User::factory()->superAdmin()->create(), $this->manager] as $user) {
            $this->assertFalse($user->can('delete', $item));
            $this->assertFalse($user->can('forceDelete', $item));
        }
    }

    public function test_keyword_status_changes_do_not_destroy_content(): void
    {
        $keyword = Keyword::factory()->forProject($this->project)->create();
        $item = $this->create(['target_keyword_id' => $keyword->id]);

        app(SetKeywordStatusAction::class)->handle($keyword, KeywordStatus::Archived);
        $this->assertTrue($item->fresh()->targetKeyword->is($keyword));

        $keyword->delete();
        $this->assertTrue($item->fresh()->targetKeyword->is($keyword));
        $this->assertDatabaseHas('content_items', ['id' => $item->id, 'deleted_at' => null]);
    }

    public function test_validation_of_title_type_and_status(): void
    {
        foreach ([
            ['title' => ''],
            ['title' => str_repeat('t', 256)],
            ['content_type' => 'video'],
            ['status' => 'live'],
        ] as $attributes) {
            try {
                $this->create($attributes);
                $this->fail('Expected InvalidArgumentException for '.json_encode($attributes));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertDatabaseCount('content_items', 0);
    }
}
