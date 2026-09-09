<?php

namespace Tests\Feature\Imports;

use App\Actions\Analytics\SaveGa4CountryMetricsAction;
use App\Actions\Analytics\SaveGscPageMetricsAction;
use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Models\Ga4CountryMetric;
use App\Models\GscPageMetric;
use App\Models\GscQueryMetric;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\RunsCsvImports;
use Tests\TestCase;

class AnalyticsImportTest extends TestCase
{
    use RefreshDatabase;
    use RunsCsvImports;

    protected User $manager;

    protected Project $project;

    protected MonthlyCycle $september;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImportDisk();
        $this->manager = User::factory()->seoManager()->create();
        $this->project = Project::factory()->create(['name' => 'Casa']);
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
    }

    public function test_gsc_query_csv_imports_with_deterministic_identity_and_leaves_keywords_alone(): void
    {
        $keyword = Keyword::factory()->forProject($this->project)->create(['keyword' => 'red roses', 'search_volume' => 10]);
        GscQueryMetric::factory()->forCycle($this->september)->create(['query' => 'old query', 'clicks' => 1, 'impressions' => 2]);

        $csv = "Top queries,Clicks,Impressions,CTR,Position\n"
            ."\"Red   Roses\",120,3000,4%,3.2\n"
            ."garden tools,5,900,0.56,\n";

        $batch = $this->importCsv($this->manager, ImportType::GscQueries, $this->project, $this->september, $csv);

        $this->assertSame(ImportStatus::Completed, $batch->status);
        $this->assertSame(2, $batch->imported_rows);

        $rows = GscQueryMetric::query()->where('monthly_cycle_id', $this->september->id)->orderBy('query')->get();
        $this->assertSame(['garden tools', 'red roses'], $rows->pluck('query')->all(), 'normalised identity; the previous month dataset row is replaced like manual entry');
        $roses = $rows->firstWhere('query', 'red roses');
        $this->assertSame(120, $roses->clicks);
        $this->assertSame('4.00', (string) $roses->ctr);
        $this->assertSame('3.20', (string) $roses->average_position);
        $this->assertNull($rows->firstWhere('query', 'garden tools')->average_position);

        // Tracked keywords are untouched.
        $this->assertSame(1, Keyword::query()->count());
        $this->assertSame(10, $keyword->refresh()->search_volume);

        // Same identity twice in one file is rejected in the preview.
        [, $report] = $this->validateCsv($this->manager, ImportType::GscQueries, $this->project, $this->september, "query,clicks,impressions,ctr\nred roses,1,1,1\nRED ROSES,2,2,2\n");
        $this->assertSame('Duplicates row 2 (same query).', $report->issues()[0]->message);
    }

    public function test_gsc_page_csv_imports_without_page_mapping_and_links_same_project_pages_only(): void
    {
        $own = Page::factory()->forProject($this->project)->create(['url' => 'https://casa.test/roses']);
        $foreign = Page::factory()->forProject(Project::factory()->create())->create(['url' => 'https://other.test/tulips']);

        $csv = "Top pages,Clicks,Impressions,CTR,Position\n"
            ."https://casa.test/roses,10,100,10,1.5\n"
            ."https://casa.test/unknown,3,50,6,\n"
            ."https://other.test/tulips,1,10,10,\n";

        $batch = $this->importCsv($this->manager, ImportType::GscPages, $this->project, $this->september, $csv);

        $this->assertSame(ImportStatus::Completed, $batch->status);
        $rows = GscPageMetric::query()->where('monthly_cycle_id', $this->september->id)->get()->keyBy('page_url');
        $this->assertCount(3, $rows);
        $this->assertSame($own->id, $rows['https://casa.test/roses']->page_id);
        $this->assertNull($rows['https://casa.test/unknown']->page_id);
        $this->assertNull($rows['https://other.test/tulips']->page_id, 'a page of another project is never linked');
        $this->assertNotSame($foreign->id, $rows['https://other.test/tulips']->page_id);
        $this->assertSame(1, Page::query()->where('project_id', $this->project->id)->count(), 'no pages created');

        // The domain action itself still rejects a foreign page id if one were ever supplied.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not belong to project');
        app(SaveGscPageMetricsAction::class)->handle($this->september, [['page_url' => 'https://x.test/', 'page_id' => $foreign->id, 'clicks' => 1, 'impressions' => 1]], $this->manager);
    }

    public function test_ga4_country_csv_imports_with_existing_normalisation_and_percentage_convention(): void
    {
        $csv = "Country,Active users,New users,Sessions,Engaged sessions,Engagement rate,Event count,Key events\n"
            ."\"United   Kingdom\",1200,300,1500,900,60,5000,42\n"
            ."Germany,,,,,8.5%,,\n";

        $batch = $this->importCsv($this->manager, ImportType::Ga4Countries, $this->project, $this->september, $csv);

        $this->assertSame(ImportStatus::Completed, $batch->status);
        $rows = Ga4CountryMetric::query()->where('monthly_cycle_id', $this->september->id)->get()->keyBy('country');
        $this->assertSame(['Germany', 'United Kingdom'], $rows->keys()->sort()->values()->all());
        $this->assertSame(1200, $rows['United Kingdom']->active_users);
        $this->assertSame('60.00', (string) $rows['United Kingdom']->engagement_rate);
        $this->assertSame('8.50', (string) $rows['Germany']->engagement_rate, '8.5 means 8.5%, never 0.085');
        $this->assertNull($rows['Germany']->active_users);

        // Same as manual entry: identity is case-insensitive, whitespace collapsed.
        app(SaveGa4CountryMetricsAction::class)->handle($this->september, [['country' => 'united kingdom', 'active_users' => 5]], $this->manager);
        $this->assertSame(5, $rows['United Kingdom']->refresh()->active_users);

        [, $report] = $this->validateCsv($this->manager, ImportType::Ga4Countries, $this->project, $this->september, "country,engagement_rate\nFrance,10\n france ,20\nSpain,150\nItaly,0.5\n");
        $this->assertSame(2, $report->validCount());
        $this->assertStringContainsString('Duplicates row 2 (same country).', $report->issues()[0]->message);
        $this->assertStringContainsString('percentage between 0 and 100 (8.5 means 8.5%)', $report->issues()[1]->message);
        $this->assertSame(0.5, $report->validRows()[1]->data['engagement_rate']);
    }

    public function test_required_counts_follow_the_domain_rules(): void
    {
        [, $report] = $this->validateCsv($this->manager, ImportType::GscQueries, $this->project, $this->september, "query,clicks,impressions,ctr\nroses,,10,1\nroses two,-1,10,1\nroses three,1,10,101\nroses four,1.5,10,1\n");

        $this->assertSame(0, $report->validCount());
        $messages = $report->issues()->map(fn ($i) => $i->field.': '.$i->message)->all();
        $this->assertStringContainsString('clicks: Clicks is required.', $messages[0]);
        $this->assertStringContainsString('clicks: The clicks must be a whole number of 0 or more', $messages[1]);
        $this->assertStringContainsString('ctr: The CTR must be a percentage between 0 and 100', $messages[2]);
        $this->assertStringContainsString('clicks: The clicks must be a whole number of 0 or more; [1.5] given', $messages[3]);
    }
}
