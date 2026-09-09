<?php

namespace Tests\Feature\Imports;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Exceptions\CsvImportException;
use App\Filament\Resources\Imports\ImportBatchResource;
use App\Filament\Resources\Imports\Pages\ListImportBatches;
use App\Filament\Resources\Imports\Pages\NewImport;
use App\Models\ImportBatch;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\User;
use App\Services\Imports\ImportExecutionService;
use App\Services\Imports\ImportUploadService;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\RunsCsvImports;
use Tests\TestCase;

class ImportAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use RunsCsvImports;

    protected User $admin;

    protected User $manager;

    protected User $executive;

    protected Project $assigned;

    protected Project $unrelated;

    protected MonthlyCycle $assignedCycle;

    protected MonthlyCycle $unrelatedCycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImportDisk();
        $this->admin = User::factory()->superAdmin()->create();
        $this->manager = User::factory()->seoManager()->create();
        $this->executive = User::factory()->seoExecutive()->create();
        $this->assigned = Project::factory()->ownedBy($this->executive)->create(['name' => 'Assigned']);
        $this->unrelated = Project::factory()->create(['name' => 'Unrelated']);
        $this->assignedCycle = app(CreateMonthlyCycleAction::class)->handle($this->assigned, new CyclePeriod(2026, 9));
        $this->unrelatedCycle = app(CreateMonthlyCycleAction::class)->handle($this->unrelated, new CyclePeriod(2026, 9));
    }

    public function test_admin_manager_and_assigned_executive_can_import_into_accessible_projects(): void
    {
        foreach ([$this->admin, $this->manager, $this->executive] as $i => $user) {
            $batch = $this->importCsv($user, ImportType::Keywords, $this->assigned, null, "keyword\nkeyword {$i}\n");

            $this->assertSame(ImportStatus::Completed, $batch->status, $user->name);
            $this->assertSame($user->id, $batch->created_by);
        }

        $this->assertSame(3, Keyword::query()->where('project_id', $this->assigned->id)->count());

        // Admin and manager also reach the unrelated project (they see every project).
        $this->assertSame(ImportStatus::Completed, $this->importCsv($this->admin, ImportType::Keywords, $this->unrelated, null, "keyword\nx\n")->status);
        $this->assertSame(ImportStatus::Completed, $this->importCsv($this->manager, ImportType::Backlinks, $this->unrelated, $this->unrelatedCycle, "published_url,type,status\nhttps://a.test/,citation,live\n")->status);
    }

    public function test_executive_cannot_import_into_an_unrelated_project(): void
    {
        $uploads = app(ImportUploadService::class);

        try {
            $uploads->resolveProject($this->executive, $this->unrelated->id);
            $this->fail('unrelated project must not resolve');
        } catch (CsvImportException $exception) {
            $this->assertSame('Choose a project you have access to.', $exception->getMessage());
        }

        // Even with the model in hand (bypassing the wizard) the service refuses.
        try {
            $uploads->upload($this->executive, ImportType::Keywords, $this->unrelated, null, $this->csvFile("keyword\nx\n"));
            $this->fail('upload must be refused');
        } catch (CsvImportException $exception) {
            $this->assertSame('You may not import into this project.', $exception->getMessage());
        }

        $this->assertSame(0, ImportBatch::query()->count());
        $this->assertSame(0, Keyword::query()->count());
    }

    public function test_a_crafted_project_id_cannot_bypass_scoping_in_the_wizard(): void
    {
        $this->actingAs($this->executive);

        Livewire::test(NewImport::class)
            ->assertOk()
            ->set('importType', ImportType::Keywords->value)
            ->set('projectId', (string) $this->unrelated->id)
            ->call('chooseContext')
            ->assertSet('step', 1)
            ->assertNotified('Choose a project you have access to.');

        // The unrelated project is not even offered.
        $options = Livewire::test(NewImport::class)->instance()->getProjectOptions();
        $this->assertArrayHasKey($this->assigned->id, $options);
        $this->assertArrayNotHasKey($this->unrelated->id, $options);

        // Nor does a query-string prefill leak it.
        $this->get(ImportBatchResource::getUrl('create', ['project' => $this->unrelated->id, 'type' => 'keywords']))->assertOk()
            ->assertSee('data-import-project=""', false)
            ->assertSee('data-import-type="keywords"', false);
        $this->get(ImportBatchResource::getUrl('create', ['project' => $this->assigned->id]))->assertOk()
            ->assertSee('data-import-project="'.$this->assigned->id.'"', false);

        $this->assertSame(0, ImportBatch::query()->count());
    }

    public function test_a_crafted_monthly_cycle_id_cannot_bypass_the_project_relationship(): void
    {
        $uploads = app(ImportUploadService::class);

        try {
            $uploads->resolveCycle($this->assigned, ImportType::Backlinks, $this->unrelatedCycle->id);
            $this->fail('foreign cycle must not resolve');
        } catch (CsvImportException $exception) {
            $this->assertSame('Choose a reporting month of the selected project.', $exception->getMessage());
        }

        try {
            $uploads->upload($this->manager, ImportType::Backlinks, $this->assigned, $this->unrelatedCycle, $this->csvFile("published_url,type,status\nhttps://a.test/,citation,live\n"));
            $this->fail('upload with a foreign cycle must be refused');
        } catch (CsvImportException $exception) {
            $this->assertSame('Choose a reporting month of the selected project.', $exception->getMessage());
        }

        $this->actingAs($this->executive);
        Livewire::test(NewImport::class)
            ->set('importType', ImportType::Backlinks->value)
            ->set('projectId', (string) $this->assigned->id)
            ->set('monthlyCycleId', (string) $this->unrelatedCycle->id)
            ->call('chooseContext')
            ->assertSet('step', 1)
            ->assertNotified('Choose a reporting month of the selected project.');

        $this->assertSame(0, ImportBatch::query()->count());
    }

    public function test_import_history_is_project_scoped(): void
    {
        $mine = $this->importCsv($this->manager, ImportType::Keywords, $this->assigned, null, "keyword\nmine\n");
        $theirs = $this->importCsv($this->manager, ImportType::Keywords, $this->unrelated, null, "keyword\ntheirs\n");

        $this->actingAs($this->executive);

        Livewire::test(ListImportBatches::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);

        $this->get(ImportBatchResource::getUrl('view', ['record' => $mine]))->assertOk()->assertSee('Assigned');
        $this->get(ImportBatchResource::getUrl('view', ['record' => $theirs]))->assertNotFound();

        $this->actingAs($this->admin);
        Livewire::test(ListImportBatches::class)->assertCanSeeTableRecords([$mine, $theirs]);
        $this->get(ImportBatchResource::getUrl('view', ['record' => $theirs]))->assertOk();
    }

    public function test_executive_cannot_execute_someone_elses_batch(): void
    {
        [$batch] = $this->validateCsv($this->manager, ImportType::Keywords, $this->unrelated, null, "keyword\ntheirs\n");

        $this->expectException(CsvImportException::class);
        $this->expectExceptionMessage('You may not run this import.');
        app(ImportExecutionService::class)->execute($batch, $this->executive);
    }
}
