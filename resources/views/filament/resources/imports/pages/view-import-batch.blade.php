<x-filament-panels::page>
    @php
        $batch = $this->getBatch();
        $cards = [
            ['label' => 'Total rows', 'value' => $batch->total_rows, 'key' => 'total', 'color' => 'gray'],
            ['label' => 'Valid rows', 'value' => $batch->valid_rows, 'key' => 'valid', 'color' => 'gray'],
            ['label' => 'Imported rows', 'value' => $batch->imported_rows, 'key' => 'imported', 'color' => $batch->imported_rows > 0 ? 'success' : 'gray'],
            ['label' => 'Failed rows', 'value' => $batch->failed_rows, 'key' => 'failed', 'color' => $batch->failed_rows > 0 ? 'danger' : 'gray'],
        ];
    @endphp

    <div
        {{
            (new \Filament\Support\View\ComponentAttributeBag)
                ->grid(['default' => 1, 'sm' => 2, 'xl' => 4])
                ->style(['gap: calc(var(--spacing) * 4)'])
                ->merge(['data-import-summary' => ''], escape: false)
        }}
    >
        @foreach ($cards as $card)
            @php $flagged = $card['color'] !== 'gray'; @endphp
            <div class="fi-wi-stats-overview-stat">
                <div class="fi-wi-stats-overview-stat-content">
                    <div class="fi-wi-stats-overview-stat-label-ctn">
                        <span class="fi-wi-stats-overview-stat-label">{{ $card['label'] }}</span>
                    </div>
                    <div class="fi-wi-stats-overview-stat-value{{ $flagged ? ' fi-color-'.$card['color'].' fi-text-color-600 dark:fi-text-color-400' : '' }}"{!! $flagged ? ' style="color: var(--text)"' : '' !!} data-import-card="{{ $card['key'] }}" data-value="{{ $card['value'] }}">{{ $card['value'] }}</div>
                    <div class="fi-wi-stats-overview-stat-description"><span>{{ $batch->status->getLabel() }}</span></div>
                </div>
            </div>
        @endforeach
    </div>

    <x-filament::section>
        <x-slot name="heading">Import</x-slot>
        <x-slot name="description">{{ $batch->original_filename }} · uploaded {{ $batch->created_at?->format('j M Y H:i') }} by {{ $batch->createdBy?->name ?? '—' }}</x-slot>

        <dl
            {{
                (new \Filament\Support\View\ComponentAttributeBag)
                    ->grid(['default' => 2, 'lg' => 4])
                    ->style(['gap: calc(var(--spacing) * 4)', 'margin: 0'])
                    ->merge(['data-import-details' => ''], escape: false)
            }}
        >
            <div><dt class="fi-wi-stats-overview-stat-label" style="font-size: var(--text-sm); color: var(--gray-500)">Status</dt><dd style="margin: 0"><x-filament::badge :color="$batch->status->getColor()">{{ $batch->status->getLabel() }}</x-filament::badge></dd></div>
            <div><dt style="font-size: var(--text-sm); color: var(--gray-500)">Project</dt><dd style="margin: 0">{{ $batch->project->name }}</dd></div>
            <div><dt style="font-size: var(--text-sm); color: var(--gray-500)">Period</dt><dd style="margin: 0">{{ $batch->monthlyCycle?->periodLabel() ?? '—' }}</dd></div>
            <div><dt style="font-size: var(--text-sm); color: var(--gray-500)">Type</dt><dd style="margin: 0">{{ $batch->import_type->getLabel() }}</dd></div>
            <div><dt style="font-size: var(--text-sm); color: var(--gray-500)">Started</dt><dd style="margin: 0">{{ $batch->started_at?->format('j M Y H:i:s') ?? '—' }}</dd></div>
            <div><dt style="font-size: var(--text-sm); color: var(--gray-500)">Completed</dt><dd style="margin: 0">{{ $batch->completed_at?->format('j M Y H:i:s') ?? '—' }}</dd></div>
            <div><dt style="font-size: var(--text-sm); color: var(--gray-500)">Column mapping</dt><dd style="margin: 0; font-size: var(--text-sm)">
                @forelse ($batch->mapping() as $field => $header)
                    <div data-import-mapping="{{ $field }}">{{ $field }} ← {{ $header }}</div>
                @empty
                    —
                @endforelse
            </dd></div>
        </dl>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Row problems</x-slot>
        <x-slot name="description">Errors block the whole import (nothing is written until every row is valid); warnings are informational.</x-slot>

        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
