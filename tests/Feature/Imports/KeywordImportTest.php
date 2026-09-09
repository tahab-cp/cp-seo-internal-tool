<?php

namespace Tests\Feature\Imports;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Enums\KeywordIntent;
use App\Enums\KeywordRole;
use App\Enums\KeywordStatus;
use App\Models\Keyword;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Services\Imports\ImportExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RunsCsvImports;
use Tests\TestCase;

class KeywordImportTest extends TestCase
{
    use RefreshDatabase;
    use RunsCsvImports;

    protected User $manager;

    protected Project $project;

    protected Project $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImportDisk();
        $this->manager = User::factory()->seoManager()->create();
        $this->project = Project::factory()->create(['name' => 'Casa']);
        $this->other = Project::factory()->create(['name' => 'Other']);
    }

    public function test_keyword_csv_creates_new_keywords_through_the_domain_action(): void
    {
        $page = Page::factory()->forProject($this->project)->create(['url' => 'https://casa.test/roses', 'path' => '/roses']);

        $csv = "Keyword,Location,Target page URL,Role,Volume,KD,Intent,Branded,Status\n"
            ."\"Roses, red\",London,https://casa.test/roses,primary,1200,35,commercial,yes,active\n"
            ."garden tools,,,,,,,,\n";

        $batch = $this->importCsv($this->manager, ImportType::Keywords, $this->project, null, $csv);

        $this->assertSame(ImportStatus::Completed, $batch->status);
        $this->assertSame(2, $batch->imported_rows);
        $this->assertSame(0, $batch->failed_rows);

        $roses = Keyword::query()->where('keyword', 'Roses, red')->firstOrFail();
        $this->assertSame($this->project->id, $roses->project_id);
        $this->assertSame('roses, red', $roses->keyword_normalized);
        $this->assertSame('london', $roses->location_normalized);
        $this->assertSame($page->id, $roses->target_page_id);
        $this->assertSame(KeywordRole::Primary, $roses->keyword_role);
        $this->assertSame(1200, $roses->search_volume);
        $this->assertSame(35, $roses->keyword_difficulty);
        $this->assertSame(KeywordIntent::Commercial, $roses->search_intent);
        $this->assertTrue($roses->is_branded);
        $this->assertSame(KeywordStatus::Active, $roses->status);

        $tools = Keyword::query()->where('keyword', 'garden tools')->firstOrFail();
        $this->assertNull($tools->location);
        $this->assertSame('', $tools->location_normalized);
        $this->assertFalse($tools->is_branded);
        $this->assertSame(KeywordStatus::Active, $tools->status);
        $this->assertNull($tools->target_page_id);
    }

    public function test_a_normalised_duplicate_updates_the_existing_keyword_instead_of_creating_one(): void
    {
        $existing = Keyword::factory()->forProject($this->project)->create(['keyword' => 'Garden Tools', 'search_volume' => 50, 'keyword_difficulty' => 20]);

        $csv = "keyword,search_volume\n  garden   TOOLS ,900\n";

        $batch = $this->importCsv($this->manager, ImportType::Keywords, $this->project, null, $csv);

        $this->assertSame(ImportStatus::Completed, $batch->status);
        $this->assertSame(1, Keyword::query()->where('project_id', $this->project->id)->count());
        $this->assertSame(900, $existing->refresh()->search_volume);
        $this->assertSame('garden   TOOLS', $existing->keyword, 'a non-blank CSV keyword updates the spelling (trimmed) exactly like a manual edit; the identity is what matched');
        $this->assertSame('garden tools', $existing->keyword_normalized);
        $this->assertSame(20, $existing->keyword_difficulty);
    }

    public function test_the_same_keyword_may_still_exist_in_another_project(): void
    {
        Keyword::factory()->forProject($this->other)->create(['keyword' => 'garden tools']);

        $batch = $this->importCsv($this->manager, ImportType::Keywords, $this->project, null, "keyword\ngarden tools\n");

        $this->assertSame(ImportStatus::Completed, $batch->status);
        $this->assertSame(1, Keyword::query()->where('project_id', $this->project->id)->count());
        $this->assertSame(1, Keyword::query()->where('project_id', $this->other->id)->count());
    }

    public function test_blank_values_leave_existing_fields_unchanged_on_update(): void
    {
        $page = Page::factory()->forProject($this->project)->create();
        $existing = Keyword::factory()->forProject($this->project)->targeting($page)->create([
            'keyword' => 'garden tools', 'search_volume' => 50, 'keyword_difficulty' => 20,
            'keyword_role' => KeywordRole::Secondary, 'search_intent' => KeywordIntent::Local, 'is_branded' => true, 'status' => KeywordStatus::Paused,
        ]);

        $csv = "keyword,search_volume,keyword_difficulty,keyword_role,search_intent,is_branded,status,target_page_url\ngarden tools,,,,,,,\n";

        $batch = $this->importCsv($this->manager, ImportType::Keywords, $this->project, null, $csv);

        $this->assertSame(ImportStatus::Completed, $batch->status);
        $existing->refresh();
        $this->assertSame(50, $existing->search_volume);
        $this->assertSame(20, $existing->keyword_difficulty);
        $this->assertSame(KeywordRole::Secondary, $existing->keyword_role);
        $this->assertSame(KeywordIntent::Local, $existing->search_intent);
        $this->assertTrue($existing->is_branded);
        $this->assertSame(KeywordStatus::Paused, $existing->status);
        $this->assertSame($page->id, $existing->target_page_id);
    }

    public function test_invalid_enum_boolean_and_number_values_are_rejected_with_row_numbers(): void
    {
        $csv = "keyword,keyword_role,search_intent,status,is_branded,search_volume\n"
            ."ok,primary,commercial,active,yes,10\n"
            ."bad role,owner,commercial,active,yes,10\n"
            ."bad intent,primary,buying,active,yes,10\n"
            ."bad status,primary,commercial,live,yes,10\n"
            ."bad bool,primary,commercial,active,maybe,10\n"
            ."bad volume,primary,commercial,active,yes,-3\n";

        [$batch, $report] = $this->validateCsv($this->manager, ImportType::Keywords, $this->project, null, $csv);

        $this->assertSame(6, $report->totalRows());
        $this->assertSame(1, $report->validCount());
        $this->assertSame(5, $report->invalidCount());
        $this->assertFalse($report->isImportable());

        $messages = $report->issues()->mapWithKeys(fn ($i) => [$i->rowNumber => $i->field.': '.$i->message])->all();
        $this->assertStringContainsString('keyword_role: Unknown keyword role [owner]', $messages[3]);
        $this->assertStringContainsString('search_intent: Unknown search intent [buying]', $messages[4]);
        $this->assertStringContainsString('status: Unknown status [live]', $messages[5]);
        $this->assertStringContainsString('is_branded: "maybe" is not a recognised yes/no value', $messages[6]);
        $this->assertStringContainsString('search_volume: The search volume must be a non-negative integer', $messages[7]);

        // Nothing written by validation, and the batch refuses to import while rows are invalid.
        $this->assertSame(0, Keyword::query()->count());
        $this->assertSame(ImportStatus::Validated, $batch->status);
        $this->assertSame(5, $batch->failed_rows);
        $this->assertSame(5, $batch->rowErrors()->count());

        $this->expectExceptionMessage('still has invalid rows');
        app(ImportExecutionService::class)->execute($batch, $this->manager);
    }

    public function test_a_page_of_another_project_can_never_become_the_target_page(): void
    {
        $foreign = Page::factory()->forProject($this->other)->create(['url' => 'https://other.test/roses']);

        $batch = $this->importCsv($this->manager, ImportType::Keywords, $this->project, null, "keyword,target_page_url\nroses,https://other.test/roses\n");

        $this->assertSame(ImportStatus::Completed, $batch->status);
        $keyword = Keyword::query()->where('keyword', 'roses')->firstOrFail();
        $this->assertNull($keyword->target_page_id);
        $this->assertNotSame($foreign->id, $keyword->target_page_id);
        $this->assertSame(1, $batch->rowErrors()->where('severity', 'warning')->count());
        $this->assertStringContainsString('No page with URL "https://other.test/roses" exists in this project', $batch->rowErrors()->first()->message);
    }

    public function test_an_unknown_target_page_is_a_warning_that_leaves_the_target_untouched(): void
    {
        $page = Page::factory()->forProject($this->project)->create();
        $existing = Keyword::factory()->forProject($this->project)->targeting($page)->create(['keyword' => 'roses']);

        [$batch, $report] = $this->validateCsv($this->manager, ImportType::Keywords, $this->project, null, "keyword,target_page_url\nroses,https://casa.test/nowhere\nnew one,https://casa.test/nowhere\n");

        $this->assertTrue($report->isImportable(), 'warnings never block');
        $this->assertSame(2, $report->warningCount());
        $this->assertSame(0, $report->errorCount());
        $this->assertStringContainsString('left unchanged', $report->issues()[0]->message);
        $this->assertStringContainsString('left empty', $report->issues()[1]->message);

        $batch = app(ImportExecutionService::class)->execute($batch, $this->manager);

        $this->assertSame(ImportStatus::Completed, $batch->status);
        $this->assertSame($page->id, $existing->refresh()->target_page_id);
        $this->assertNull(Keyword::query()->where('keyword', 'new one')->firstOrFail()->target_page_id);
        $this->assertSame(0, Page::query()->where('url', 'https://casa.test/nowhere')->count(), 'pages are never created by an import');
    }

    public function test_the_same_identity_twice_in_one_csv_rejects_the_later_row(): void
    {
        [, $report] = $this->validateCsv($this->manager, ImportType::Keywords, $this->project, null, "keyword,location\nroses,London\nROSES ,london\nroses,\n");

        $this->assertSame(2, $report->validCount());
        $this->assertSame('Duplicates row 2 (same keyword and location).', $report->issues()[0]->message);
        $this->assertSame(3, $report->issues()[0]->rowNumber);
    }
}
