<?php

namespace App\Services\Imports\Importers;

use App\Actions\Analytics\SaveGscQueryMetricsAction;
use App\Enums\ImportType;
use App\Services\Analytics\AnalyticsIntegrityGuard;
use App\Services\Imports\Importers\Concerns\PreparesRows;
use App\Services\Imports\Importers\Concerns\ReplacesMonthlyDataset;
use App\Support\Imports\IdentityTracker;
use App\Support\Imports\ImportContext;
use App\Support\Imports\ImportField;
use App\Support\Imports\PreparedRow;
use Illuminate\Support\Collection;

/**
 * Search Console query rows for one month. Identity is the normalised
 * query (trimmed, whitespace-collapsed, lower-cased) exactly as manual
 * entry; tracked Keywords are never touched.
 */
class GscQueryCsvImporter implements CsvImporter
{
    use PreparesRows;
    use ReplacesMonthlyDataset;

    public function __construct(
        protected SaveGscQueryMetricsAction $save,
        protected AnalyticsIntegrityGuard $guard,
    ) {}

    public function type(): ImportType
    {
        return ImportType::GscQueries;
    }

    public function fields(): array
    {
        return [
            ImportField::required('query', 'Query', ['top queries', 'queries', 'search query', 'keyword']),
            ImportField::required('clicks', 'Clicks', []),
            ImportField::required('impressions', 'Impressions', []),
            ImportField::required('ctr', 'CTR', ['click through rate'], 'Percentage 0-100 (8.5 means 8.5%)'),
            ImportField::optional('average_position', 'Average position', ['position', 'avg position', 'avg. position']),
        ];
    }

    public function prepare(ImportContext $context, array $values, int $rowNumber, IdentityTracker $identities): PreparedRow
    {
        $row = new PreparedRow($rowNumber, $values, []);

        if (! $this->requireFields($row, $values, $this->fields())) {
            return $row;
        }

        $query = $this->normalise($row, 'query', fn (): string => $this->guard->normaliseQuery($values['query']));

        $data = [
            'query' => $query,
            'clicks' => $this->normalise($row, 'clicks', fn (): int => $this->guard->normaliseCount(trim($values['clicks']), 'clicks', required: true)),
            'impressions' => $this->normalise($row, 'impressions', fn (): int => $this->guard->normaliseCount(trim($values['impressions']), 'impressions', required: true)),
            'ctr' => $this->normalise($row, 'ctr', fn (): ?float => $this->guard->normalisePercentage($this->percent($values['ctr']), 'CTR')),
            'average_position' => $this->normalise($row, 'average_position', fn (): ?float => $this->guard->normalisePosition($this->normalizer()->text($values['average_position'] ?? null), 'average position')),
        ];

        if (! $row->isValid() && $row->issues !== []) {
            return $row;
        }

        $earlier = $identities->remember($this->guard->identityKey($query), $rowNumber);

        if ($earlier !== null) {
            $row->addError('query', "Duplicates row {$earlier} (same query).");

            return $row;
        }

        $row->data = $data;

        return $row;
    }

    public function import(ImportContext $context, Collection $rows): int
    {
        return $this->saveDataset($context, $rows, fn ($cycle, $payload, $user) => $this->save->handle($cycle, $payload, $user));
    }

    /**
     * Search Console exports write "8.5%"; the trailing sign is dropped
     * and the human percentage convention (0-100) is kept unchanged.
     */
    protected function percent(string $value): string
    {
        return rtrim(trim($value), '%');
    }
}
