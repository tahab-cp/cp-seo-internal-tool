<?php

namespace App\Services\Imports\Importers;

use App\Actions\Analytics\SaveGa4CountryMetricsAction;
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
 * GA4 audience-by-country rows for one month. Country identity is the
 * trimmed, whitespace-collapsed name compared case-insensitively, exactly
 * as manual entry. Engagement rate keeps the human percentage convention
 * (8.5 means 8.5%).
 */
class Ga4CountryCsvImporter implements CsvImporter
{
    use PreparesRows;
    use ReplacesMonthlyDataset;

    public function __construct(
        protected SaveGa4CountryMetricsAction $save,
        protected AnalyticsIntegrityGuard $guard,
    ) {}

    public function type(): ImportType
    {
        return ImportType::Ga4Countries;
    }

    public function fields(): array
    {
        return [
            ImportField::required('country', 'Country', ['country id', 'country name']),
            ImportField::optional('active_users', 'Active users', ['users']),
            ImportField::optional('new_users', 'New users', []),
            ImportField::optional('sessions', 'Sessions', []),
            ImportField::optional('engaged_sessions', 'Engaged sessions', []),
            ImportField::optional('engagement_rate', 'Engagement rate', [], 'Percentage 0-100 (8.5 means 8.5%)'),
            ImportField::optional('event_count', 'Event count', ['events']),
            ImportField::optional('key_events', 'Key events', ['conversions']),
        ];
    }

    public function prepare(ImportContext $context, array $values, int $rowNumber, IdentityTracker $identities): PreparedRow
    {
        $row = new PreparedRow($rowNumber, $values, []);

        if (! $this->requireFields($row, $values, $this->fields())) {
            return $row;
        }

        $country = $this->normalise($row, 'country', fn (): string => $this->guard->normaliseCountry($values['country']));

        $data = ['country' => $country];

        foreach (['active_users' => 'active users', 'new_users' => 'new users', 'sessions' => 'sessions', 'engaged_sessions' => 'engaged sessions', 'event_count' => 'event count', 'key_events' => 'key events'] as $field => $label) {
            $data[$field] = $this->normalise($row, $field, fn (): ?int => $this->guard->normaliseCount($this->normalizer()->text($values[$field] ?? null), $label));
        }

        $data['engagement_rate'] = $this->normalise($row, 'engagement_rate', fn (): ?float => $this->guard->normalisePercentage($this->normalizer()->text(rtrim((string) ($values['engagement_rate'] ?? ''), '%')), 'engagement rate'));

        if (! $row->isValid() && $row->issues !== []) {
            return $row;
        }

        $earlier = $identities->remember($this->guard->identityKey($country), $rowNumber);

        if ($earlier !== null) {
            $row->addError('country', "Duplicates row {$earlier} (same country).");

            return $row;
        }

        $row->data = $data;

        return $row;
    }

    public function import(ImportContext $context, Collection $rows): int
    {
        return $this->saveDataset($context, $rows, fn ($cycle, $payload, $user) => $this->save->handle($cycle, $payload, $user));
    }
}
