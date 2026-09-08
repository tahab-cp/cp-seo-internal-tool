<?php

namespace Tests\Feature\Keywords;

use App\Actions\Keywords\CreateKeywordAction;
use App\Actions\Keywords\SetKeywordStatusAction;
use App\Actions\Keywords\UpdateKeywordAction;
use App\Actions\Pages\SetPageStatusAction;
use App\Enums\KeywordIntent;
use App\Enums\KeywordRole;
use App\Enums\KeywordStatus;
use App\Enums\PageStatus;
use App\Exceptions\DuplicateKeywordException;
use App\Models\Keyword;
use App\Models\Page;
use App\Models\Project;
use App\Models\RankingSnapshot;
use App\Support\Keywords\KeywordNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class KeywordModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_project_has_many_keywords_and_a_keyword_belongs_to_one_project(): void
    {
        $project = Project::factory()->create();
        $keywords = Keyword::factory()->count(3)->forProject($project)->create();

        $this->assertSame(3, $project->keywords()->count());
        $this->assertTrue($keywords->first()->project->is($project));
    }

    public function test_keywords_may_target_a_page_and_several_may_share_it(): void
    {
        $page = Page::factory()->create();
        [$a, $b] = Keyword::factory()->count(2)->targeting($page)->create();
        $orphan = Keyword::factory()->forProject($page->project)->create();

        $this->assertTrue($a->targetPage->is($page));
        $this->assertTrue($b->targetPage->is($page));
        $this->assertNull($orphan->targetPage);
        $this->assertSame(2, $page->keywords()->count());
    }

    public function test_a_keyword_cannot_target_a_page_of_another_project(): void
    {
        $project = Project::factory()->create(['name' => 'Casa Botanica']);
        $foreignPage = Page::factory()->create();

        try {
            app(CreateKeywordAction::class)->handle($project, ['keyword' => 'luxury villas', 'target_page_id' => $foreignPage->id]);
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('does not belong to project "Casa Botanica"', $exception->getMessage());
        }

        $keyword = Keyword::factory()->forProject($project)->create();

        $this->expectException(InvalidArgumentException::class);
        app(UpdateKeywordAction::class)->handle($keyword, ['target_page_id' => $foreignPage->id]);
    }

    public function test_enums_match_the_documented_values(): void
    {
        $this->assertSame(['active', 'paused', 'archived'], array_map(fn (KeywordStatus $s): string => $s->value, KeywordStatus::cases()));
        $this->assertSame(
            ['informational', 'navigational', 'commercial', 'transactional', 'local', 'unknown'],
            array_map(fn (KeywordIntent $i): string => $i->value, KeywordIntent::cases()),
        );
        $this->assertSame(['primary', 'secondary'], array_map(fn (KeywordRole $r): string => $r->value, KeywordRole::cases()));

        $keyword = app(CreateKeywordAction::class)->handle(Project::factory()->create(), [
            'keyword' => 'seo agency',
            'search_intent' => 'commercial',
            'keyword_role' => 'primary',
        ]);

        $this->assertSame(KeywordStatus::Active, $keyword->fresh()->status);
        $this->assertSame(KeywordIntent::Commercial, $keyword->fresh()->search_intent);
        $this->assertSame(KeywordRole::Primary, $keyword->fresh()->keyword_role);
        $this->assertFalse($keyword->is_branded);
    }

    public function test_normalised_duplicates_are_rejected_within_a_project_but_allowed_across_projects(): void
    {
        $projectA = Project::factory()->create(['name' => 'A']);
        $projectB = Project::factory()->create();
        $action = app(CreateKeywordAction::class);

        $original = $action->handle($projectA, ['keyword' => 'SEO Agency London']);

        // The user-facing text is stored exactly as typed.
        $this->assertSame('SEO Agency London', $original->keyword);
        $this->assertSame('seo agency london', $original->keyword_normalized);
        $this->assertSame('', $original->location_normalized);

        foreach (['SEO Agency London', ' seo agency london ', "seo\tagency   LONDON", 'Seo Agency London'] as $duplicate) {
            try {
                $action->handle($projectA, ['keyword' => $duplicate]);
                $this->fail("Expected [{$duplicate}] to be a duplicate.");
            } catch (DuplicateKeywordException $exception) {
                $this->assertStringContainsString('already tracked in project "A"', $exception->getMessage());
            }
        }

        // Different location is a different tracked keyword; case/space in location is normalised too.
        $action->handle($projectA, ['keyword' => 'SEO Agency London', 'location' => 'Manchester']);

        $this->expectException(DuplicateKeywordException::class);
        $action->handle($projectA, ['keyword' => 'seo agency london', 'location' => ' MANCHESTER ']);
    }

    public function test_equivalent_keyword_may_exist_in_another_project_and_the_database_backs_the_rule(): void
    {
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();

        app(CreateKeywordAction::class)->handle($projectA, ['keyword' => 'luxury villas', 'location' => 'London']);
        app(CreateKeywordAction::class)->handle($projectB, ['keyword' => ' Luxury  Villas ', 'location' => 'london']);

        $this->assertSame(1, $projectA->keywords()->count());
        $this->assertSame(1, $projectB->keywords()->count());

        $this->expectException(UniqueConstraintViolationException::class);

        Keyword::factory()->forProject($projectA)->create(['keyword' => 'LUXURY VILLAS', 'location' => 'LONDON']);
    }

    public function test_the_normalizer_is_deterministic(): void
    {
        $this->assertSame('seo agency london', KeywordNormalizer::keyword("  SEO   Agency\nLondon "));
        $this->assertSame('', KeywordNormalizer::location(null));
        $this->assertSame('', KeywordNormalizer::location('   '));
        $this->assertSame('new york', KeywordNormalizer::location(' New   York'));
    }

    public function test_keyword_validation_in_the_domain_layer(): void
    {
        $project = Project::factory()->create();

        foreach ([
            ['keyword' => ''],
            ['keyword' => str_repeat('k', 256)],
            ['keyword' => 'ok', 'search_volume' => -1],
            ['keyword' => 'ok', 'keyword_difficulty' => -5],
            ['keyword' => 'ok', 'keyword_difficulty' => 1.5],
            ['keyword' => 'ok', 'search_intent' => 'curious'],
            ['keyword' => 'ok', 'status' => 'deleted'],
            ['keyword' => 'ok', 'location' => str_repeat('l', 256)],
        ] as $attributes) {
            try {
                app(CreateKeywordAction::class)->handle($project, $attributes);
                $this->fail('Expected InvalidArgumentException for '.json_encode($attributes));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, $project->keywords()->count());

        $keyword = app(CreateKeywordAction::class)->handle($project, ['keyword' => 'ok', 'search_volume' => '0', 'keyword_difficulty' => 150, 'is_branded' => '1']);

        $this->assertSame(0, $keyword->search_volume);
        $this->assertSame(150, $keyword->keyword_difficulty);
        $this->assertTrue($keyword->is_branded);
    }

    public function test_archiving_and_page_removal_preserve_keywords_and_ranking_history(): void
    {
        $page = Page::factory()->create();
        $keyword = Keyword::factory()->targeting($page)->create();
        RankingSnapshot::factory()->count(3)->forKeyword($keyword)
            ->sequence(['checked_at' => '2026-09-01 09:00'], ['checked_at' => '2026-09-08 09:00'], ['checked_at' => '2026-09-15 09:00'])
            ->create();

        app(SetKeywordStatusAction::class)->handle($keyword, KeywordStatus::Archived);
        app(SetPageStatusAction::class)->handle($page, PageStatus::Removed);

        $this->assertTrue($keyword->fresh()->isArchived());
        $this->assertNotSoftDeleted($keyword);
        $this->assertSame(3, $keyword->rankingSnapshots()->count());
        $this->assertTrue($keyword->fresh()->targetPage->is($page));
        $this->assertTrue($page->fresh()->isRemoved());

        // Exceptional soft delete of the page leaves the keyword and history alone.
        $page->delete();
        $this->assertTrue($keyword->fresh()->targetPage->is($page));
        $this->assertSame(3, $keyword->rankingSnapshots()->count());

        // Hard-deleting a keyword with history is blocked by the database.
        $this->expectException(QueryException::class);
        $keyword->forceDelete();
    }

    public function test_update_keyword_action_changes_master_data(): void
    {
        $project = Project::factory()->create();
        $page = Page::factory()->forProject($project)->create();
        $keyword = app(CreateKeywordAction::class)->handle($project, ['keyword' => 'old']);

        app(UpdateKeywordAction::class)->handle($keyword, [
            'keyword' => 'New Keyword',
            'target_page_id' => $page->id,
            'search_volume' => 1200,
            'keyword_difficulty' => 40,
            'search_intent' => KeywordIntent::Local,
            'keyword_role' => 'secondary',
            'location' => 'Leeds',
            'is_branded' => true,
            'status' => 'paused',
        ]);

        $fresh = $keyword->fresh();

        $this->assertSame('New Keyword', $fresh->keyword);
        $this->assertSame('new keyword', $fresh->keyword_normalized);
        $this->assertSame('leeds', $fresh->location_normalized);
        $this->assertTrue($fresh->targetPage->is($page));
        $this->assertSame(1200, $fresh->search_volume);
        $this->assertSame(KeywordIntent::Local, $fresh->search_intent);
        $this->assertSame(KeywordRole::Secondary, $fresh->keyword_role);
        $this->assertTrue($fresh->is_branded);
        $this->assertSame(KeywordStatus::Paused, $fresh->status);

        // Clearing the target page is allowed.
        app(UpdateKeywordAction::class)->handle($keyword, ['target_page_id' => null]);
        $this->assertNull($keyword->fresh()->target_page_id);
    }
}
