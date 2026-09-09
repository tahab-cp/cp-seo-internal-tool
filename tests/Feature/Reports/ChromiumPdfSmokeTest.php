<?php

namespace Tests\Feature\Reports;

use App\Services\Reports\PdfReportGenerator;
use App\Services\Reports\ReportSnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\BuildsCompleteReports;
use Tests\TestCase;

/**
 * The one test that spawns the real Chromium renderer. Skipped unless
 * RUN_CHROMIUM_TESTS=1 so the normal suite never depends on a browser:
 *
 *   RUN_CHROMIUM_TESTS=1 php artisan test --group=chromium
 */
#[Group('chromium')]
class ChromiumPdfSmokeTest extends TestCase
{
    use BuildsCompleteReports;
    use RefreshDatabase;

    public function test_the_configured_chromium_renders_a_real_pdf(): void
    {
        if (! filter_var(env('RUN_CHROMIUM_TESTS', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set RUN_CHROMIUM_TESTS=1 to run the Chromium PDF smoke test.');
        }

        Carbon::setTestNow('2026-09-30 10:00:00');
        Storage::fake('local');

        $this->buildCompleteReport();

        $generator = app(PdfReportGenerator::class);
        $binary = $generator->chromiumBinary();
        $this->assertFileExists($binary);

        $path = $generator->generate(app(ReportSnapshotBuilder::class)->build($this->report), $this->report);

        Storage::disk('local')->assertExists($path);
        $pdf = Storage::disk('local')->get($path);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(10_000, strlen($pdf), 'A real multi-section report PDF should not be tiny.');
        $this->assertStringContainsString('/Type /Page', $pdf);
    }
}
