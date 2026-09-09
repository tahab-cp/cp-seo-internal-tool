<?php

namespace App\Services\Imports\Importers;

use App\Actions\Analytics\SaveGscPageMetricsAction;
use App\Enums\ImportType;
use App\Models\Page;
use App\Services\Analytics\AnalyticsIntegrityGuard;
use App\Services\Imports\Importers\Concerns\PreparesRows;
use App\Services\Imports\Importers\Concerns\ReplacesMonthlyDataset;
use App\Support\Imports\IdentityTracker;
use App\Support\Imports\ImportContext;
use App\Support\Imports\ImportField;
use App\Support\Imports\PreparedRow;
use Illuminate\Support\Collection;

/**
 * Search Console landing-page rows for one month. page_url is the source
 * value; when it equals the URL of one of the PROJECT's pages, page_id is
 * linked, otherwise it stays null. Pages are never created and a page of
 * another project can never be linked.
 */
class GscPageCsvImporter implements CsvImporter
{
    use PreparesRows;
    use ReplacesMonthlyDataset;

    public function __construct(
        protected SaveGscPageMetricsAction $save,
        protected AnalyticsIntegrityGuard $guard,
    ) {}

    public function type(): ImportType
    {
        return ImportType::GscPages;
    }

    public function fields(): array
    {
        return [
            ImportField::required('page_url', 'Page URL', ['top pages', 'pages', 'page', 'url', 'landing page']),
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

        $url = $this->normalise($row, 'page_url', fn (): string => $this->guard->normaliseUrl($values['page_url']));

        $data = [
            'page_url' => $url,
            'page_id' => null,
            'clicks' => $this->normalise($row, 'clicks', fn (): int => $this->guard->normaliseCount(trim($values['clicks']), 'clicks', required: true)),
            'impressions' => $this->normalise($row, 'impressions', fn (): int => $this->guard->normaliseCount(trim($values['impressions']), 'impressions', required: true)),
            'ctr' => $this->normalise($row, 'ctr', fn (): ?float => $this->guard->normalisePercentage(rtrim(trim($values['ctr']), '%'), 'CTR')),
            'average_position' => $this->normalise($row, 'average_position', fn (): ?float => $this->guard->normalisePosition($this->normalizer()->text($values['average_position'] ?? null), 'average position')),
        ];

        if (! $row->isValid() && $row->issues !== []) {
            return $row;
        }

        $earlier = $identities->remember($this->guard->identityKey($url), $rowNumber);

        if ($earlier !== null) {
            $row->addError('page_url', "Duplicates row {$earlier} (same page URL).");

            return $row;
        }

        $data['page_id'] = Page::query()
            ->where('project_id', $context->project->getKey())
            ->where('url', $url)
            ->orderBy('id')
            ->value('id');

        $row->data = $data;

        return $row;
    }

    public function import(ImportContext $context, Collection $rows): int
    {
        return $this->saveDataset($context, $rows, fn ($cycle, $payload, $user) => $this->save->handle($cycle, $payload, $user));
    }
}
