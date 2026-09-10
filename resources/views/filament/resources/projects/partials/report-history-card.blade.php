{{--
    One reporting month in the Report History list. Everything shown comes
    from the state array built by ProjectReports::historyState(): the cycle,
    its report (if any), the live readiness and the archived revisions.
    Record actions (Open / View / PDF / Create draft) are rendered by the
    table inside the same card.
--}}
@php
    /** @var array{cycle: \App\Models\MonthlyCycle, report: ?\App\Models\MonthlyReport, readiness: ?\App\Support\Reports\ReportReadiness, revisions: \Illuminate\Support\Collection, current: bool, revision_urls: array<int, array{view: string, pdf: ?string}>} $state */
    $state = $getState();
    $cycle = $state['cycle'];
    $report = $state['report'];
    $readiness = $state['readiness'];
    $revisions = $state['revisions'];
    $percentage = $readiness?->percentage();
    $ready = $readiness?->isReady() ?? false;

    $label = 'class="fi-section-header-description" style="font-size: var(--text-xs); font-weight: var(--font-weight-medium); text-transform: uppercase; letter-spacing: 0.04em"';
    $muted = 'class="fi-section-header-description" style="font-size: var(--text-sm)"';
    $value = 'class="fi-in-text-item" style="margin: 0; font-size: var(--text-lg); font-weight: var(--font-weight-semibold); font-variant-numeric: tabular-nums"';
    $row = 'style="display: flex; flex-wrap: wrap; align-items: center; gap: calc(var(--spacing) * 2)"';
    $grid = fn (array $cols, int $gap = 4, array $attrs = []) => (new \Filament\Support\View\ComponentAttributeBag)->grid($cols)->style(["gap: calc(var(--spacing) * {$gap})"])->merge($attrs, escape: false);
@endphp

