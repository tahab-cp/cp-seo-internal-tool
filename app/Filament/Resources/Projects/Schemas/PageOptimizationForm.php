<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Enums\MonthlyCycleStatus;
use App\Models\MonthlyCycle;
use App\Models\PageOptimization;
use App\Models\Project;
use Closure;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Validation\Rule;

/**
 * Record / edit optimisation fields. The actions re-validate every rule,
 * including same-project cycle, lock state and at-least-one change.
 */
class PageOptimizationForm
{
    /**
     * @return array<int, Component>
     */
    public static function components(Project $project, ?int $defaultCycleId = null): array
    {
        $cycles = $project->monthlyCycles()
            ->where('status', '!=', MonthlyCycleStatus::Locked->value)
            ->latestPeriodFirst()
            ->get();

        $flags = [];

        foreach (PageOptimization::CHANGE_FLAGS as $flag => $label) {
            $flags[] = Checkbox::make($flag)
                ->label($label.' updated')
                ->default(false);
        }

        // The last checkbox carries the "at least one change" rule so the
        // message appears once, next to the group.
        $flags[array_key_last($flags)]->rules([
            fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                foreach (array_keys(PageOptimization::CHANGE_FLAGS) as $flag) {
                    if (filter_var($get($flag), FILTER_VALIDATE_BOOLEAN)) {
                        return;
                    }
                }

                $fail('Select at least one change made to the page.');
            },
        ]);

        return [
            Select::make('monthly_cycle_id')
                ->label('Reporting month')
                ->options($cycles->mapWithKeys(fn (MonthlyCycle $cycle): array => [$cycle->id => $cycle->periodLabel()])->all())
                ->default($defaultCycleId)
                ->required()
                ->native(false)
                ->rule(Rule::exists('monthly_cycles', 'id')
                    ->where('project_id', $project->getKey())
                    ->where('status', '!=', MonthlyCycleStatus::Locked->value))
                ->helperText('Locked months cannot be selected.'),
            DateTimePicker::make('optimized_at')
                ->label('Optimised at')
                ->required()
                ->default(now())
                ->seconds(false)
                ->native(false),
            ...$flags,
            Textarea::make('notes')
                ->rows(3)
                ->maxLength(5000)
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fillFromOptimization(PageOptimization $optimization): array
    {
        $data = [
            'monthly_cycle_id' => $optimization->monthly_cycle_id,
            'optimized_at' => $optimization->optimized_at?->toDateTimeString(),
            'notes' => $optimization->notes,
        ];

        foreach (array_keys(PageOptimization::CHANGE_FLAGS) as $flag) {
            $data[$flag] = (bool) $optimization->{$flag};
        }

        return $data;
    }
}
