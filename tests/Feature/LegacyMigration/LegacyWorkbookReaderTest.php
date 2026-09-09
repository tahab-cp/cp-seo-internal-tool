<?php

namespace Tests\Feature\LegacyMigration;

use App\Models\Project;
use App\Models\RankingSnapshot;
use App\Services\LegacyMigration\LegacyWorkbookReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Row;
use Tests\Support\RunsLegacyMigrations;
use Tests\TestCase;

class LegacyWorkbookReaderTest extends TestCase
{
    use RefreshDatabase;
    use RunsLegacyMigrations;

    public function test_an_xlsx_workbook_reads_the_same_sheets_headers_and_rows_as_the_csv_directory(): void
    {
        $reader = app(LegacyWorkbookReader::class);
        $csv = $reader->read($this->fixtureDirectory());
        $path = $this->writeXlsx($this->fixtureSheets());
        $xlsx = $reader->read($path);

        $this->assertEqualsCanonicalizing($csv->sheetNames(), $xlsx->sheetNames());
        $this->assertSame(64, strlen($xlsx->checksum));
        $this->assertSame(hash_file('sha256', $path), $xlsx->checksum);
        $this->assertNotSame($csv->checksum, $xlsx->checksum, 'different source bytes, different checksum');

        foreach ($csv->sheets as $sheet) {
            $other = $xlsx->sheet($sheet->name);
            $this->assertNotNull($other, $sheet->name);
            $this->assertSame($sheet->headers, $other->headers, $sheet->name);
            $this->assertSame(array_values($sheet->rows), array_values($other->rows), $sheet->name);
        }

        @unlink($path);
    }

    public function test_xlsx_date_numeric_and_boolean_cells_are_rendered_deterministically(): void
    {
        $path = $this->writeXlsx(['Rankings' => [
            ['project', 'keyword', new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2026-07-15 09:30:00')],
            ['Site', 'kw', 12.0, true],
            ['', '', '', ''],
            ['Site', 'kw two', 7.5, null],
        ]]);

        $sheet = app(LegacyWorkbookReader::class)->read($path)->sheet('Rankings');

        $this->assertSame(['project', 'keyword', '2026-07-01', '2026-07-15 09:30:00'], $sheet->headers);
        // The writer drops the all-empty row, so the workbook holds three physical rows; keys follow the sheet's own numbering.
        $this->assertSame([2, 3], array_keys($sheet->rows));
        $this->assertSame(['project' => 'Site', 'keyword' => 'kw', '2026-07-01' => '12', '2026-07-15 09:30:00' => '1'], $sheet->rows[2]);
        $this->assertSame('7.5', $sheet->rows[3]['2026-07-01']);
        $this->assertSame('', $sheet->rows[3]['2026-07-15 09:30:00']);

        @unlink($path);
    }

    public function test_unsupported_sources_and_bad_headers_are_rejected(): void
    {
        $reader = app(LegacyWorkbookReader::class);

        foreach (['missing.xlsx', __FILE__, sys_get_temp_dir()] as $bad) {
            try {
                $reader->read($bad);
                $this->fail("{$bad} should be rejected");
            } catch (InvalidArgumentException $exception) {
                $this->addToAssertionCount(1);
            }
        }

        $path = $this->writeXlsx(['Rankings' => [['project', 'keyword', 'keyword'], ['a', 'b', 'c']]]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate header names: keyword');

        try {
            $reader->read($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_migrating_from_xlsx_produces_the_same_domain_result_as_the_csv_directory(): void
    {
        $this->setUpLegacyFixtureUsers();
        $path = $this->writeXlsx($this->fixtureSheets());

        $run = $this->migrate(dryRun: false, source: $path);

        $this->assertSame(3, Project::query()->count());
        $this->assertSame(9, RankingSnapshot::query()->count());
        $this->assertSame(basename($path), $run->source_filename);
        $this->assertSame(hash_file('sha256', $path), $run->source_checksum, 'the workbook was not modified by reading it');

        @unlink($path);
    }
}
