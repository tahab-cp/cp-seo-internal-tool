<?php

namespace Tests\Support;

use App\Exceptions\PdfGenerationException;
use App\Services\Reports\PdfReportGenerator;
use App\Services\Reports\ReportRenderer;

/**
 * Replaces the Chromium step only. Rendering, path building and storage
 * still run for real (against Storage::fake), so finalization tests cover
 * the filesystem behaviour without spawning a browser.
 */
class FakePdfReportGenerator extends PdfReportGenerator
{
    public int $conversions = 0;

    public ?string $lastHtml = null;

    /**
     * Runs while "Chromium" is rendering, to simulate another user changing
     * source data mid-finalization.
     *
     * @var (callable(): void)|null
     */
    public $duringConversion = null;

    public function __construct(
        public bool $shouldFail = false,
        public string $failureMessage = 'Chromium exploded (simulated).',
    ) {
        parent::__construct(app(ReportRenderer::class));
    }

    protected function convert(string $html): string
    {
        $this->conversions++;
        $this->lastHtml = $html;

        if ($this->duringConversion !== null) {
            ($this->duringConversion)();
        }

        if ($this->shouldFail) {
            throw new PdfGenerationException($this->failureMessage);
        }

        return "%PDF-1.4\n% fake report pdf\n".md5($html)."\n%%EOF\n";
    }

    public static function install(bool $shouldFail = false): self
    {
        $fake = new self($shouldFail);

        app()->instance(PdfReportGenerator::class, $fake);

        return $fake;
    }
}
