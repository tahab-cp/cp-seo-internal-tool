{{--
    Report status badge: Draft / Ready for review / Final, with a
    "· Correction" suffix while a superseded final is being corrected.
    Presentation only; the status and correction flag come from the model.

    @var \App\Models\MonthlyReport|null $report
    @var string $size  ('sm' | 'md' | 'lg')
--}}
@php
    $size = $size ?? 'md';
@endphp
@if ($report)
    <x-filament::badge :color="$report->status->getColor()" :size="$size" data-report-status-badge="{{ $report->status->value }}" data-report-correction="{{ $report->isCorrection() ? 1 : 0 }}">{{ $report->status->getLabel() }}{{ $report->isCorrection() ? ' · Correction' : '' }}</x-filament::badge>
@else
    <x-filament::badge color="gray" :size="$size" data-report-status-badge="none">Not started</x-filament::badge>
@endif
