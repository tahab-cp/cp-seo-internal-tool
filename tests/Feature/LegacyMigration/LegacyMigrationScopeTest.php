<?php

namespace Tests\Feature\LegacyMigration;

use App\Actions\MonthlyCycles\CreateHistoricalMonthlyCycleAction;
use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Models\Keyword;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Support\RunsCsvImports;
use Tests\TestCase;

class LegacyMigrationScopeTest extends TestCase
{
    use RefreshDatabase;
    use RunsCsvImports;

    public function test_historical_cycle_action_never_resolves_todays_package_and_reuses_existing_cycles(): void
    {
        $package = Package::factory()->withTargets([['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 10]])->create();
        $project = Project::factory()->withPackage($package)->create();

        $historical = app(CreateHistoricalMonthlyCycleAction::class);

        $empty = $historical->handle($project, new CyclePeriod(2025, 3));
        $this->assertSame(0, $empty->targets()->count(), 'no legacy targets → no snapshot, not today\'s 10');
        $this->assertSame('2025-03-01', $empty->started_at->toDateString());

        $explicit = $historical->handle($project, new CyclePeriod(2025, 4), ['backlinks' => ['label' => 'Backlinks', 'target_value' => 6]]);
        $this->assertSame(['backlinks' => 6], $explicit->targets->pluck('target_value', 'target_key')->map(fn ($v) => (int) $v)->all());

        $again = $historical->handle($project, new CyclePeriod(2025, 4), ['backlinks' => ['target_value' => 99]]);
        $this->assertSame($explicit->id, $again->id, 'never duplicated');
        $this->assertSame(6, (int) $again->targets->first()->target_value, 'never re-snapshotted');

        // The product path is unchanged: today's package targets for a new cycle.
        $normal = app(CreateMonthlyCycleAction::class)->handle($project, new CyclePeriod(2025, 5));
        $this->assertSame(10, (int) $normal->targets->first()->target_value);

        $this->expectException(\InvalidArgumentException::class);
        $historical->handle($project, new CyclePeriod(2025, 6), ['backlinks' => ['target_value' => -1]]);
    }

    public function test_user_facing_csv_import_behaviour_is_unchanged(): void
    {
        $this->fakeImportDisk();
        $manager = User::factory()->seoManager()->create();
        $project = Project::factory()->create();

        $batch = $this->importCsv($manager, ImportType::Keywords, $project, null, "keyword,search_volume\nroses,10\n");

        $this->assertSame(ImportStatus::Completed, $batch->status);
        $this->assertSame(1, Keyword::query()->count());
        $this->assertSame(['keywords', 'ranking_snapshots', 'backlinks', 'gsc_queries', 'gsc_pages', 'ga4_countries'], array_map(fn (ImportType $t) => $t->value, ImportType::cases()));
        $this->assertSame(['csv', 'txt'], config('imports.allowed_extensions'), 'the product still accepts CSV only');
    }

    public function test_no_google_sheets_oauth_seo_api_client_portal_or_generic_etl_was_introduced(): void
    {
        $composer = json_decode(File::get(base_path('composer.json')), true);
        $required = array_keys(($composer['require'] ?? []) + ($composer['require-dev'] ?? []));

        foreach (['google/apiclient', 'google/analytics-data', 'revolution/laravel-google-sheets', 'phpoffice/phpspreadsheet', 'maatwebsite/excel', 'laravel/socialite'] as $package) {
            $this->assertNotContains($package, $required);
        }

        $this->assertContains('openspout/openspout', $required, 'the one focused XLSX reader, already shipped with Filament');

        $sources = collect(File::allFiles(app_path('Services/LegacyMigration')))
            ->merge(File::allFiles(app_path('Support/LegacyMigration')))
            ->push(new \SplFileInfo(app_path('Console/Commands/MigrateLegacyCommand.php')))
            ->mapWithKeys(fn ($f) => [$f->getPathname() => File::get($f->getPathname())]);

        foreach ($sources as $path => $source) {
            foreach (['Google_Client', 'Google\\Service', 'GoogleSheets', 'oauth', 'AhrefsApi', 'SemrushApi', 'DataForSeo', 'Http::', 'ShouldQueue', 'dispatch(', 'DB::table(', 'DB::insert', '->upsert(', 'DB::statement', 'similar_text', 'levenshtein', 'User::create', 'User::query()->create', 'forceCreate'] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase($forbidden, $source, "{$path} must not contain {$forbidden}");
            }
        }

        // Not a product feature: no Filament resource/page and no route for it.
        $this->assertEmpty(array_filter(File::allFiles(app_path('Filament')), fn ($f) => str_contains($f->getFilename(), 'Legacy') || str_contains($f->getFilename(), 'Migration')));
        $this->assertEmpty(array_filter(array_keys(app('router')->getRoutes()->getRoutesByName()), fn (string $n) => str_contains($n, 'legacy') || str_contains($n, 'portal')));
        $this->assertFalse(File::exists(app_path('Services/Integrations')));
        $this->assertFalse(File::exists(app_path('Services/Etl')));
        $this->assertFalse(File::exists(app_path('Services/Portal')));
    }
}
