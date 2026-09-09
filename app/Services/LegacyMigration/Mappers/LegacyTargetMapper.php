<?php

namespace App\Services\LegacyMigration\Mappers;

use App\Support\LegacyMigration\LegacySheet;
use App\Support\LegacyMigration\MigrationContext;
use App\Support\LegacyMigration\MigrationOutcome;
use InvalidArgumentException;

/**
 * "Targets" sheet: the target that APPLIED in a historical month
 * (project, period, target key, value). Values are registered with the
 * cycle resolver so a cycle created later in the run is snapshotted with
 * exactly these numbers. An existing cycle is never re-snapshotted: equal
 * values are a SKIP, different values a CONFLICT to review.
 */
class LegacyTargetMapper extends LegacySheetMapper
{
    public function sheet(): string
    {
        return 'Targets';
    }

    public function fields(): array
    {
        return [
            'project' => ['aliases' => ['website', 'project name'], 'required' => true],
            'period' => ['aliases' => ['month', 'reporting month', 'year month'], 'required' => true],
            'target_key' => ['aliases' => ['target', 'key', 'deliverable'], 'required' => true],
            'target_value' => ['aliases' => ['value', 'target amount', 'monthly target'], 'required' => true],
            'label' => ['aliases' => ['target label', 'name']],
        ];
    }

    public function migrateGroup(MigrationContext $context, LegacySheet $sheet, string $group, array $rows): void
    {
        $byPeriod = [];

        foreach ($rows as $rowNumber => $row) {
            try {
                $this->requireValues($row, 'project', 'period', 'target_key', 'target_value');

                $project = $this->project($context, $row);
                $period = $context->values->period($this->value($row, 'period'));
                $key = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $this->value($row, 'target_key')) ?? '', '_'));
                $value = $this->value($row, 'target_value');

                if ($key === '' || ! is_numeric($value) || (int) $value != $value || (int) $value < 0) {
                    throw new InvalidArgumentException(sprintf('Target "%s" must have a key and a whole-number value of 0 or more; [%s] given.', $this->value($row, 'target_key'), $value));
                }

                $periodKey = sprintf('%04d-%02d', $period->year, $period->month);
                $byPeriod[$periodKey]['project'] = $project;
                $byPeriod[$periodKey]['period'] = $period;
                $byPeriod[$periodKey]['rows'][$rowNumber] = $key;
                $byPeriod[$periodKey]['targets'][$key] = ['label' => $this->optional($row, 'label') ?? ucwords(str_replace('_', ' ', $key)), 'target_value' => (int) $value];
            } catch (InvalidArgumentException $exception) {
                $context->tally('targets', MigrationOutcome::CONFLICT);
                $context->error($sheet->name, $rowNumber, 'target', $exception->getMessage(), $row);
            }
        }

        foreach ($byPeriod as $entry) {
            $existing = $entry['project']->monthlyCycles()->forPeriod($entry['period'])->with('targets')->first();

            if ($existing === null) {
                $context->cycles->registerTargets($entry['project'], $entry['period'], $entry['targets']);

                continue;
            }

            // Never rewrite a historical snapshot; report agreement or disagreement.
            $current = $existing->targets->pluck('target_value', 'target_key')->map(fn ($v): int => (int) $v)->all();

            foreach ($entry['rows'] as $rowNumber => $key) {
                $wanted = $entry['targets'][$key]['target_value'];

                if (array_key_exists($key, $current) && $current[$key] === $wanted) {
                    $context->tally('targets', MigrationOutcome::SKIP);
                } else {
                    $context->tally('targets', MigrationOutcome::CONFLICT);
                    $context->warning($sheet->name, $rowNumber, 'target', sprintf('%s of "%s" already has a target snapshot (%s = %s); the legacy value %d was NOT applied.', $entry['period']->label(), $entry['project']->name, $key, array_key_exists($key, $current) ? $current[$key] : 'absent', $wanted));
                }
            }
        }
    }
}
