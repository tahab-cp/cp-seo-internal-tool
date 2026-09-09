<?php

namespace App\Services\Reports;

use App\Exceptions\PdfGenerationException;
use App\Models\MonthlyReport;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Renders a report snapshot to PDF with a headless Chromium-based browser
 * (Chrome / Edge / Chromium, --print-to-pdf) and stores it through the
 * Laravel filesystem. No Filament page ever touches Chromium directly.
 *
 * The browser step is isolated in convert() so tests can substitute a
 * fake generator without spawning a browser.
 */
class PdfReportGenerator
{
    public function __construct(
        protected ReportRenderer $renderer,
    ) {}

    /**
     * Render, convert and store. Returns the relative path on the report
     * disk. Throws PdfGenerationException on any failure; nothing is left
     * on disk in that case.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function generate(array $snapshot, MonthlyReport $report): string
    {
        $html = $this->renderer->html($snapshot, forPdf: true);
        $pdf = $this->convert($html);

        if (! str_starts_with($pdf, '%PDF')) {
            throw new PdfGenerationException('The renderer did not return a PDF document.');
        }

        $path = $this->pathFor($report);

        try {
            $stored = $this->disk()->put($path, $pdf);
        } catch (Throwable $exception) {
            throw new PdfGenerationException('The PDF could not be stored: '.$exception->getMessage(), previous: $exception);
        }

        if (! $stored || ! $this->disk()->exists($path)) {
            throw new PdfGenerationException("The PDF could not be stored at [{$path}].");
        }

        return $path;
    }

    /**
     * Remove a stored PDF (used for cleanup when finalization fails after
     * the file was written). Never throws.
     */
    public function delete(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        try {
            if ($this->disk()->exists($path)) {
                $this->disk()->delete($path);
            }
        } catch (Throwable) {
            report_if(app()->hasDebugModeEnabled(), new PdfGenerationException("Orphaned PDF [{$path}] could not be removed."));
        }
    }

    public function exists(?string $path): bool
    {
        return $path !== null && $path !== '' && $this->disk()->exists($path);
    }

    public function disk(): Filesystem
    {
        return Storage::disk(config('reports.pdf_disk'));
    }

    /**
     * reports/{project}/{yyyy}-{mm}/report-{id}-{timestamp}.pdf
     */
    public function pathFor(MonthlyReport $report): string
    {
        $cycle = $report->monthlyCycle;

        return sprintf(
            '%s/%d/%04d-%02d/report-%d-%s.pdf',
            trim((string) config('reports.pdf_directory', 'reports'), '/'),
            $cycle->project_id,
            $cycle->year,
            $cycle->month,
            $report->getKey(),
            now()->format('YmdHis'),
        );
    }

    /**
     * HTML → PDF bytes via headless Chromium.
     */
    protected function convert(string $html): string
    {
        $binary = $this->chromiumBinary();
        $workDir = storage_path('app/tmp/reports/'.Str::uuid());
        File::ensureDirectoryExists($workDir);

        $htmlPath = $workDir.'/report.html';
        $pdfPath = $workDir.'/report.pdf';

        try {
            File::put($htmlPath, $html);

            $command = array_merge([
                $binary,
                '--headless=new',
                '--disable-gpu',
                '--no-first-run',
                '--no-default-browser-check',
                '--disable-extensions',
                '--hide-scrollbars',
                '--run-all-compositor-stages-before-draw',
                '--virtual-time-budget=5000',
                '--no-pdf-header-footer',
                '--user-data-dir='.$workDir.'/profile',
                '--print-to-pdf='.$pdfPath,
            ], config('reports.chromium_flags', []), [
                'file:///'.str_replace('\\', '/', ltrim($htmlPath, '/')),
            ]);

            $result = Process::timeout((int) config('reports.chromium_timeout', 90))->run($command);

            if (! File::exists($pdfPath) || File::size($pdfPath) === 0) {
                throw new PdfGenerationException(sprintf(
                    'Chromium did not produce a PDF (exit %s). %s',
                    $result->exitCode() ?? 'n/a',
                    Str::limit(trim($result->errorOutput() ?: $result->output()), 500),
                ));
            }

            return (string) File::get($pdfPath);
        } catch (PdfGenerationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new PdfGenerationException('Chromium PDF rendering failed: '.$exception->getMessage(), previous: $exception);
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    /**
     * The configured Chromium binary, or the first known candidate that exists.
     */
    public function chromiumBinary(): string
    {
        $configured = config('reports.chromium_path');

        if (filled($configured)) {
            if (! is_file($configured)) {
                throw new PdfGenerationException("CHROMIUM_PATH [{$configured}] does not exist.");
            }

            return $configured;
        }

        foreach ((array) config('reports.chromium_candidates', []) as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new PdfGenerationException('No Chromium-based browser found. Set CHROMIUM_PATH to a Chrome, Edge or Chromium executable.');
    }
}
