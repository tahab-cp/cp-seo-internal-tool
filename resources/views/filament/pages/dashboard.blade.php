<x-filament-panels::page>
    @php
        $overview = $this->getOverview();
        $period = $overview->period;
        $my = $overview->myWork;
        $reviewQueue = $this->getReviewQueue();
        $canReview = $this->canReview();
        $cards = [
            ['label' => 'Active projects', 'value' => $overview->activeProjects, 'key' => 'active_projects', 'color' => 'gray'],
            ['label' => 'Missing monthly cycles', 'value' => $overview->missingCycles, 'key' => 'missing_cycles', 'color' => $overview->missingCycles > 0 ? 'danger' : 'gray'],
            ['label' => 'Open tasks', 'value' => $overview->openTasks, 'key' => 'open_tasks', 'color' => 'gray'],
            ['label' => 'Overdue tasks', 'value' => $overview->overdueTasks, 'key' => 'overdue_tasks', 'color' => $overview->overdueTasks > 0 ? 'danger' : 'gray'],
            ['label' => 'Reports ready for review', 'value' => $overview->reportsReadyForReview, 'key' => 'reports_ready', 'color' => $overview->reportsReadyForReview > 0 ? 'warning' : 'gray'],
            ['label' => 'Reports still draft', 'value' => $overview->reportsDraft, 'key' => 'reports_draft', 'color' => 'gray'],
            ['label' => 'Reports not started', 'value' => $overview->reportsNotStarted, 'key' => 'reports_not_started', 'color' => 'gray'],
            ['label' => 'Final reports', 'value' => $overview->reportsFinal, 'key' => 'reports_final', 'color' => 'success'],
        ];
    @endphp

    {{--
        Summary cards: Filament's own grid (1 / sm:2 / xl:4 columns) and stat
        card styles, which the panel stylesheet ships, so the layout holds
        without a compiled theme. Values keep their semantic status colour.
    --}}
    <div
        {{
            (new \Filament\Support\View\ComponentAttributeBag)
                ->grid(['default' => 1, 'sm' => 2, 'xl' => 4])
                ->style(['gap: calc(var(--spacing) * 4)'])
                ->merge(['data-dashboard-cards' => '', 'data-dashboard-period' => $period->label()], escape: false)
        }}
    >
        @foreach ($cards as $card)
            @php $flagged = $card['color'] !== 'gray'; @endphp
            <div class="fi-wi-stats-overview-stat">
                <div class="fi-wi-stats-overview-stat-content">
                    <div class="fi-wi-stats-overview-stat-label-ctn">
                        <span class="fi-wi-stats-overview-stat-label">{{ $card['label'] }}</span>
                    </div>
                    <div class="fi-wi-stats-overview-stat-value{{ $flagged ? ' fi-color-'.$card['color'].' fi-text-color-600 dark:fi-text-color-400' : '' }}"{!! $flagged ? ' style="color: var(--text)"' : '' !!} data-dashboard-card="{{ $card['key'] }}" data-value="{{ $card['value'] }}">{{ $card['value'] }}</div>
                    <div class="fi-wi-stats-overview-stat-description"><span>{{ $period->label() }}</span></div>
                </div>
            </div>
        @endforeach
    </div>

    {{--
        Every major block below is a direct child of the page content, which
        Filament lays out as a single-column grid with a fixed row gap, so
        the sections read as separate blocks with consistent spacing.
    --}}

    {{-- Needs attention --}}
    <x-filament::section>
        <x-slot name="heading">Needs attention</x-slot>
        <x-slot name="description">Projects with actionable conditions for {{ $period->label() }}, most urgent first.</x-slot>

        @if ($overview->attention->isEmpty())
            <p class="text-sm text-gray-500" data-attention-empty>Nothing needs attention right now.</p>
        @else
            <ul class="divide-y divide-gray-100 dark:divide-gray-800" data-attention-queue>
                @foreach ($overview->attention as $item)
                    <li class="py-3" data-attention-item="{{ $item->project->getKey() }}" data-attention-reasons="{{ implode(',', $item->reasonCodes()) }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <a class="font-medium hover:underline" href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('view', ['record' => $item->project]) }}">{{ $item->title() }}</a>
                            <div class="flex flex-wrap gap-2">
                                @if ($item->cycle === null && \Illuminate\Support\Facades\Gate::allows('ensureMonthlyCycle', $item->project))
                                    <x-filament::link :href="\App\Filament\Resources\Projects\ProjectResource::getUrl('monthly-cycles', ['record' => $item->project])" size="sm">Monthly cycles</x-filament::link>
                                @endif
                                @if ($item->hasReason(\App\Support\Dashboard\AttentionItem::OVERDUE_TASKS))
                                    <x-filament::link :href="\App\Filament\Resources\Projects\ProjectResource::getUrl('tasks', ['record' => $item->project])" size="sm">Tasks</x-filament::link>
                                @endif
                                @if ($item->report)
                                    <x-filament::link :href="\App\Filament\Resources\Projects\ProjectResource::getUrl('report', ['record' => $item->project, 'report' => $item->report])" size="sm">Report</x-filament::link>
                                @elseif ($item->cycle)
                                    <x-filament::link :href="\App\Filament\Resources\Projects\ProjectResource::getUrl('reports', ['record' => $item->project])" size="sm">Reports</x-filament::link>
                                @endif
                            </div>
                        </div>
                        <ul class="mt-1 list-disc pl-5 text-sm text-gray-600 dark:text-gray-300">
                            @foreach ($item->reasons as $reason)
                                <li data-attention-reason="{{ $reason['code'] }}"><span class="font-medium">{{ $reason['label'] }}</span>@if ($reason['detail']) <span class="text-gray-500">— {{ $reason['detail'] }}</span>@endif</li>
                            @endforeach
                        </ul>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>

    {{-- My work --}}
    <x-filament::section>
        <x-slot name="heading">My work</x-slot>
        <x-slot name="description">Tasks assigned to you.</x-slot>

        {{--
            Four compact metric boxes in one row from the lg breakpoint,
            two per row below it. Boxes reuse the summary card style
            (same border, background, equal height) with tighter padding
            and a smaller value so the section stays compact.
        --}}
        @php
            $metrics = [
                ['label' => 'Overdue', 'value' => $my['overdue'], 'attr' => 'data-my-work-overdue', 'flagged' => $my['overdue'] > 0],
                ['label' => 'Due today', 'value' => $my['due_today'], 'attr' => 'data-my-work-today', 'flagged' => false],
                ['label' => 'Due this week', 'value' => $my['due_this_week'], 'attr' => 'data-my-work-week', 'flagged' => false],
                ['label' => 'Open', 'value' => $my['open'], 'attr' => 'data-my-work-open', 'flagged' => false],
            ];
        @endphp
        <dl
            {{
                (new \Filament\Support\View\ComponentAttributeBag)
                    ->grid(['default' => 2, 'lg' => 4])
                    ->style(['gap: calc(var(--spacing) * 4)', 'margin: 0'])
                    ->merge(['data-my-work' => ''], escape: false)
            }}
        >
            @foreach ($metrics as $metric)
                <div class="fi-wi-stats-overview-stat" style="padding: calc(var(--spacing) * 4)">
                    <div class="fi-wi-stats-overview-stat-content">
                        <dt class="fi-wi-stats-overview-stat-label">{{ $metric['label'] }}</dt>
                        <dd class="fi-wi-stats-overview-stat-value{{ $metric['flagged'] ? ' fi-color-danger fi-text-color-600 dark:fi-text-color-400' : '' }}" style="margin: 0; font-size: var(--text-2xl); line-height: var(--text-2xl--line-height);{{ $metric['flagged'] ? ' color: var(--text);' : '' }}" {{ $metric['attr'] }}="{{ $metric['value'] }}">{{ $metric['value'] }}</dd>
                    </div>
                </div>
            @endforeach
        </dl>

        <div style="margin-top: calc(var(--spacing) * 4); display: flex; flex-wrap: wrap; align-items: center; gap: calc(var(--spacing) * 4)">
            <x-filament::button tag="a" size="sm" color="gray" :href="\App\Filament\Pages\MyTasks::getUrl()">Open My Tasks</x-filament::button>

            @if ($this->canSeeTeamWorkload())
                <x-filament::link :href="\App\Filament\Pages\TeamWorkload::getUrl()" size="sm">Team workload</x-filament::link>
            @endif
        </div>
    </x-filament::section>

    {{-- Ready for review queue (reviewers only) --}}
    @if ($canReview)
        <x-filament::section>
            <x-slot name="heading">Ready for review</x-slot>
            <x-slot name="description">Reports awaiting a manager's review and finalization for {{ $period->label() }}. Each one is finalized deliberately, one at a time.</x-slot>

            @if ($reviewQueue->isEmpty())
                <p class="text-sm text-gray-500" data-review-queue-empty>No reports are waiting for review.</p>
            @else
                <table class="w-full text-sm" data-review-queue>
                    <thead class="text-left text-xs text-gray-500"><tr><th class="py-1">Client</th><th class="py-1">Project</th><th class="py-1">Period</th><th class="py-1">Version</th><th class="py-1">Readiness</th><th class="py-1">Marked ready</th><th class="py-1 text-right"></th></tr></thead>
                    <tbody>
                        @foreach ($reviewQueue as $row)
                            @php $marked = $row->report->auditEvents()->where('event_type', 'report_marked_ready')->with('user')->latest('id')->first(); @endphp
                            <tr class="border-t border-gray-100 dark:border-gray-800" data-review-item="{{ $row->report->getKey() }}">
                                <td class="py-2">{{ $row->project->client?->name ?? '—' }}</td>
                                <td class="py-2 font-medium">{{ $row->project->name }}</td>
                                <td class="py-2">{{ $row->cycle->periodLabel() }}</td>
                                <td class="py-2">{{ $row->report->versionLabel() }}@if ($row->report->isCorrection()) <span class="text-xs text-gray-500">(correction)</span>@endif</td>
                                <td class="py-2">{{ $row->readinessLabel() }}</td>
                                <td class="py-2 text-xs text-gray-500">{{ $marked ? ($marked->user?->name ?? 'Unknown').' · '.$marked->created_at?->format('j M Y H:i') : '—' }}</td>
                                <td class="py-2 text-right"><x-filament::link :href="$this->reportUrl($row)" size="sm">Review</x-filament::link></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            <div class="mt-3">
                <x-filament::link :href="\App\Filament\Pages\ReportsOverview::getUrl()" size="sm">All reports</x-filament::link>
            </div>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
