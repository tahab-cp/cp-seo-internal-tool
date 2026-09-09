<?php

namespace App\Services\Imports\Importers;

use App\Actions\Keywords\CreateKeywordAction;
use App\Actions\Keywords\KeywordAttributes;
use App\Actions\Keywords\UpdateKeywordAction;
use App\Enums\ImportType;
use App\Exceptions\ImportRowException;
use App\Models\Keyword;
use App\Models\Page;
use App\Services\Imports\Importers\Concerns\PreparesRows;
use App\Support\Imports\IdentityTracker;
use App\Support\Imports\ImportContext;
use App\Support\Imports\ImportField;
use App\Support\Imports\PreparedRow;
use App\Support\Keywords\KeywordNormalizer;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Project-level keywords.
 *
 * Duplicate rule (deterministic): the identity is the normalised keyword
 * plus normalised location within the project, exactly as manual entry.
 *   - identity already tracked  → that keyword is UPDATED; blank CSV values
 *                                  leave the existing value unchanged
 *   - new identity              → keyword CREATED (defaults as manual entry)
 *   - identity twice in the CSV → the later row is rejected
 *
 * target_page_url resolves only against the project's own pages by exact
 * URL (or path). An unknown URL is a WARNING: the target is left unset
 * (create) or unchanged (update); pages are never created here.
 */
class KeywordCsvImporter implements CsvImporter
{
    use PreparesRows;

    public function __construct(
        protected CreateKeywordAction $create,
        protected UpdateKeywordAction $update,
    ) {}

    public function type(): ImportType
    {
        return ImportType::Keywords;
    }

    public function fields(): array
    {
        return [
            ImportField::required('keyword', 'Keyword', ['keywords', 'query', 'term', 'search term']),
            ImportField::optional('location', 'Location', ['geo', 'target location', 'city', 'country']),
            ImportField::optional('target_page_url', 'Target page URL', ['target page', 'target url', 'url', 'page', 'landing page'], 'Matched against the project\'s pages; unknown URLs are left unlinked with a warning.'),
            ImportField::optional('keyword_role', 'Keyword role', ['role'], 'primary or secondary'),
            ImportField::optional('search_volume', 'Search volume', ['volume', 'sv', 'monthly searches']),
            ImportField::optional('keyword_difficulty', 'Keyword difficulty', ['kd', 'difficulty']),
            ImportField::optional('search_intent', 'Search intent', ['intent'], 'informational, navigational, commercial, transactional, local or unknown'),
            ImportField::optional('is_branded', 'Branded', ['branded', 'brand'], '1/0, true/false or yes/no'),
            ImportField::optional('status', 'Status', [], 'active, paused or archived'),
        ];
    }

    public function prepare(ImportContext $context, array $values, int $rowNumber, IdentityTracker $identities): PreparedRow
    {
        $row = new PreparedRow($rowNumber, $values, []);

        if (! $this->requireFields($row, $values, $this->fields())) {
            return $row;
        }

        $attributes = [];

        // Identity fields go through the same normaliser as manual entry.
        $keyword = $this->normalise($row, 'keyword', fn (): array => KeywordAttributes::normalise(['keyword' => $values['keyword']]));
        $location = $this->normalise($row, 'location', fn (): array => KeywordAttributes::normalise(['location' => $values['location'] ?? '']));

        if ($keyword === null || $location === null) {
            return $row;
        }

        $attributes['keyword'] = $keyword['keyword'];

        if (! $this->blank($values, 'location')) {
            $attributes['location'] = $location['location'];
        }

        foreach (['keyword_role' => 'keyword role', 'search_volume' => 'search volume', 'keyword_difficulty' => 'keyword difficulty', 'search_intent' => 'search intent', 'status' => 'status'] as $field => $label) {
            if ($this->blank($values, $field)) {
                continue;
            }

            $normalised = $this->normalise($row, $field, fn (): array => KeywordAttributes::normalise([$field => trim($values[$field])]));

            if ($normalised !== null) {
                $attributes[$field] = $normalised[$field];
            }
        }

        if (! $this->blank($values, 'is_branded')) {
            $branded = $this->normalise($row, 'is_branded', fn (): bool => $this->normalizer()->boolean($values['is_branded']));

            if ($branded !== null) {
                $attributes['is_branded'] = $branded;
            }
        }

        $identity = $keyword['keyword_normalized'].'|'.$location['location_normalized'];
        $earlier = $identities->remember($identity, $rowNumber);

        if ($earlier !== null) {
            $row->addError('keyword', "Duplicates row {$earlier} (same keyword and location).");
        }

        $existing = Keyword::withTrashed()
            ->where('project_id', $context->project->getKey())
            ->where('keyword_normalized', $keyword['keyword_normalized'])
            ->where('location_normalized', $location['location_normalized'])
            ->first();

        $targetPageId = null;
        $targetResolved = false;

        if (! $this->blank($values, 'target_page_url')) {
            $page = $this->resolvePage($context, trim($values['target_page_url']));

            if ($page === null) {
                $row->addWarning('target_page_url', sprintf('No page with URL "%s" exists in this project; the target page is left %s.', trim($values['target_page_url']), $existing ? 'unchanged' : 'empty'));
            } else {
                $targetPageId = (int) $page->getKey();
                $targetResolved = true;
            }
        }

        if (! $row->isValid() && $row->issues !== []) {
            return $row;
        }

        $row->data = [
            'mode' => $existing ? 'update' : 'create',
            'keyword_id' => $existing?->getKey(),
            'attributes' => $attributes,
            'target_page_id' => $targetPageId,
            'target_resolved' => $targetResolved,
        ];

        return $row;
    }

    public function import(ImportContext $context, Collection $rows): int
    {
        $written = 0;

        foreach ($rows as $row) {
            try {
                $data = $row->data;
                $attributes = $data['attributes'];

                if ($data['mode'] === 'update') {
                    $keyword = Keyword::withTrashed()->findOrFail($data['keyword_id']);

                    if ($data['target_resolved']) {
                        $attributes['target_page_id'] = $data['target_page_id'];
                    }

                    $this->update->handle($keyword, $attributes);
                } else {
                    $this->create->handle($context->project, $attributes + ['target_page_id' => $data['target_page_id']]);
                }

                $written++;
            } catch (Throwable $exception) {
                throw ImportRowException::forRow($row->rowNumber, $exception);
            }
        }

        return $written;
    }

    protected function resolvePage(ImportContext $context, string $url): ?Page
    {
        $candidates = array_values(array_unique([$url, rtrim($url, '/'), rtrim($url, '/').'/']));

        return Page::query()
            ->where('project_id', $context->project->getKey())
            ->where(fn ($query) => $query->whereIn('url', $candidates)->orWhereIn('path', $candidates))
            ->orderBy('id')
            ->first();
    }

    public static function identityOf(string $keyword, ?string $location): string
    {
        return KeywordNormalizer::keyword($keyword).'|'.KeywordNormalizer::location($location);
    }
}
