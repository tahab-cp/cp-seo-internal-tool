<x-filament-panels::page>
    @php
        $project = $this->getProject();
        $cycles = $this->getCycles();
        $cycle = $this->getSelectedCycle();
        $currentPeriod = $this->getCurrentPeriod();
        $currentMissing = $this->currentCycleIsMissing();
        $canEnsure = $currentMissing && \Illuminate\Support\Facades\Gate::allows('ensureMonthlyCycle', $project);

        $muted = 'class="fi-section-header-description" style="font-size: var(--text-sm)"';
        $row = 'style="display: flex; flex-wrap: wrap; align-items: center; gap: calc(var(--spacing) * 3)"';
        $grid = fn (array $cols, int $gap = 4, array $attrs = []) => (new \Filament\Support\View\ComponentAttributeBag)->grid($cols)->style(["gap: calc(var(--spacing) * {$gap})"])->merge($attrs, escape: false);

        $explanation = fn (\App\Enums\MonthlyCycleStatus $status): string => match ($status) {
            \App\Enums\MonthlyCycleStatus::Open => 'Monthly work can be added for this reporting month.',
            \App\Enums\MonthlyCycleStatus::Reporting => 'The report for this month is being prepared.',
            \App\Enums\MonthlyCycleStatus::Locked => 'The report is final and monthly work is read-only.',
        };
    @endphp

    @include('filament.resources.projects.partials.project-workspace-header', ['project' => $project, 'modules' => $this->getWorkspaceModules('monthly-cycles')])

    {{-- Module title --}}
    <div style="display: grid; gap: calc(var(--spacing) * 1)" data-cycles-header>
        <h2 class="fi-section-header-heading" style="margin: 0; font-size: var(--text-xl); line-height: var(--text-xl--line-height)">Monthly cycles</h2>
        <p {!! $muted !!}>View each reporting month and the targets that were set for it.</p>
    </div>

    {{-- Missing current month --}}
    @if ($currentMissing)
        <div style="display: grid; gap: calc(var(--spacing) * 2); padding: calc(var(--spacing) * 4) calc(var(--spacing) * 5); border: 1px solid color-mix(in oklab, var(--gray-500) 22%, transparent); border-radius: var(--radius-lg)" data-cycles-missing-current="{{ $currentPeriod->label() }}">
            <div class="fi-in-text-item" style="font-size: var(--text-sm); font-weight: var(--font-weight-medium)">No monthly cycle for {{ $currentPeriod->label() }}</div>
            <p {!! $muted !!}>{{ $cycles->isEmpty() ? 'No monthly cycles yet. ' : '' }}This month has not been started for this project yet. Active projects receive a cycle automatically at the start of each month.</p>
            @if ($canEnsure)
                <div><x-filament::button size="sm" icon="heroicon-o-calendar-days" wire:click="mountAction('ensureCurrentMonth')" data-cycles-ensure>Create {{ $currentPeriod->label() }} cycle</x-filament::button></div>
            @endif
        </div>
    @endif

    @if ($cycles->isNotEmpty())
        {{-- Selected month --}}
        <x-filament::section>
            <x-slot name="heading">
                <span {!! $row !!} style="display: inline-flex; gap: calc(var(--spacing) * 2)" data-cycle-heading>
                    {{ $cycle?->periodLabel() ?? 'Reporting month' }}
                    @if ($cycle)
                        <x-filament::badge :color="$cycle->status->getColor()" size="lg" data-cycle-status="{{ $cycle->status->value }}">{{ $cycle->status->getLabel() }}</x-filament::badge>
                        @if ($cycle->period()->equals($currentPeriod))
                            <x-filament::badge color="info" data-cycle-current>Current month</x-filament::badge>
                        @endif
                    @endif
                </span>
            </x-slot>
            <x-slot name="description">{{ $cycle ? $explanation($cycle->status) : 'Select a reporting month to see its status and targets.' }}</x-slot>
            <x-slot name="afterHeader">
                <div {!! $row !!} data-cycles-selector>
                    <label for="cycles-month" class="fi-section-header-description" style="font-size: var(--text-xs); font-weight: var(--font-weight-medium); text-transform: uppercase; letter-spacing: 0.04em">Reporting month</label>
                    <div style="min-width: 12rem">
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="cycles-month" wire:model.live="selectedCycleId">
                                @foreach ($cycles as $option)
                                    <option value="{{ $option->getKey() }}">{{ $option->periodLabel() }}{{ $option->isLocked() ? ' (locked)' : '' }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                </div>
            </x-slot>

            @if ($cycle)
                @php
                    $report = $cycle->monthlyReport;
                    $cards = [
                        ['key' => 'status', 'label' => 'Status', 'value' => $cycle->status->getLabel(), 'helper' => $explanation($cycle->status), 'tone' => $cycle->status->getColor(), 'attr' => 'data-cycle-card-status="'.e($cycle->status->value).'"'],
                        ['key' => 'started', 'label' => 'Started', 'value' => $cycle->started_at?->format('j M Y') ?? '—', 'helper' => 'Cycle created', 'tone' => 'gray', 'attr' => 'data-cycle-started="'.e($cycle->started_at?->toDateString() ?? '').'"'],
                        ['key' => 'lock', 'label' => 'Lock status', 'value' => $cycle->isLocked() ? 'Locked' : 'Not locked', 'helper' => $cycle->isLocked() ? 'Locked on '.($cycle->locked_at?->format('j M Y') ?? '—').($cycle->lockedBy ? ' by '.$cycle->lockedBy->name : '') : 'Monthly work stays editable', 'tone' => 'gray', 'attr' => 'data-cycle-locked="'.($cycle->isLocked() ? 1 : 0).'"'],
                        ['key' => 'report', 'label' => 'Report', 'value' => $report?->status->getLabel() ?? 'Not started', 'helper' => $report ? $report->versionLabel().($report->isCorrection() ? ' · correction in progress' : '') : 'No draft yet', 'tone' => $report?->status->getColor() ?? 'gray', 'attr' => 'data-cycle-report="'.e($report?->status->value ?? 'none').'"'],
                    ];
                @endphp

                <div {{ $grid(['default' => 1, 'sm' => 2, 'xl' => 4], 4, ['data-cycle-summary' => '']) }}>
                    @foreach ($cards as $card)
                        @php $flagged = $card['tone'] !== 'gray'; @endphp
                        <div class="fi-wi-stats-overview-stat" style="padding: calc(var(--spacing) * 4)" data-cycle-card="{{ $card['key'] }}">
                            <div class="fi-wi-stats-overview-stat-content">
                                <div class="fi-wi-stats-overview-stat-label-ctn"><span class="fi-wi-stats-overview-stat-label">{{ $card['label'] }}</span></div>
                                <div class="fi-wi-stats-overview-stat-value{{ $flagged ? ' fi-color-'.$card['tone'].' fi-text-color-600 dark:fi-text-color-400' : '' }}" style="font-size: var(--text-xl); line-height: var(--text-xl--line-height);{{ $flagged ? ' color: var(--text);' : '' }}" {!! $card['attr'] !!}>{{ $card['value'] }}</div>
                                <div class="fi-wi-stats-overview-stat-description"><span>{{ $card['helper'] }}</span></div>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if ($cycle->isLocked())
                    <p {!! $muted !!} style="margin-top: calc(var(--spacing) * 4); font-size: var(--text-sm)" data-cycle-locked-note>This reporting month is locked. Monthly work is read-only.</p>
                @endif

                {{-- Recent months strip --}}
                @if ($cycles->count() > 1)
                    <div {!! $row !!} style="display: flex; flex-wrap: wrap; align-items: center; gap: calc(var(--spacing) * 2); margin-top: calc(var(--spacing) * 5)" data-cycles-strip>
                        <span class="fi-section-header-description" style="font-size: var(--text-xs); font-weight: var(--font-weight-medium); text-transform: uppercase; letter-spacing: 0.04em">Recent months</span>
                        @foreach ($cycles->take(6) as $recent)
                            <x-filament::button
                                size="xs"
                                :color="$recent->is($cycle) ? 'primary' : 'gray'"
                                :outlined="! $recent->is($cycle)"
                                wire:click="$set('selectedCycleId', {{ $recent->getKey() }})"
                                data-cycles-strip-item="{{ $recent->getKey() }}"
                                :aria-current="$recent->is($cycle) ? 'true' : null"
                            >{{ $recent->period()->startOfMonth()->format('M Y') }} · {{ $recent->status->getLabel() }}</x-filament::button>
                        @endforeach
                    </div>
                @endif
            @endif
        </x-filament::section>

        @if ($cycle)
            {{-- Monthly targets --}}
            <x-filament::section>
                <x-slot name="heading">Monthly targets</x-slot>
                <x-slot name="description">These are the targets set for {{ $cycle->periodLabel() }}. Future package changes will not change them.</x-slot>

                @if ($cycle->targets->isEmpty())
                    <p {!! $muted !!} data-cycle-no-targets>No monthly targets were set for this period.</p>
                @else
                    <div {{ $grid(['default' => 2, 'lg' => 4], 4, ['data-cycle-targets' => '']) }}>
                        @foreach ($cycle->targets as $target)
                            <div class="fi-wi-stats-overview-stat" style="padding: calc(var(--spacing) * 4)" data-cycle-target="{{ $target->target_key }}">
                                <div class="fi-wi-stats-overview-stat-content">
                                    <div class="fi-wi-stats-overview-stat-label-ctn"><span class="fi-wi-stats-overview-stat-label">{{ $target->label }}</span></div>
                                    <div class="fi-wi-stats-overview-stat-value" style="font-size: var(--text-2xl); line-height: var(--text-2xl--line-height); font-variant-numeric: tabular-nums" data-target-key="{{ $target->target_key }}">{{ $target->target_value }}</div>
                                    <div class="fi-wi-stats-overview-stat-description"><span>Monthly target</span></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>

            {{-- Work for the month --}}
            <x-filament::section>
                <x-slot name="heading">Work for {{ $cycle->period()->startOfMonth()->format('F') }}</x-slot>
                <x-slot name="description">Open this month's tasks, notes and report.</x-slot>

                <div {!! $row !!} data-cycle-work>
                    @foreach ($this->getWorkLinks($cycle) as $link)
                        <x-filament::button tag="a" :href="$link['url']" color="gray" outlined size="sm" :icon="$link['icon']" data-cycle-work-link="{{ $link['key'] }}">{{ $link['label'] }}</x-filament::button>
                    @endforeach
                </div>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
