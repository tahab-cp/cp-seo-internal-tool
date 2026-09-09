<?php

namespace Tests\Feature\Imports;

use App\Actions\Keywords\KeywordAttributes;
use App\Exceptions\CsvImportException;
use App\Services\Analytics\AnalyticsIntegrityGuard;
use App\Services\Imports\CsvColumnMapper;
use App\Services\Imports\CsvReader;
use App\Services\Imports\CsvValueNormalizer;
use App\Services\Rankings\RankingSnapshotGuard;
use App\Support\Imports\ImportField;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * CSV-level behaviour: parsing, file rules, mapping and the strict value
 * normalisers. No database needed.
 */
class CsvCoreTest extends TestCase
{
    protected function reader(): CsvReader
    {
        return app(CsvReader::class);
    }

    protected function upload(string $content, string $name = 'file.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public function test_quoted_commas_quotes_and_crlf_line_endings_parse_correctly(): void
    {
        $csv = "keyword,notes,volume\r\n\"seo london, uk\",\"He said \"\"hi\"\"\",100\r\nplain,,\r\n";

        $document = $this->reader()->inspectUpload($this->upload($csv));

        $this->assertSame(['keyword', 'notes', 'volume'], $document->headers);
        $this->assertSame(2, $document->rowCount());
        $this->assertSame(['seo london, uk', 'He said "hi"', '100'], $document->rows[2]);
        $this->assertSame(['plain', '', ''], $document->rows[3]);
        $this->assertSame(['keyword' => 'plain', 'notes' => '', 'volume' => ''], $document->assoc($document->rows[3]));
    }

    public function test_utf8_bom_is_stripped_from_the_first_header(): void
    {
        $document = $this->reader()->inspectUpload($this->upload("\xEF\xBB\xBFkeyword,volume\nseo,10\n"));

        $this->assertSame(['keyword', 'volume'], $document->headers);
        $this->assertSame('seo', $document->assoc($document->rows[2])['keyword']);
    }

    public function test_blank_lines_are_ignored_and_row_numbers_follow_the_file(): void
    {
        $csv = "\n\nkeyword\n\nfirst\n   \n,\nsecond\n";

        $document = $this->reader()->inspectUpload($this->upload($csv));

        // Header is line 3; "first" is line 5; "second" is line 8 (blank and comma-only lines skipped).
        $this->assertSame([5, 8], array_keys($document->rows));
        $this->assertSame('first', $document->rows[5][0]);
    }

    public function test_unsupported_file_types_are_rejected(): void
    {
        foreach (['data.xlsx', 'data.xls', 'script.php', 'macro.xlsm', 'file.exe'] as $name) {
            try {
                $this->reader()->inspectUpload($this->upload("a,b\n1,2\n", $name));
                $this->fail("{$name} should be rejected");
            } catch (CsvImportException $exception) {
                $this->assertStringContainsString('Only CSV files', $exception->getMessage());
            }
        }

        // Binary content behind a .csv name is rejected by MIME sniffing.
        $renamed = UploadedFile::fake()->createWithContent('photo.csv', "\x89PNG\r\n\x1a\n".str_repeat("\x00\x01\x02\xFF", 32));

        $this->expectException(CsvImportException::class);
        $this->expectExceptionMessage('does not look like a CSV');
        $this->reader()->inspectUpload($renamed);
    }

    public function test_oversized_files_are_rejected_before_parsing(): void
    {
        config(['imports.max_file_bytes' => 64]);

        $this->expectException(CsvImportException::class);
        $this->expectExceptionMessage('larger than');
        $this->reader()->inspectUpload($this->upload("keyword\n".str_repeat("a very long keyword row\n", 20)));
    }

    public function test_row_limit_is_enforced(): void
    {
        config(['imports.max_rows' => 3]);

        $this->expectException(CsvImportException::class);
        $this->expectExceptionMessage('more than 3 data rows');
        $this->reader()->inspectUpload($this->upload("keyword\na\nb\nc\nd\n"));
    }

    public function test_missing_blank_or_duplicate_headers_are_rejected(): void
    {
        foreach ([
            '' => 'empty',
            "\n\n" => 'no header row',
            ",,\n" => 'no header row',
            "keyword,,volume\nseo,1,2\n" => 'Header column 2 is blank',
            "keyword,keyword\nseo,seo\n" => 'Duplicate header names: keyword',
        ] as $csv => $message) {
            try {
                $this->reader()->inspectUpload($this->upload($csv));
                $this->fail('Expected rejection for '.json_encode($csv));
            } catch (CsvImportException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage(), json_encode($csv));
            }
        }
    }

