{{--
    Readiness summary: percentage, bar, "x / y required sections complete"
    and either the missing sections (with the readiness reasons) or the
    ready wording for the report's state. Every value comes from
    ReportReadinessService; nothing is calculated here.

    @var \App\Support\Reports\ReportReadiness $readiness
    @var \App\Models\MonthlyReport $report
--}}
@php
    $percentage = $readiness->percentage();
    $ready = $readiness->isReady();
    $muted = 'class="fi-section-header-description" style="font-size: var(--text-sm)"';
@endphp
<div style="display: grid; gap: calc(var(--spacing) * 3)" data-readiness-summary data-readiness-ready="{{ $ready ? 1 : 0 }}">
    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: baseline; gap: calc(var(--spacing) * 2)">
        <span class="fi-in-text-item" style="font-size: var(--text-sm); font-weight: var(--font-weight-medium)">Report readiness</span>
        <span class="fi-in-text-item" style="font-size: var(--text-2xl); line-height: var(--text-2xl--line-height); font-weight: var(--font-weight-semibold); font-variant-numeric: tabular-nums; color: {{ $ready ? 'var(--success-600)' : 'var(--warning-600)' }}" data-readiness-percentage>{{ $percentage }}%</span>
    </div>
    <div role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percentage }}" aria-label="Report readiness {{ $percentage }}%" style="height: 6px; border-radius: 999px; background: color-mix(in oklab, var(--gray-500) 22%, transparent); overflow: hidden">
        <div style="height: 100%; width: {{ $percentage }}%; border-radius: 999px; background: {{ $ready ? 'var(--success-500)' : 'var(--warning-500)' }}"></div>
    </div>
    <div {!! $muted !!} data-readiness-label>{{ $readiness->completedRequiredCount() }} / {{ $readiness->requiredCount() }} required sections complete</div>

    @if (! $ready)
        <div style="display: grid; gap: calc(var(--spacing) * 2)" data-missing-sections>
            <div class="fi-in-text-item" style="font-size: var(--text-sm); font-weight: var(--font-weight-medium)">Missing required information</div>
            <ul style="display: grid; gap: calc(var(--spacing) * 2); margin: 0; padding: 0; list-style: none">
                @foreach ($readiness->missing() as $missing)
                    <li style="display: grid; gap: calc(var(--spacing) * 0.5)" data-missing="{{ $missing->key->value }}">
                        <div><x-filament::badge color="warning">{{ $missing->title }}</x-filament::badge></div>
                        @if ($missing->reason)
                            <span {!! $muted !!}>{{ $missing->reason }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @elseif ($report->isFinal())
        <p {!! $muted !!} data-report-ready>Report data is complete. This version has been finalised.</p>
    @elseif ($report->isReadyForReview())
        <p {!! $muted !!} data-report-ready>All required sections are complete. Ready for manager review.</p>
    @else
        <p {!! $muted !!} data-report-ready>All required sections are complete. Ready to submit for review.</p>
    @endif
</div>
