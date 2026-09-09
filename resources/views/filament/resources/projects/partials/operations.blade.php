@php
    /** @var \App\Models\Project $project */
    $project = $getRecord();
    $period = \App\Support\MonthlyCycles\CyclePeriod::current();
    $row = app(\App\Services\Dashboard\ProjectOperationsService::class)->rowFor($project, $period);
    $completion = $row->completion;
    $cycle = $row->cycle;
    $progress = $cycle ? app(\App\Services\MonthlyCycles\TargetProgressService::class) : null;
    $url = fn (string $page, array $extra = []) => \App\Filament\Resources\Projects\ProjectResource::getUrl($page, ['record' => $project] + $extra);
@endphp

<div class="space-y-4" data-project-operations data-period="{{ $period->label() }}">
    @if ($cycle === null)
        <div class="rounded-lg border border-danger-200 bg-danger-50 p-3 text-sm text-danger-800 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-200" data-operations-missing-cycle>
            No monthly cycle exists for {{ $period->label() }}.
            @if (\Illuminate\Support\Facades\Gate::allows('ensureMonthlyCycle', $project))
                <a class="underline" href="{{ $url('monthly-cycles') }}">Create it under Monthly cycles.</a>
            @endif
        </div>
    @else
        <div class="grid gap-4 md:grid-cols-3 lg:grid-cols-6">
            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <div class="text-xs text-gray-500">Monthly Target Completion</div>
                <div class="text-2xl font-semibold" data-operations-completion="{{ $completion?->overallPercentage() ?? '' }}">{{ $completion?->label() ?? '—' }}</div>
                <div class="text-xs text-gray-500">{{ $completion?->metCount() ?? 0 }} of {{ $completion?->participating()->count() ?? 0 }} targets met</div>
            </div>
            <a class="rounded-lg border border-gray-200 p-3 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800" href="{{ $url('tasks', ['cycle' => $cycle->getKey()]) }}">
                <div class="text-xs text-gray-500">Tasks</div>
                <div class="text-2xl font-semibold" data-operations-open-tasks="{{ $row->openTasks }}">{{ $row->openTasks }} open</div>
                <div class="text-xs {{ $row->overdueTasks > 0 ? 'text-danger-600' : 'text-gray-500' }}" data-operations-overdue-tasks="{{ $row->overdueTasks }}">{{ $row->overdueTasks }} overdue</div>
            </a>
            <a class="rounded-lg border border-gray-200 p-3 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800" href="{{ $url('pages') }}">
                <div class="text-xs text-gray-500">Pages optimised</div>
                <div class="text-2xl font-semibold" data-operations-pages>{{ $progress->pagesOptimised($cycle)->format() }}</div>
            </a>
            <a class="rounded-lg border border-gray-200 p-3 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800" href="{{ $url('backlinks', ['cycle' => $cycle->getKey()]) }}">
                <div class="text-xs text-gray-500">Backlinks / guest posts</div>
                <div class="text-2xl font-semibold" data-operations-backlinks>{{ $progress->backlinks($cycle)->format() }}</div>
                <div class="text-xs text-gray-500">Guest posts {{ $progress->guestPosts($cycle)->format() }}</div>
            </a>
            <a class="rounded-lg border border-gray-200 p-3 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800" href="{{ $url('content', ['view' => $cycle->getKey()]) }}">
                <div class="text-xs text-gray-500">Blogs published</div>
                <div class="text-2xl font-semibold" data-operations-blogs>{{ $progress->blogs($cycle)->format() }}</div>
            </a>
            <a class="rounded-lg border border-gray-200 p-3 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800" href="{{ $row->report ? $url('report', ['report' => $row->report]) : $url('reports') }}">
                <div class="text-xs text-gray-500">Report</div>
                <div class="text-lg font-semibold" data-operations-report-status="{{ $row->report?->status->value ?? 'none' }}">{{ $row->reportStatusLabel() }}</div>
                <div class="text-xs text-gray-500" data-operations-readiness="{{ $row->readiness?->percentage() ?? '' }}">Readiness {{ $row->readinessLabel() }}</div>
            </a>
        </div>

        @if ($completion && $completion->rows->isNotEmpty())
            <table class="w-full max-w-2xl text-sm" data-operations-targets>
                <thead class="text-left text-xs text-gray-500"><tr><th class="py-1">Target</th><th class="py-1 text-right">Actual / target</th><th class="py-1 text-right">Completion</th></tr></thead>
                <tbody>
                    @foreach ($completion->rows as $target)
                        <tr class="border-t border-gray-100 dark:border-gray-800" data-operations-target="{{ $target->targetKey }}" data-percentage="{{ $target->percentage() ?? '' }}" data-contribution="{{ $target->contribution() ?? '' }}">
                            <td class="py-1">{{ $target->label }}@if (! $target->supported) <span class="text-xs text-gray-400">(no actual tracked)</span>@elseif ($target->target <= 0) <span class="text-xs text-gray-400">(no target set)</span>@endif</td>
                            <td class="py-1 text-right">{{ $target->supported ? $target->display() : '—' }}</td>
                            <td class="py-1 text-right {{ $target->isMet() ? 'text-success-600 font-semibold' : '' }}">{{ $target->percentageLabel() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="text-xs text-gray-500">Individual figures may exceed 100%; each target contributes at most 100% to the overall completion, so over-delivery never hides a missed target.</p>
        @endif
    @endif
</div>
