<x-filament-panels::page>
    @php
        $counts = $this->getStatusCounts();
        $grid = fn (array $cols, int $gap = 4, array $attrs = []) => (new \Filament\Support\View\ComponentAttributeBag)->grid($cols)->style(["gap: calc(var(--spacing) * {$gap})"])->merge($attrs, escape: false);
        $cards = [
            ['key' => 'draft', 'label' => 'Draft', 'value' => $counts['draft'], 'helper' => 'Being prepared', 'tone' => 'gray'],
            ['key' => 'ready', 'label' => 'Ready for review', 'value' => $counts['ready_for_review'], 'helper' => 'Waiting for a manager', 'tone' => $counts['ready_for_review'] > 0 ? 'warning' : 'gray'],
            ['key' => 'final', 'label' => 'Final', 'value' => $counts['final'], 'helper' => 'Locked and delivered', 'tone' => $counts['final'] > 0 ? 'success' : 'gray'],
            ['key' => 'correction', 'label' => 'Corrections', 'value' => $counts['correction'], 'helper' => 'Unlocked for correction', 'tone' => $counts['correction'] > 0 ? 'warning' : 'gray'],
        ];
    @endphp

    <div {{ $grid(['default' => 2, 'xl' => 4], 4, ['data-reports-overview-summary' => '']) }}>
        @foreach ($cards as $card)
            @php $flagged = $card['tone'] !== 'gray'; @endphp
            <div class="fi-wi-stats-overview-stat" style="padding: calc(var(--spacing) * 4)" data-reports-overview-card="{{ $card['key'] }}">
                <div class="fi-wi-stats-overview-stat-content">
                    <div class="fi-wi-stats-overview-stat-label-ctn"><span class="fi-wi-stats-overview-stat-label">{{ $card['label'] }}</span></div>
                    <div class="fi-wi-stats-overview-stat-value{{ $flagged ? ' fi-color-'.$card['tone'].' fi-text-color-600 dark:fi-text-color-400' : '' }}" style="font-size: var(--text-2xl); line-height: var(--text-2xl--line-height); font-variant-numeric: tabular-nums;{{ $flagged ? ' color: var(--text);' : '' }}" data-reports-overview-count="{{ $card['value'] }}">{{ $card['value'] }}</div>
                    <div class="fi-wi-stats-overview-stat-description"><span>{{ $card['helper'] }}</span></div>
                </div>
            </div>
        @endforeach
    </div>

    {{ $this->table }}
</x-filament-panels::page>
