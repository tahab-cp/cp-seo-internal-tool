<?php

namespace Tests\Feature\Imports;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Enums\MonthlyCycleStatus;
use App\Filament\Resources\Imports\ImportBatchResource;
use App\Filament\Resources\Imports\Pages\ListImportBatches;
use App\Filament\Resources\Imports\Pages\NewImport;
use App\Filament\Resources\Imports\Pages\ViewImportBatch;
use App\Models\ImportBatch;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\RankingSnapshot;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\RunsCsvImports;
use Tests\TestCase;

class ImportHistoryUiTest extends TestCase
{
    use RefreshDatabase;
    use RunsCsvImports;

    protected User $manager;

    protected User $executive;

    protected Project $project;

    protected MonthlyCycle $september;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImportDisk();
        $this->manager = User::factory()->seoManager()->create();
        $this->executive = User::factory()->seoExecutive()->create();
        $this->project = Project::factory()->ownedBy($this->executive)->create(['name' => 'Casa']);
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
    }

    public function test_a_successful_import_batch_is_recorded_with_its_file_on_the_private_disk(): void
    {
        $batch = $this->importCsv($this->manager, ImportType::Keywords, $this->project, null, "keyword\nroses\n", name: 'My Keywords (Sept).csv');

        $this->assertSame(ImportStatus::Completed, $batch->status);
        $this->assertSame('My Keywords (Sept).csv', $batch->original_filename);
        $this->assertSame(ImportType::Keywords, $batch->import_type);
        $this->assertNull($batch->monthly_cycle_id);
        $this->assertSame($this->manager->id, $batch->created_by);
        $this->assertSame(['keyword' => 'keyword'], $batch->mapping());
        $this->assertStringStartsWith('imports/'.$this->project->id.'/'.$batch->id.'-', $batch->stored_file_path);
        Storage::disk(config('imports.disk'))->assertExists($batch->stored_file_path);
        $this->assertStringNotContainsString('..', $batch->stored_file_path);

        $this->actingAs($this->manager);
        $this->get(ImportBatchResource::getUrl('index'))->assertOk()->assertSee('My Keywords (Sept).csv')->assertDontSee($batch->stored_file_path);
    }

    public function test_failed_validation_errors_remain_reviewable_with_row_numbers(): void
    {
        [$batch] = $this->validateCsv($this->manager, ImportType::Keywords, $this->project, null, "keyword,search_volume\nroses,10\ntulips,-1\n,5\n");

        $this->assertSame(ImportStatus::Validated, $batch->status);
        $this->assertSame(2, $batch->failed_rows);

        $errors = $batch->rowErrors()->get();
        $this->assertSame([3, 4], $errors->pluck('row_number')->all());
        $this->assertSame(['search_volume', 'keyword'], $errors->pluck('field')->all());
        $this->assertSame('tulips', $errors[0]->raw_row_json['keyword']);
        $this->assertSame('-1', $errors[0]->raw_row_json['search_volume']);

        $this->actingAs($this->executive);
        Livewire::test(ViewImportBatch::class, ['record' => $batch->getKey()])
            ->assertOk()
            ->assertSee('Casa — Keywords')
            ->assertCanSeeTableRecords($errors)
            ->assertSee('The search volume must be a non-negative integer')
            ->assertSee('Keyword is required.');

        $this->get(ImportBatchResource::getUrl('view', ['record' => $batch]))->assertOk()
            ->assertSee('data-import-card="failed" data-value="2"', false)
            ->assertSee('data-import-mapping="search_volume"', false);
    }

    public function test_history_table_and_details_have_no_delete_or_edit_actions(): void
    {
        $batch = $this->importCsv($this->manager, ImportType::Keywords, $this->project, null, "keyword\nroses\n");

        $this->assertFalse(ImportBatchResource::canDelete($batch));
        $this->assertFalse(ImportBatchResource::canDeleteAny());
        $this->assertFalse(ImportBatchResource::canEdit($batch));
        $this->assertFalse($this->manager->can('delete', $batch));
        $this->assertFalse($this->manager->can('forceDelete', $batch));
        $this->assertFalse(User::factory()->superAdmin()->create()->can('delete', $batch));

        $this->actingAs($this->manager);
        Livewire::test(ListImportBatches::class)
            ->assertCanSeeTableRecords([$batch])
            ->assertTableActionExists('view')
            ->assertTableActionDoesNotExist('delete')
            ->assertTableActionDoesNotExist('forceDelete')
            ->assertTableActionDoesNotExist('edit')
            ->assertTableBulkActionDoesNotExist('delete');

        $this->assertArrayNotHasKey('edit', ImportBatchResource::getPages());
    }

    public function test_the_wizard_previews_without_mutating_and_imports_only_on_the_explicit_final_step(): void
    {
        $this->actingAs($this->executive);

        $csv = "Keyword,Volume\nroses,120\n\"tulips, red\",\n";

        $component = Livewire::test(NewImport::class)
            ->assertOk()
            ->assertSet('step', 1)
            ->set('importType', ImportType::Keywords->value)
            ->set('projectId', (string) $this->project->id)
            ->call('chooseContext')
            ->assertSet('step', 2)
            ->set('file', UploadedFile::fake()->createWithContent('keywords.csv', $csv))
            ->call('upload')
            ->assertSet('step', 3)
            ->assertSet('mapping.keyword', 'Keyword')
            ->assertSet('mapping.search_volume', 'Volume')
            ->call('saveMapping')
            ->assertSet('step', 4)
            ->assertSee('data-preview-card="total" data-value="2"', false)
            ->assertSee('data-preview-card="valid" data-value="2"', false)
            ->assertSee('data-preview-card="invalid" data-value="0"', false)
            ->assertSee('data-import-run', false)
            ->assertSee('tulips, red');

        $batch = ImportBatch::query()->sole();
        $this->assertSame(ImportStatus::Validated, $batch->status);
        $this->assertSame(0, Keyword::query()->count(), 'preview and validation write nothing');

        $component->call('import')->assertSet('step', 5)->assertSee('data-import-result="completed"', false)->assertSee('data-result-imported="2"', false);

        $this->assertSame(2, Keyword::query()->where('project_id', $this->project->id)->count());
        $this->assertSame(ImportStatus::Completed, $batch->refresh()->status);
        $this->assertSame(2, $batch->imported_rows);
    }

    public function test_the_wizard_blocks_import_while_rows_are_invalid_and_shows_each_problem(): void
    {
        $this->actingAs($this->manager);
        Keyword::factory()->forProject($this->project)->create(['keyword' => 'roses']);

        Livewire::test(NewImport::class)
            ->set('importType', ImportType::RankingSnapshots->value)
            ->set('projectId', (string) $this->project->id)
            ->set('monthlyCycleId', (string) $this->september->id)
            ->call('chooseContext')
            ->assertSet('step', 2)
            ->set('file', UploadedFile::fake()->createWithContent('ranks.csv', "keyword,checked_at,position\nroses,2026-09-10,3\nseo london,2026-09-10,1\nroses,2026-09-11,0\n"))
            ->call('upload')
            ->call('saveMapping')
            ->assertSet('step', 4)
            ->assertSee('data-preview-card="invalid" data-value="2"', false)
            ->assertSee('data-import-blocked', false)
            ->assertDontSee('data-import-run', false)
            ->assertSee('Unknown keyword "seo london"')
            ->assertSee('positive integer or empty')
            ->assertSee('data-preview-issue="3"', false)
            ->assertSee('data-preview-issue="4"', false)
            ->call('import')
            ->assertSet('step', 4)
            ->assertNotified('The file still has invalid rows; fix the CSV and validate it again.');

        $this->assertSame(0, RankingSnapshot::query()->count());
    }

    public function test_locked_months_are_not_offered_and_a_crafted_locked_cycle_is_refused(): void
    {
        $august = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 8));
        $august->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();

        $this->actingAs($this->manager);

        $component = Livewire::test(NewImport::class)
            ->set('importType', ImportType::Backlinks->value)
            ->set('projectId', (string) $this->project->id);

        $options = $component->instance()->getCycleOptions();
        $this->assertArrayHasKey($this->september->id, $options);
        $this->assertArrayNotHasKey($august->id, $options);

        $component->set('monthlyCycleId', (string) $august->id)
            ->call('chooseContext')
            ->assertSet('step', 1)
            ->assertNotified('August 2026 is locked (finalized); imports into it are not allowed. A Super Admin must unlock the month first.');
    }

    public function test_the_wizard_rejects_bad_files_and_missing_required_mappings_before_anything_is_stored(): void
    {
        $this->actingAs($this->manager);

        $component = Livewire::test(NewImport::class)
            ->set('importType', ImportType::Keywords->value)
            ->set('projectId', (string) $this->project->id)
            ->call('chooseContext')
            ->set('file', UploadedFile::fake()->createWithContent('book.xlsx', "PK\x03\x04"))
            ->call('upload')
            ->assertSet('step', 2)
            ->assertNotified('Only CSV files (.csv) can be imported; spreadsheets must be exported to CSV first.');

        $this->assertSame(0, ImportBatch::query()->count());

        $component->set('file', UploadedFile::fake()->createWithContent('kw.csv', "Phrase,Volume\nroses,1\n"))
            ->call('upload')
            ->assertSet('step', 3)
            ->assertSet('mapping.keyword', null)
            ->assertSet('mapping.search_volume', 'Volume')
            ->call('saveMapping')
            ->assertSet('step', 3)
            ->assertNotified('The required field "Keyword" is not mapped to a CSV column.')
            ->set('mapping.keyword', 'Phrase')
            ->call('saveMapping')
            ->assertSet('step', 4);
    }

    public function test_imports_navigation_is_visible_to_every_role_and_guests_are_redirected(): void
    {
        foreach ([$this->manager, $this->executive] as $user) {
            $this->actingAs($user);
            $this->get('/admin')->assertOk()->assertSee(ImportBatchResource::getUrl('index'));
            $this->get(ImportBatchResource::getUrl('create'))->assertOk()->assertSee('New import');
        }

        auth()->logout();
        $this->get(ImportBatchResource::getUrl('index'))->assertRedirect();
    }
}
