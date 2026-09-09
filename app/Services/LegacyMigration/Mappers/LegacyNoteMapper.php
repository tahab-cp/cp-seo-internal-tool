<?php

namespace App\Services\LegacyMigration\Mappers;

use App\Actions\Notes\CreateMonthlyNoteAction;
use App\Enums\MonthlyNoteType;
use App\Support\LegacyMigration\LegacySheet;
use App\Support\LegacyMigration\MigrationContext;
use App\Support\LegacyMigration\MigrationOutcome;
use InvalidArgumentException;

/**
 * "Notes" sheet: narrative items copied from old monthly reports where the
 * source carries an explicit type (win, challenge, observation,
 * recommendation, next month focus). Cell text without a type column is
 * never turned into a note.
 */
class LegacyNoteMapper extends LegacySheetMapper
{
    public function __construct(
        protected CreateMonthlyNoteAction $createNote,
    ) {}

    public function sheet(): string
    {
        return 'Notes';
    }

    public function fields(): array
    {
        return [
            'project' => ['aliases' => ['website', 'project name'], 'required' => true],
            'period' => ['aliases' => ['month', 'reporting month'], 'required' => true],
            'type' => ['aliases' => ['note type', 'section'], 'required' => true],
            'body' => ['aliases' => ['note', 'text', 'content'], 'required' => true],
            'title' => ['aliases' => ['heading']],
        ];
    }

    public function migrateGroup(MigrationContext $context, LegacySheet $sheet, string $group, array $rows): void
    {
        foreach ($rows as $rowNumber => $row) {
            try {
                $this->requireValues($row, 'project', 'period', 'type', 'body');

                $project = $this->project($context, $row);
                $period = $context->values->period($this->value($row, 'period'));
                $type = $context->values->enum('note_type', $this->value($row, 'type'), MonthlyNoteType::class);

                $fingerprint = $context->ledger::fingerprint(['note', $project->getKey(), sprintf('%04d-%02d', $period->year, $period->month), $type->value, $this->value($row, 'body')]);

                if (! $this->shouldCreate($context, $sheet, $rowNumber, 'notes', $fingerprint)) {
                    continue;
                }

                $cycle = $this->cycle($context, $project, $period, $sheet, $rowNumber);

                if ($cycle === null) {
                    $context->tally('notes', MigrationOutcome::CONFLICT);

                    continue;
                }

                $note = $this->createNote->handle($cycle, [
                    'type' => $type,
                    'title' => $this->optional($row, 'title'),
                    'body' => $this->value($row, 'body'),
                ], $context->actor);

                $context->ledger->record($sheet->name, $rowNumber, $fingerprint, 'monthly_note', (int) $note->getKey());
                $context->tally('notes', MigrationOutcome::CREATE);
            } catch (InvalidArgumentException $exception) {
                $context->tally('notes', MigrationOutcome::CONFLICT);
                $context->error($sheet->name, $rowNumber, 'note', $exception->getMessage(), $row);
            }
        }
    }
}
