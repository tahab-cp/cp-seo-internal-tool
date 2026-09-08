<?php

namespace Tests\Feature\Backlinks;

use App\Actions\Backlinks\CreateBacklinkAction;
use App\Actions\Backlinks\SetBacklinkStatusAction;
use App\Actions\Backlinks\UpdateBacklinkAction;
use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Enums\BacklinkStatus;
use App\Enums\BacklinkType;
use App\Exceptions\InactiveUserAssignmentException;
use App\Exceptions\UnauthorizedProjectUserException;
use App\Models\Backlink;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class BacklinkModelTest extends TestCase
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
    protected function create(array $overrides = [], ?User $by = null): Backlink
    {
        return app(CreateBacklinkAction::class)->handle($this->project, $overrides + [
            'monthly_cycle_id' => $this->september->id,
            'published_url' => 'https://blog.example/post',
            'type' => 'citation',
            'status' => 'live',
        ], $by ?? $this->manager);
    }

    public function test_relationships_and_enums(): void
    {
        $backlink = $this->create([
            'anchor_text' => 'luxury villas',
            'target_url' => 'https://casa.example/villas',
            'type' => 'guest_post',
            'status' => 'submitted',
            'published_date' => '2026-09-10',
            'domain_authority' => 45,
            'domain_rating' => 50,
            'spam_score' => 2,
            'notes' => 'Outreach via email',
        ]);

        $this->assertTrue($backlink->project->is($this->project));
        $this->assertTrue($backlink->monthlyCycle->is($this->september));
        $this->assertTrue($backlink->createdBy->is($this->manager));
        $this->assertTrue($this->project->backlinks->contains($backlink));
        $this->assertTrue($this->september->backlinks->contains($backlink));
        $this->assertSame(BacklinkType::GuestPost, $backlink->type);
        $this->assertSame(BacklinkStatus::Submitted, $backlink->status);
        $this->assertSame('2026-09-10', $backlink->published_date->toDateString());
        $this->assertSame(45, $backlink->domain_authority);
        $this->assertSame(1, $this->project->backlinks()->count());

        $this->assertSame(
            ['guest_post', 'citation', 'profile', 'forum', 'blog_comment', 'directory', 'outreach', 'other'],
            array_map(fn (BacklinkType $t): string => $t->value, BacklinkType::cases()),
        );
        $this->assertSame(
            ['planned', 'submitted', 'live', 'rejected', 'removed'],
            array_map(fn (BacklinkStatus $s): string => $s->value, BacklinkStatus::cases()),
        );
        $this->assertDatabaseHas('backlinks', ['id' => $backlink->id, 'type' => 'guest_post', 'status' => 'submitted']);
    }

    public function test_the_cycle_must_belong_to_the_same_project(): void
    {
        $foreignCycle = MonthlyCycle::factory()->create();

        try {
            $this->create(['monthly_cycle_id' => $foreignCycle->id]);
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('does not belong to project "Casa Botanica"', $exception->getMessage());
        }

        $this->assertDatabaseCount('backlinks', 0);

        $backlink = $this->create();

        $this->expectException(InvalidArgumentException::class);
        app(UpdateBacklinkAction::class)->handle($backlink, ['monthly_cycle_id' => $foreignCycle->id]);
    }

    public function test_the_creator_must_be_active_and_able_to_access_the_project(): void
    {
        $inactive = User::factory()->seoManager()->inactive()->create();
        $outsider = User::factory()->seoExecutive()->create();
        $member = User::factory()->seoExecutive()->create();
        $this->project->teamMembers()->attach($member);

        try {
            $this->create([], $inactive);
            $this->fail('Expected InactiveUserAssignmentException.');
        } catch (InactiveUserAssignmentException) {
            $this->addToAssertionCount(1);
        }

        try {
            $this->create([], $outsider);
            $this->fail('Expected UnauthorizedProjectUserException.');
        } catch (UnauthorizedProjectUserException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseCount('backlinks', 0);
        $this->assertTrue($this->create([], $member)->createdBy->is($member));
    }

    public function test_url_validation(): void
    {
        foreach ([
            ['published_url' => ''],
            ['published_url' => null],
            ['published_url' => 'not a url'],
            ['published_url' => 'ftp://files.example/x'],
            ['published_url' => 'https://ok.example/', 'target_url' => 'nope'],
            ['published_url' => 'https://ok.example/'.str_repeat('x', 500)],
        ] as $attributes) {
            try {
                $this->create($attributes);
                $this->fail('Expected InvalidArgumentException for '.json_encode($attributes));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertDatabaseCount('backlinks', 0);

        $backlink = $this->create(['target_url' => '', 'anchor_text' => '  ']);
        $this->assertNull($backlink->target_url);
        $this->assertNull($backlink->anchor_text);
    }

    public function test_metric_validation(): void
    {
        $backlink = $this->create(['domain_authority' => null, 'domain_rating' => '', 'spam_score' => '0']);

        $this->assertNull($backlink->domain_authority);
        $this->assertNull($backlink->domain_rating);
        $this->assertSame(0, $backlink->spam_score);

        foreach ([
            ['domain_authority' => -1],
            ['domain_authority' => 101],
            ['domain_rating' => 150],
            ['spam_score' => 2.5],
            ['spam_score' => 'high'],
        ] as $attributes) {
            try {
                $this->create($attributes);
                $this->fail('Expected InvalidArgumentException for '.json_encode($attributes));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(100, $this->create(['domain_authority' => 100])->domain_authority);
        $this->expectException(InvalidArgumentException::class);
        $this->create(['type' => 'sponsorship']);
    }

    public function test_the_same_url_may_be_recorded_more_than_once(): void
    {
        $this->create(['published_url' => 'https://directory.example/listing']);
        $this->create(['published_url' => 'https://directory.example/listing', 'type' => 'directory']);

        $this->assertSame(2, $this->project->backlinks()->where('published_url', 'https://directory.example/listing')->count());
    }

    public function test_removed_status_is_the_normal_retirement_and_never_soft_deletes(): void
    {
        $backlink = $this->create();

        app(SetBacklinkStatusAction::class)->handle($backlink, BacklinkStatus::Removed);

        $this->assertSame(BacklinkStatus::Removed, $backlink->fresh()->status);
        $this->assertNotSoftDeleted($backlink);
        $this->assertFalse($backlink->fresh()->isLive());

        foreach ([User::factory()->superAdmin()->create(), $this->manager, User::factory()->seoExecutive()->create()] as $user) {
            $this->assertFalse($user->can('delete', $backlink));
            $this->assertFalse($user->can('forceDelete', $backlink));
            $this->assertFalse($user->can('restore', $backlink));
        }
    }

    public function test_update_action_changes_fields_and_keeps_project_and_creator(): void
    {
        $backlink = $this->create();
        $october = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 10));

        app(UpdateBacklinkAction::class)->handle($backlink, [
            'monthly_cycle_id' => $october->id,
            'published_url' => 'https://blog.example/updated',
            'anchor_text' => 'updated anchor',
            'target_url' => 'https://casa.example/new',
            'type' => 'outreach',
            'status' => 'submitted',
            'published_date' => '2026-10-01',
            'domain_authority' => 60,
            'domain_rating' => null,
            'spam_score' => 5,
            'notes' => 'moved to October',
        ]);

        $fresh = $backlink->fresh();

        $this->assertSame($october->id, $fresh->monthly_cycle_id);
        $this->assertSame('https://blog.example/updated', $fresh->published_url);
        $this->assertSame(BacklinkType::Outreach, $fresh->type);
        $this->assertSame(BacklinkStatus::Submitted, $fresh->status);
        $this->assertSame('2026-10-01', $fresh->published_date->toDateString());
        $this->assertSame(60, $fresh->domain_authority);
        $this->assertNull($fresh->domain_rating);
        $this->assertSame($this->project->id, $fresh->project_id);
        $this->assertSame($this->manager->id, $fresh->created_by);
    }
}
