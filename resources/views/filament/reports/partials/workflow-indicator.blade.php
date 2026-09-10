{{--
    Draft → Ready for review → Final, with the current step highlighted.
    Presentation only: the step follows the report's status.

    @var \App\Models\MonthlyReport $report
--}}
@php
    $current = match (true) {
        $report->isFinal() => 3,
        $report->isReadyForReview() => 2,
        default => 1,
    };
    $steps = [1 => 'Draft', 2 => 'Ready for review', 3 => 'Final'];
@endphp
<ol style="display: flex; flex-wrap: wrap; align-items: center; gap: calc(var(--spacing) * 2); margin: 0; padding: 0; list-style: none" aria-label="Report workflow" data-report-workflow="{{ $current }}">
    @foreach ($steps as $step => $label)
        @php
            $state = $step < $current ? 'done' : ($step === $current ? 'current' : 'todo');
            $color = match ($state) { 'done' => 'var(--success-600)', 'current' => 'var(--primary-600)', default => 'var(--gray-500)' };
        @endphp
        <li style="display: flex; align-items: center; gap: calc(var(--spacing) * 1.5)" data-workflow-step="{{ $step }}" data-workflow-state="{{ $state }}">
            <span aria-hidden="true" style="display: inline-flex; width: 1.25rem; height: 1.25rem; align-items: center; justify-content: center; border-radius: 999px; font-size: 0.7rem; font-weight: var(--font-weight-semibold); border: 2px solid {{ $color }}; color: {{ $state === 'current' ? '#fff' : $color }}; background: {{ $state === 'current' ? $color : 'transparent' }}">{{ $state === 'done' ? '✓' : $step }}</span>
            <span class="fi-in-text-item" style="font-size: var(--text-sm); font-weight: {{ $state === 'current' ? 'var(--font-weight-semibold)' : 'var(--font-weight-normal)' }}; color: {{ $state === 'todo' ? 'var(--gray-500)' : 'inherit' }}">{{ $label }}</span>
        </li>
        @unless ($loop->last)
            <li aria-hidden="true" style="width: 1.5rem; height: 2px; background: color-mix(in oklab, var(--gray-500) 30%, transparent); border-radius: 999px"></li>
        @endunless
    @endforeach
</ol>