<div style="display: grid; gap: calc(var(--spacing) * 4); width: 100%; min-width: 0" data-report-history-card="{{ $cycle->getKey() }}" data-history-status="{{ $report?->status->value ?? 'none' }}" data-history-current="{{ $state['current'] ? 1 : 0 }}">
    {{-- Period, status and version --}}
    <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: calc(var(--spacing) * 3)">
        <div style="display: grid; gap: calc(var(--spacing) * 0.5)">
            <h3 class="fi-section-header-heading" style="margin: 0; font-size: var(--text-lg); line-height: var(--text-lg--line-height)" data-history-period>{{ $cycle->periodLabel() }}</h3>
            @if ($cycle->isLocked())
                <span class="fi-section-header-description" style="font-size: var(--text-xs)" data-history-locked>Reporting period locked</span>
            @endif
        </div>
        <div {!! $row !!} data-history-badges>
            @if ($state['current'])
                <x-filament::badge color="info" data-history-current-badge>Current</x-filament::badge>
            @endif
            @include('filament.reports.partials.status-badge', ['report' => $report, 'size' => 'md'])
            @if ($report)
                <x-filament::badge color="gray" data-history-version="{{ $report->version }}">{{ $report->versionLabel() }}</x-filament::badge>
            @endif
        </div>
    </div>

    @if ($report && $readiness)
        {{-- Readiness | Required sections | Finalised --}}
        <div {{ $grid(['default' => 1, 'sm' => 2, 'xl' => 3], 4, ['data-history-metrics' => '']) }}>
            <div style="display: grid; gap: calc(var(--spacing) * 1.5)" data-history-metric="readiness">
                <div style="display: flex; justify-content: space-between; align-items: baseline; gap: calc(var(--spacing) * 2)">
                    <span {!! $label !!}>Readiness</span>
                    <span class="fi-in-text-item" style="margin: 0; font-size: var(--text-lg); font-weight: var(--font-weight-semibold); font-variant-numeric: tabular-nums; color: {{ $ready ? 'var(--success-600)' : 'var(--warning-600)' }}" data-history-readiness="{{ $percentage }}">{{ $percentage }}%</span>
                </div>
                <div role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percentage }}" aria-label="Readiness {{ $percentage }}%" style="height: 6px; border-radius: 999px; background: color-mix(in oklab, var(--gray-500) 22%, transparent); overflow: hidden">
                    <div style="height: 100%; width: {{ $percentage }}%; border-radius: 999px; background: {{ $ready ? 'var(--success-500)' : 'var(--warning-500)' }}"></div>
                </div>
            </div>
            <div style="display: grid; gap: calc(var(--spacing) * 1)" data-history-metric="sections">
                <span {!! $label !!}>Required sections</span>
                <span class="fi-in-text-item" style="font-size: var(--text-sm)" data-history-sections="{{ $readiness->completedRequiredCount() }}/{{ $readiness->requiredCount() }}">{{ $readiness->completedRequiredCount() }} of {{ $readiness->requiredCount() }} complete</span>
            </div>
            <div style="display: grid; gap: calc(var(--spacing) * 1)" data-history-metric="finalised">
                <span {!! $label !!}>Finalised</span>
                @if ($report->isFinal())
                    <span class="fi-in-text-item" style="font-size: var(--text-sm)" data-history-finalised="{{ $report->finalized_at?->toDateString() }}">{{ $report->finalized_at?->format('j M Y') }}</span>
                    @if ($report->finalizedBy)
                        <span class="fi-section-header-description" style="font-size: var(--text-xs)" data-history-finalised-by>{{ $report->finalizedBy->name }}</span>
                    @endif
                @else
                    <span {!! $muted !!} data-history-finalised="">—</span>
                @endif
            </div>
        </div>

        {{-- Missing sections or the complete state --}}
        @if ($ready)
            <div {!! $row !!} data-history-complete>
                <span aria-hidden="true" style="display: inline-flex; width: 1.1rem; height: 1.1rem; align-items: center; justify-content: center; border-radius: 999px; background: var(--success-500); color: #fff; font-size: 0.65rem; font-weight: var(--font-weight-semibold)">✓</span>
                <span class="fi-in-text-item" style="font-size: var(--text-sm)">All required sections complete</span>
            </div>
        @else
            <div style="display: grid; gap: calc(var(--spacing) * 1.5)" data-history-missing>
                <span {!! $label !!}>Missing</span>
                <div {!! $row !!}>
                    @foreach ($readiness->missing() as $missing)
                        <x-filament::badge color="warning" size="sm" data-history-missing-section="{{ $missing->key->value }}">{{ $missing->title }}</x-filament::badge>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Correction: the preserved previous final --}}
        @if ($report->isCorrection() && $revisions->isNotEmpty())
            @php $previous = $revisions->first(); @endphp
            <div {!! $muted !!} data-history-previous-final="{{ $previous->version }}">Previous final: {{ $previous->versionLabel() }} • Finalised {{ $previous->finalized_at?->format('j M Y') }}{{ $previous->finalizedBy ? ' by '.$previous->finalizedBy->name : '' }}</div>
        @elseif ($revisions->isNotEmpty())
            <div {!! $row !!} data-history-previous-versions>
                <span {!! $muted !!}>Previous versions:</span>
                @foreach ($revisions as $revision)
                    <span {!! $row !!} style="display: inline-flex; gap: calc(var(--spacing) * 1.5)" data-history-revision="{{ $revision->version }}">
                        <span class="fi-in-text-item" style="font-size: var(--text-sm); font-weight: var(--font-weight-medium)">{{ $revision->versionLabel() }}</span>
                        <x-filament::link :href="$state['revision_urls'][$revision->getKey()]['view']" target="_blank" size="sm">View</x-filament::link>
                        @if ($state['revision_urls'][$revision->getKey()]['pdf'])
                            <x-filament::link :href="$state['revision_urls'][$revision->getKey()]['pdf']" target="_blank" size="sm">PDF</x-filament::link>
                        @endif
                    </span>
                @endforeach
            </div>
        @endif
    @else
        <p {!! $muted !!} data-history-not-started>{{ $cycle->isLocked() ? 'No report was started for this month before it was locked.' : 'No report started yet.' }}</p>
    @endif
</div>
