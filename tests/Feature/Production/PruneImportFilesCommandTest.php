<?php

namespace Tests\Feature\Production;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Models\ImportBatch;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RunsCsvImports;
use Tests\TestCase;

class PruneImportFilesCommandTest extends TestCase
{
    use RefreshDatabase;
    use RunsCsvImports;

    public function test_old_raw_files_are_removed_while_history_and_row_issues_are_kept(): void
    {
        $this->fakeImportDisk();
        Carbon::setTestNow('2026-09-15 10:00:00');
        $manager = User::factory()->seoManager()->create();
        $project = Project::factory()->create();

        [$old] = $this->validateCsv($manager, ImportType::Keywords, $project, null, "keyword,search_volume\nroses,10\ntulips,-1\n");
        $old->forceFill(['created_at' => now()->subDays(120)])->save();
        $oldPath = $old->stored_file_path;
        $this->assertSame(1, $old->rowErrors()->count());

        $recent = $this->importCsv($manager, ImportType::Keywords, $project, null, "keyword\nlilies\n");
        $recentPath = $recent->stored_file_path;

        Artisan::call('seo:prune-import-files', ['--dry-run' => true]);
        $this->assertStringContainsString('would remove batch #'.$old->id, Artisan::output());
        Storage::disk(config('imports.disk'))->assertExists($oldPath);

        Artisan::call('seo:prune-import-files');
        $output = Artisan::output();

        $this->assertStringContainsString('1 batch(es) older than 90 day(s)', $output);
        $this->assertStringContainsString('1 file(s) removed', $output);
        Storage::disk(config('imports.disk'))->assertMissing($oldPath);
        Storage::disk(config('imports.disk'))->assertExists($recentPath);

        $old->refresh();
        $this->assertNull($old->stored_file_path);
        $this->assertSame(ImportStatus::Validated, $old->status, 'history untouched');
        $this->assertSame(1, $old->rowErrors()->count(), 'row issues stay reviewable');
        $this->assertSame(2, ImportBatch::query()->count(), 'batches are never deleted');
        $this->assertNotNull($recent->refresh()->stored_file_path);

        Artisan::call('seo:prune-import-files', ['--days' => 1]);
        Storage::disk(config('imports.disk'))->assertExists($recentPath);
        $this->assertNotSame(0, Artisan::call('seo:prune-import-files', ['--days' => 0]), 'a zero-day window is refused');
        Storage::disk(config('imports.disk'))->assertExists($recentPath);
    }
}
