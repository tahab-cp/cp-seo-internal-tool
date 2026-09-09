<?php

namespace App\Services\Imports\Importers;

use App\Actions\Rankings\RecordRankingSnapshotAction;
use App\Enums\ImportType;
use App\Enums\RankingSource;
use App\Exceptions\ImportRowException;
use App\Models\Keyword;
use App\Models\RankingSnapshot;
use App\Services\Imports\Importers\Concerns\PreparesRows;
use App\Services\Rankings\RankingSnapshotGuard;
use App\Support\Imports\IdentityTracker;
use App\Support\Imports\ImportContext;
use App\Support\Imports\ImportField;
use App\Support\Imports\PreparedRow;
use App\Support\Keywords\KeywordNormalizer;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Ranking observations for one reporting month. Source is ALWAYS
 * csv_import; the CSV cannot choose it.
 *
 *   - keyword resolves by the project's normalised identity; an optional
 *     "location" column disambiguates keywords tracked in several
 *     locations. Unknown keywords are rejected (never created here).
 *   - blank position = Not Ranking (NULL); 0 is rejected by the domain rule.
 *   - the same keyword + checked_at in one CSV is rejected as a duplicate.
 *   - an existing observation with the same keyword + checked_at +
 *     csv_import follows the domain rule: same month → updated in place,
 *     other month → rejected (historical integrity).
 */
class RankingCsvImporter implements CsvImporter
{
    use PreparesRows;

    public function __construct(
        protected RecordRankingSnapshotAction $record,
        protected RankingSnapshotGuard $guard,
    ) {}

    public function type(): ImportType
    {
        return ImportType::RankingSnapshots;
    }

    public function fields(): array
    {
        return [
            ImportField::required('keyword', 'Keyword', ['keywords', 'query', 'term']),
            ImportField::required('checked_at', 'Checked at', ['date', 'checked', 'checked on', 'date checked', 'datetime'], 'YYYY-MM-DD or YYYY-MM-DD HH:MM:SS'),
            ImportField::optional('position', 'Position', ['rank', 'ranking', 'pos'], 'Positive whole number; blank means Not Ranking'),
            ImportField::optional('ranking_url', 'Ranking URL', ['url', 'landing page', 'ranking page']),
            ImportField::optional('location', 'Location', ['geo', 'target location'], 'Only needed when the keyword is tracked in more than one location'),
        ];
    }

    public function prepare(ImportContext $context, array $values, int $rowNumber, IdentityTracker $identities): PreparedRow
    {
        $row = new PreparedRow($rowNumber, $values, []);

        if (! $this->requireFields($row, $values, $this->fields())) {
            return $row;
        }

        $keyword = $this->resolveKeyword($context, $row, trim($values['keyword']), $this->blank($values, 'location') ? null : trim($values['location']));
        $checkedAt = $this->normalise($row, 'checked_at', fn (): string => $this->normalizer()->dateTime($values['checked_at']));
        $position = $this->normalise($row, 'position', fn (): ?int => $this->guard->normalisePosition($this->blank($values, 'position') ? null : trim($values['position'])));
        $url = $this->normalise($row, 'ranking_url', fn (): ?string => $this->guard->normaliseUrl($values['ranking_url'] ?? null));

        if ($keyword === null || $checkedAt === null || ! $row->isValid() && $row->issues !== []) {
            return $row;
        }

        $earlier = $identities->remember($keyword->getKey().'|'.$checkedAt, $rowNumber);

        if ($earlier !== null) {
            $row->addError('checked_at', "Duplicates row {$earlier} (same keyword and checked-at moment).");

            return $row;
        }

        // Surface the cycle-integrity conflict before the user clicks Import.
        $existing = RankingSnapshot::query()
            ->where('keyword_id', $keyword->getKey())
            ->where('checked_at', $checkedAt)
            ->where('source', RankingSource::CsvImport->value)
            ->first();

        if ($existing !== null && (int) $existing->monthly_cycle_id !== $context->cycleId()) {
            $row->addError('checked_at', sprintf('An observation for "%s" at %s already exists in %s and cannot be re-recorded against %s.', $keyword->keyword, $checkedAt, $existing->monthlyCycle->periodLabel(), $context->cycle->periodLabel()));

            return $row;
        }

        if ($existing !== null) {
            $row->addWarning('checked_at', 'An observation already exists for this keyword and moment; it will be updated in place.');
        }

        $row->data = [
            'keyword_id' => (int) $keyword->getKey(),
            'checked_at' => $checkedAt,
            'position' => $position,
            'ranking_url' => $url,
        ];

        return $row;
    }

    public function import(ImportContext $context, Collection $rows): int
    {
        $written = 0;

        foreach ($rows as $row) {
            try {
                $this->record->handle($context->project, $row->data + [
                    'monthly_cycle_id' => $context->cycleId(),
                    'source' => RankingSource::CsvImport,
                ]);

                $written++;
            } catch (Throwable $exception) {
                throw ImportRowException::forRow($row->rowNumber, $exception);
            }
        }

        return $written;
    }

    protected function resolveKeyword(ImportContext $context, PreparedRow $row, string $keyword, ?string $location): ?Keyword
    {
        $normalised = KeywordNormalizer::keyword($keyword);

        $matches = Keyword::query()
            ->where('project_id', $context->project->getKey())
            ->where('keyword_normalized', $normalised)
            ->orderBy('id')
            ->get();

        if ($location !== null) {
            $matches = $matches->where('location_normalized', KeywordNormalizer::location($location))->values();
        }

        if ($matches->isEmpty()) {
            $row->addError('keyword', sprintf('Unknown keyword "%s"%s in this project; keywords are not created by a ranking import.', $keyword, $location !== null ? " ({$location})" : ''));

            return null;
        }

        if ($matches->count() > 1) {
            $anyLocation = $matches->firstWhere('location_normalized', '');

            if ($location === null && $anyLocation !== null) {
                return $anyLocation;
            }

            $row->addError('keyword', sprintf('Keyword "%s" is tracked in several locations (%s); add a location column to say which one.', $keyword, $matches->map(fn (Keyword $k): string => $k->displayLocation())->implode(', ')));

            return null;
        }

        return $matches->first();
    }
}
