<?php

namespace App\Services\LegacyMigration\Mappers;

use App\Actions\Backlinks\CreateBacklinkAction;
use App\Enums\BacklinkStatus;
use App\Enums\BacklinkType;
use App\Support\LegacyMigration\LegacySheet;
use App\Support\LegacyMigration\MigrationContext;
use App\Support\LegacyMigration\MigrationOutcome;
use InvalidArgumentException;

/**
 * "Backlinks" sheet: operational link-building records. Legacy status and
 * type wording resolves only through the enum or the explicit mapping.
 * There is no URL uniqueness; idempotency comes from the ledger
 * fingerprint (project, published URL, date, anchor, type).
 */
class LegacyBacklinkMapper extends LegacySheetMapper
{
    public function __construct(
        protected CreateBacklinkAction $createBacklink,
    ) {}

    public function sheet(): string
    {
        return 'Backlinks';
    }

    public function fields(): array
    {
        return [
            'project' => ['aliases' => ['website', 'project name'], 'required' => true],
            'published_url' => ['aliases' => ['url', 'link', 'backlink url', 'source url', 'live url'], 'required' => true],
            'type' => ['aliases' => ['link type', 'backlink type'], 'required' => true],
            'status' => ['aliases' => ['link status'], 'required' => true],
            'published_date' => ['aliases' => ['date', 'published', 'live date']],
            'period' => ['aliases' => ['month', 'reporting month']],
            'anchor_text' => ['aliases' => ['anchor']],
            'target_url' => ['aliases' => ['target', 'target page', 'destination']],
            'domain_authority' => ['aliases' => ['da', 'moz da']],
            'domain_rating' => ['aliases' => ['dr', 'ahrefs dr']],
            'spam_score' => ['aliases' => ['spam']],
            'notes' => ['aliases' => ['note', 'comments']],
        ];
    }

    public function migrateGroup(MigrationContext $context, LegacySheet $sheet, string $group, array $rows): void
    {
        foreach ($rows as $rowNumber => $row) {
            try {
                $this->requireValues($row, 'project', 'published_url', 'type', 'status');

                $project = $this->project($context, $row);
                $date = $this->blank($row, 'published_date') ? null : $context->values->date($this->value($row, 'published_date'));
                $period = $this->period($context, $row, $date);
                $type = $context->values->enum('backlink_type', $this->value($row, 'type'), BacklinkType::class);
                $status = $context->values->enum('backlink_status', $this->value($row, 'status'), BacklinkStatus::class);

                $fingerprint = $context->ledger::fingerprint(['backlink', $project->getKey(), $this->value($row, 'published_url'), $date, $this->value($row, 'anchor_text'), $type->value]);

                if (! $this->shouldCreate($context, $sheet, $rowNumber, 'backlinks', $fingerprint)) {
                    continue;
                }

                $cycle = $this->cycle($context, $project, $period, $sheet, $rowNumber);

                if ($cycle === null) {
                    $context->tally('backlinks', MigrationOutcome::CONFLICT);

                    continue;
                }

                $backlink = $this->createBacklink->handle($project, [
                    'monthly_cycle_id' => $cycle->getKey(),
                    'published_url' => $this->value($row, 'published_url'),
                    'anchor_text' => $this->optional($row, 'anchor_text'),
                    'target_url' => $this->optional($row, 'target_url'),
                    'type' => $type,
                    'status' => $status,
                    'published_date' => $date,
                    'domain_authority' => $this->optional($row, 'domain_authority'),
                    'domain_rating' => $this->optional($row, 'domain_rating'),
                    'spam_score' => $this->optional($row, 'spam_score'),
                    'notes' => $this->optional($row, 'notes'),
                ], $context->actor);

                $context->ledger->record($sheet->name, $rowNumber, $fingerprint, 'backlink', (int) $backlink->getKey());
                $context->tally('backlinks', MigrationOutcome::CREATE);
            } catch (InvalidArgumentException $exception) {
                $context->tally('backlinks', MigrationOutcome::CONFLICT);
                $context->error($sheet->name, $rowNumber, 'backlink', $exception->getMessage(), $row);
            }
        }
    }
}
