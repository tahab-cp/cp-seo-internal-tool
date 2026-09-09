<?php

namespace Tests\Feature\Imports;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Enums\BacklinkStatus;
use App\Enums\BacklinkType;
use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Enums\MonthlyCycleStatus;
use App\Models\Backlink;
use App\Models\MonthlyCycle;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use App\Services\Imports\ImportExecutionService;
use App\Services\MonthlyCycles\TargetProgressService;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RunsCsvImports;
use Tests\TestCase;

class BacklinkImportTest extends TestCase
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
        $package = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 10],
            ['target_key' => 'guest_posts', 'label' => 'Guest posts', 'target_value' => 2],
        ])->create();
        $this->project = Project::factory()->withPackage($package)->create(['name' => 'Casa']);
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
    }

    public function test_valid_backlink_csv_imports_through_the_domain_action(): void
    {
        $csv = "Published URL,Anchor,Target,Type,Status,Date,DA,DR,Spam,Notes\n"
            ."https://blog.example/post,\"roses, red\",https://casa.test/roses,guest_post,live,2026-09-03,45,50,2,\"First, guest post\"\n"
            ."https://dir.example/casa,,,directory,submitted,,,,,\n";

        $batch = $this->importCsv($this->manager, ImportType::Backlinks, $this->project, $this->september, $csv);

        $this->assertSame(ImportStatus::Completed, $batch->status);
        $this->assertSame(2, $batch->imported_rows);

        $post = Backlink::query()->where('published_url', 'https://blog.example/post')->firstOrFail();
        $this->assertSame($this->project->id, $post->project_id);
        $this->assertSame($this->september->id, $post->monthly_cycle_id);
        $this->assertSame($this->manager->id, $post->created_by);
        $this->assertSame('roses, red', $post->anchor_text);
        $this->assertSame(BacklinkType::GuestPost, $post->type);
        $this->assertSame(BacklinkStatus::Live, $post->status);
        $this->assertSame('2026-09-03', $post->published_date->toDateString());
        $this->assertSame(45, $post->domain_authority);
        $this->assertSame(50, $post->domain_rating);
        $this->assertSame(2, $post->spam_score);
        $this->assertSame('First, guest post', $post->notes);

        $dir = Backlink::query()->where('published_url', 'https://dir.example/casa')->firstOrFail();
        $this->assertNull($dir->published_date);
        $this->assertNull($dir->domain_authority);
        $this->assertSame(BacklinkStatus::Submitted, $dir->status);
    }

    public function test_invalid_urls_types_statuses_dates_and_blank_status_are_rejected(): void
    {
        $csv = "published_url,type,status,published_date,domain_authority\n"
            ."not a url,guest_post,live,,\n"
            ."https://ok.example/a,sponsored,live,,\n"
            ."https://ok.example/b,guest_post,published,,\n"
            ."https://ok.example/c,guest_post,,,\n"
            ."https://ok.example/d,guest_post,live,03/09/2026,\n"
            ."https://ok.example/e,guest_post,live,2026-09-03,150\n"
            ."ftp://ok.example/f,guest_post,live,,\n"
            ."https://ok.example/g,Guest_Post,LIVE,2026-09-03,10\n";

        [$batch, $report] = $this->validateCsv($this->manager, ImportType::Backlinks, $this->project, $this->september, $csv);

        $this->assertSame(1, $report->validCount(), 'only the last row (case-insensitive enum values) is valid');
        $byRow = $report->issues()->mapWithKeys(fn ($i) => [$i->rowNumber => $i->field.': '.$i->message])->all();

        $this->assertStringContainsString('published_url: The backlink published URL must be a valid http(s) URL', $byRow[2]);
        $this->assertStringContainsString('type: Unknown backlink type [sponsored]', $byRow[3]);
        $this->assertStringContainsString('status: Unknown backlink status [published]', $byRow[4]);
        $this->assertStringContainsString('status: Status is required.', $byRow[5]);
        $this->assertStringContainsString('published_date: "03/09/2026" is not a date in YYYY-MM-DD form', $byRow[6]);
        $this->assertStringContainsString('domain_authority: The backlink domain authority must be a whole number between 0 and 100', $byRow[7]);
        $this->assertStringContainsString('published_url: The backlink published URL must be a valid http(s) URL', $byRow[8]);

        $this->assertSame(0, Backlink::query()->count());
    }

    public function test_repeated_published_urls_are_legitimate(): void
    {
        $csv = "published_url,type,status\nhttps://blog.example/post,guest_post,live\nhttps://blog.example/post,guest_post,live\nhttps://blog.example/post,citation,planned\n";

        $batch = $this->importCsv($this->manager, ImportType::Backlinks, $this->project, $this->september, $csv);

        $this->assertSame(ImportStatus::Completed, $batch->status);
        $this->assertSame(3, Backlink::query()->where('published_url', 'https://blog.example/post')->count());
    }

    public function test_locked_cycle_is_rejected(): void
    {
        [$batch] = $this->validateCsv($this->manager, ImportType::Backlinks, $this->project, $this->september, "published_url,type,status\nhttps://blog.example/post,guest_post,live\n");
        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();

        $batch = app(ImportExecutionService::class)->execute($batch, $this->manager);

        $this->assertSame(ImportStatus::Failed, $batch->status);
        $this->assertSame(0, Backlink::query()->count());
        $this->assertStringContainsString('is locked (finalized); cannot import CSV data into it', $batch->rowErrors()->first()->message);
    }

    public function test_live_guest_post_progress_updates_after_import(): void
    {
        $progress = app(TargetProgressService::class);
        $this->assertSame(0, $progress->guestPosts($this->september)->actual);
        $this->assertSame(0, $progress->backlinks($this->september)->actual);

        $csv = "published_url,type,status\n"
            ."https://a.example/1,guest_post,live\n"
            ."https://a.example/2,guest_post,live\n"
            ."https://a.example/3,guest_post,submitted\n"
            ."https://a.example/4,citation,live\n";

        $this->importCsv($this->manager, ImportType::Backlinks, $this->project, $this->september, $csv);

        $this->assertSame(2, $progress->guestPosts($this->september)->actual);
        $this->assertSame(3, $progress->backlinks($this->september)->actual);
        $this->assertSame(2, $progress->guestPosts($this->september)->target);
        $this->assertTrue($progress->guestPosts($this->september)->isComplete());
    }
}