    public function test_mapping_requires_every_required_field_and_rejects_unknown_or_reused_headers(): void
    {
        $mapper = app(CsvColumnMapper::class);
        $fields = [ImportField::required('keyword', 'Keyword', ['term']), ImportField::optional('search_volume', 'Search volume', ['volume'])];

        $this->assertSame(['keyword' => 'Term', 'search_volume' => 'Volume'], $mapper->suggest(['Term', 'Volume'], $fields));
        $this->assertSame(['keyword' => 'KEYWORD '], $mapper->suggest(['KEYWORD ', 'Unrelated'], $fields));
        $this->assertSame(['search_volume' => 'Search-Volume'], $mapper->suggest(['Search-Volume'], $fields));

        $this->assertSame(['keyword' => 'Term'], $mapper->validate(['keyword' => 'Term', 'search_volume' => ''], ['Term', 'Volume'], $fields));

        foreach ([
            [['search_volume' => 'Volume'], 'required field "Keyword" is not mapped'],
            [['keyword' => 'Nope'], 'no column named "Nope"'],
            [['keyword' => 'Term', 'search_volume' => 'Term'], 'mapped to more than one field'],
        ] as [$mapping, $message]) {
            try {
                $mapper->validate($mapping, ['Term', 'Volume'], $fields);
                $this->fail('Expected mapping rejection');
            } catch (CsvImportException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    public function test_boolean_normalisation_accepts_the_documented_set_only(): void
    {
        $normalizer = app(CsvValueNormalizer::class);

        foreach (['1', 'true', 'TRUE', 'Yes', ' yes '] as $value) {
            $this->assertTrue($normalizer->boolean($value), $value);
        }

        foreach (['0', 'false', 'False', 'NO', 'no'] as $value) {
            $this->assertFalse($normalizer->boolean($value), $value);
        }

        foreach (['y', 'n', 'maybe', '2', 'on', 'off', 'x', 'ja'] as $ambiguous) {
            try {
                $normalizer->boolean($ambiguous);
                $this->fail("{$ambiguous} should be rejected");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('not a recognised yes/no value', $exception->getMessage());
            }
        }
    }

    public function test_supported_dates_parse_deterministically_and_ambiguous_forms_are_rejected(): void
    {
        $normalizer = app(CsvValueNormalizer::class);

        $this->assertSame('2026-09-05', $normalizer->date('2026-09-05'));
        $this->assertSame('2026-09-05', $normalizer->date(' 2026-09-05 '));
        $this->assertSame('2026-09-05 00:00:00', $normalizer->dateTime('2026-09-05'));
        $this->assertSame('2026-09-05 14:30:00', $normalizer->dateTime('2026-09-05 14:30'));
        $this->assertSame('2026-09-05 14:30:15', $normalizer->dateTime('2026-09-05 14:30:15'));
        $this->assertSame('2026-09-05 14:30:15', $normalizer->dateTime('2026-09-05T14:30:15'));

        foreach (['01/02/2026', '02-01-2026', '1.2.2026', '2026/02/01', '2026-02-30', '2026-13-01', 'yesterday', '20260201', ''] as $ambiguous) {
            try {
                $normalizer->date($ambiguous);
                $this->fail("{$ambiguous} should be rejected");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('ambiguous', $exception->getMessage());
            }
        }

        foreach (['01/02/2026 10:00', '2026-09-05 25:00', '2026-09-05 10:00:99'] as $ambiguous) {
            try {
                $normalizer->dateTime($ambiguous);
                $this->fail("{$ambiguous} should be rejected");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        // A time is not accepted where a plain date is required.
        $this->expectException(InvalidArgumentException::class);
        $normalizer->date('2026-09-05 10:00');
    }

    public function test_numeric_validation_is_the_existing_domain_rule_not_an_import_specific_one(): void
    {
        // Import handlers hand values to the same normalisers manual entry uses.
        $this->expectExceptionMessageMatches('/search volume must be a non-negative integer/');

        try {
            $this->assertSame(0, app(RankingSnapshotGuard::class)->normalisePosition('0'));
            $this->fail('position 0 must be rejected');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('positive integer or empty', $exception->getMessage());
        }

        $this->assertNull(app(RankingSnapshotGuard::class)->normalisePosition(''));
        $this->assertSame(8.5, app(AnalyticsIntegrityGuard::class)->normalisePercentage('8.5', 'CTR'));

        try {
            app(AnalyticsIntegrityGuard::class)->normaliseCount('-1', 'clicks', required: true);
            $this->fail('negative counts must be rejected');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('whole number of 0 or more', $exception->getMessage());
        }

        KeywordAttributes::normalise(['search_volume' => '-5']);
    }
}
