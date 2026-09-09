<?php

namespace Tests\Feature\Imports;

use App\Enums\ImportType;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Milestone 16 boundaries: CSV only, synchronous, through the domain
 * actions, and nothing from Milestone 17 or the API integrations.
 */
class ImportScopeTest extends TestCase
{
    public function test_no_spreadsheet_google_or_seo_api_packages_were_introduced(): void
    {
        $composer = json_decode(File::get(base_path('composer.json')), true);
        $required = array_keys(($composer['require'] ?? []) + ($composer['require-dev'] ?? []));

        // openspout/openspout is declared for the Milestone 17 administrative legacy migration only;
        // the user-facing import layer must not touch it (asserted below).
        foreach (['phpoffice/phpspreadsheet', 'maatwebsite/excel', 'league/csv', 'google/apiclient', 'google/analytics-data', 'revolution/laravel-google-sheets'] as $package) {
            $this->assertNotContains($package, $required, "{$package} must not be a direct dependency");
        }

        foreach (File::allFiles(app_path('Services/Imports')) as $file) {
            $this->assertStringNotContainsString('OpenSpout', File::get($file->getPathname()), 'product CSV imports never read spreadsheets');
        }
    }

    public function test_the_import_layer_is_csv_only_synchronous_and_free_of_spreadsheet_or_api_code(): void
    {
        $sources = collect(File::allFiles(app_path('Services/Imports')))
            ->merge(File::allFiles(app_path('Filament/Resources/Imports')))
            ->merge(File::allFiles(app_path('Support/Imports')))
            ->mapWithKeys(fn ($file) => [$file->getPathname() => File::get($file->getPathname())]);

        $this->assertNotEmpty($sources);

        foreach ($sources as $path => $source) {
            foreach (['xlsx', 'PhpSpreadsheet', 'Google_Client', 'Google\\Service', 'GoogleSheets', 'AhrefsApi', 'SemrushApi', 'DataForSeo', 'ShouldQueue', 'dispatch(', 'Bus::', 'Queue::', 'Http::'] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase($forbidden, $source, "{$path} must not contain {$forbidden}");
            }
        }

        $this->assertFalse(File::isDirectory(app_path('Jobs')) && collect(File::files(app_path('Jobs')))->contains(fn ($f) => str_contains($f->getFilename(), 'Import')), 'no import job classes');
        $this->assertSame(['csv', 'txt'], config('imports.allowed_extensions'));
    }

    public function test_importers_write_only_through_domain_actions(): void
    {
        foreach (File::allFiles(app_path('Services/Imports/Importers')) as $file) {
            $source = File::get($file->getPathname());

            foreach (['DB::table', 'DB::insert', '->insert(', '->upsert(', '->insertGetId(', '::create(', '->create(', '->update([', '->save()', 'forceFill', 'DB::statement'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, "{$file->getFilename()} must not contain {$forbidden}");
            }
        }

        $this->assertSame(
            ['keywords', 'ranking_snapshots', 'backlinks', 'gsc_queries', 'gsc_pages', 'ga4_countries'],
            array_map(fn (ImportType $t): string => $t->value, ImportType::cases()),
        );
    }
}
