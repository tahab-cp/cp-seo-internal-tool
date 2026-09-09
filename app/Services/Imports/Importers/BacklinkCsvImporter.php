<?php

namespace App\Services\Imports\Importers;

use App\Actions\Backlinks\CreateBacklinkAction;
use App\Enums\BacklinkStatus;
use App\Enums\BacklinkType;
use App\Enums\ImportType;
use App\Exceptions\ImportRowException;
use App\Services\Backlinks\BacklinkGuard;
use App\Services\Imports\Importers\Concerns\PreparesRows;
use App\Support\Imports\IdentityTracker;
use App\Support\Imports\ImportContext;
use App\Support\Imports\ImportField;
use App\Support\Imports\PreparedRow;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Backlink records for one reporting month, created through
 * CreateBacklinkAction with the importing user as creator. There is no
 * URL uniqueness: the same published URL may legitimately appear several
 * times. Type and status must be given explicitly (blank is never
 * interpreted as planned).
 */
class BacklinkCsvImporter implements CsvImporter
{
    use PreparesRows;

    public function __construct(
        protected CreateBacklinkAction $create,
        protected BacklinkGuard $guard,
    ) {}

    public function type(): ImportType
    {
        return ImportType::Backlinks;
    }

    public function fields(): array
    {
        return [
            ImportField::required('published_url', 'Published URL', ['url', 'backlink url', 'source url', 'referring page']),
            ImportField::required('type', 'Type', ['backlink type', 'link type'], implode(', ', array_map(fn (BacklinkType $t): string => $t->value, BacklinkType::cases()))),
            ImportField::required('status', 'Status', ['link status'], implode(', ', array_map(fn (BacklinkStatus $s): string => $s->value, BacklinkStatus::cases()))),
            ImportField::optional('anchor_text', 'Anchor text', ['anchor']),
            ImportField::optional('target_url', 'Target URL', ['target', 'target page', 'destination']),
            ImportField::optional('published_date', 'Published date', ['date', 'published', 'live date'], 'YYYY-MM-DD'),
            ImportField::optional('domain_authority', 'Domain authority', ['da', 'moz da']),
            ImportField::optional('domain_rating', 'Domain rating', ['dr', 'ahrefs dr']),
            ImportField::optional('spam_score', 'Spam score', ['spam']),
            ImportField::optional('notes', 'Notes', ['note', 'comment', 'comments']),
        ];
    }

    public function prepare(ImportContext $context, array $values, int $rowNumber, IdentityTracker $identities): PreparedRow
    {
        $row = new PreparedRow($rowNumber, $values, []);

        if (! $this->requireFields($row, $values, $this->fields())) {
            return $row;
        }

        $data = [
            'published_url' => $this->normalise($row, 'published_url', fn (): string => $this->guard->normaliseUrl($values['published_url'], true, 'published URL')),
            'target_url' => $this->normalise($row, 'target_url', fn (): ?string => $this->guard->normaliseUrl($values['target_url'] ?? null, false, 'target URL')),
            'anchor_text' => $this->normalise($row, 'anchor_text', fn (): ?string => $this->guard->normaliseText($values['anchor_text'] ?? null, 255, 'anchor text')),
            'type' => $this->normalise($row, 'type', fn (): BacklinkType => $this->guard->normaliseType(strtolower(trim($values['type'])))),
            'status' => $this->normalise($row, 'status', fn (): BacklinkStatus => $this->guard->normaliseStatus(strtolower(trim($values['status'])))),
            'published_date' => $this->blank($values, 'published_date') ? null : $this->normalise($row, 'published_date', fn (): string => $this->normalizer()->date($values['published_date'])),
            'domain_authority' => $this->normalise($row, 'domain_authority', fn (): ?int => $this->guard->normaliseMetric($this->text($values, 'domain_authority'), 'domain authority')),
            'domain_rating' => $this->normalise($row, 'domain_rating', fn (): ?int => $this->guard->normaliseMetric($this->text($values, 'domain_rating'), 'domain rating')),
            'spam_score' => $this->normalise($row, 'spam_score', fn (): ?int => $this->guard->normaliseMetric($this->text($values, 'spam_score'), 'spam score')),
            'notes' => $this->normalise($row, 'notes', fn (): ?string => $this->guard->normaliseText($values['notes'] ?? null, 5000, 'notes')),
        ];

        if (! $row->isValid() && $row->issues !== []) {
            return $row;
        }

        $data['type'] = $data['type']->value;
        $data['status'] = $data['status']->value;
        $row->data = $data;

        return $row;
    }

    public function import(ImportContext $context, Collection $rows): int
    {
        $written = 0;

        foreach ($rows as $row) {
            try {
                $this->create->handle($context->project, $row->data + ['monthly_cycle_id' => $context->cycleId()], $context->user);
                $written++;
            } catch (Throwable $exception) {
                throw ImportRowException::forRow($row->rowNumber, $exception);
            }
        }

        return $written;
    }

    protected function text(array $values, string $key): ?string
    {
        return $this->normalizer()->text($values[$key] ?? null);
    }
}
