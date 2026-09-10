<x-filament-panels::page>
    @php
        $project = $this->getProject();
        $period = $this->getPeriod();
        $ops = $this->getOperations();
        $cycle = $ops->cycle;
        $completion = $ops->completion;
        $deliverables = $this->getDeliverables();
        $targets = $this->getResolvedTargets();
        $team = $this->getTeamMembers();
        $website = preg_replace('#^https?://(www\.)?#i', '', rtrim((string) $project->website_url, '/'));

        $label ='class="fi-section-header-description" style="font-size: var(--text-xs); font-weight: var(--font-weight-medium); text-transform: uppercase; letter-spacing: 0.04em"';
        $value = 'class="fi-in-text-item" style="margin: 0; font-size: var(--text-sm)"';
        $muted = 'class="fi-section-header-description" style="font-size: var(--text-sm)"';
        $row = 'style="display: flex; flex-wrap: wrap; align-items: center; gap: calc(var(--spacing) * 3)"';
        $stack = 'style="display: grid; gap: calc(var(--spacing) * 1)"';
        $grid = fn (array $cols, int $gap = 4, array $attrs = []) => (new \Filament\Support\View\ComponentAttributeBag)->grid($cols)->style(["gap: calc(var(--spacing) * {$gap})"])->merge($attrs, escape: false);
    @endphp

    @include('filament.resources.projects.partials.project-workspace-header', ['project' => $project, 'modules' => $this->getWorkspaceModules('overview')])

    {{-- Operations this month --}}
    <x-filament::section>
        <x-slot name="heading">Operations this month</x-slot>
        <x-slot name="description">Monthly Target Completion, tasks and report state for the current reporting period. Open a module for the detail.</x-slot>
        <x-slot name="afterHeader">
            <div {!! $row !!} data-operations-period="{{ $period->label() }}">
                <span class="fi-in-text-item" style="font-size: var(--text-sm); font-weight: var(--font-weight-medium)">{{ $period->label() }}</span>
                @if ($cycle)
                    <x-filament::badge :color="$cycle->status->getColor()" data-operations-cycle-status="{{ $cycle->status->value }}">{{ $cycle->status->getLabel() }}</x-filament::badge>
                @else
                    <x-filament::badge color="gray" data-operations-cycle-status="none">No cycle</x-filament::badge>
                @endif
            </div>
        </x-slot>

        @if ($cycle === null)
            <div data-project-operations data-period="{{ $period->label() }}">
                <x-filament::callout color="warning" icon="heroicon-o-calendar-days" heading="No monthly cycle for {{ $period->label() }}." data-operations-missing-cycle>
                    Deliverables, tasks and the report for this month start once the cycle exists. It is created automatically for active projects on the 1st, or manually under Monthly cycles.
                    @if ($this->canEnsureCycle())
                        <x-slot name="footer">
                            <x-filament::button tag="a" :href="$this->moduleUrl('monthly-cycles')" color="warning" size="sm" data-operations-ensure-cycle>Create it under Monthly cycles</x-filament::button>
                        </x-slot>
                    @endif
                </x-filament::callout>
            </div>
        @else
            @php
                $cards = [
                    ['key' => 'completion', 'label' => 'Target completion', 'value' => $completion?->label() ?? '—', 'helper' => $completion?->hasParticipatingTargets() ? ($completion->metCount().' of '.$completion->participating()->count().' targets met') : 'No targets to measure', 'color' => 'gray', 'url' => null, 'attrs' => 'data-operations-completion="'.e($completion?->overallPercentage() ?? '').'"'],
                    ['key' => 'open_tasks', 'label' => 'Open tasks', 'value' => $ops->openTasks, 'helper' => 'This month', 'color' => 'gray', 'url' => $this->moduleUrl('tasks', ['cycle' => $cycle->getKey()]), 'attrs' => 'data-operations-open-tasks="'.$ops->openTasks.'"'],
                    ['key' => 'overdue_tasks', 'label' => 'Overdue', 'value' => $ops->overdueTasks, 'helper' => $ops->overdueTasks > 0 ? 'Past their due date' : 'Nothing overdue', 'color' => $ops->overdueTasks > 0 ? 'danger' : 'gray', 'url' => $this->moduleUrl('tasks', ['cycle' => $cycle->getKey()]), 'attrs' => 'data-operations-overdue-tasks="'.$ops->overdueTasks.'"'],
                    ['key' => 'report', 'label' => 'Report', 'value' => $ops->report ? $ops->report->status->getLabel() : 'Not started', 'helper' => ($ops->report ? $ops->report->versionLabel().($ops->report->isCorrection() ? ' (correction) · ' : ' · ') : '').'Readiness '.$ops->readinessLabel(), 'color' => $ops->report?->status->getColor() ?? 'gray', 'url' => $ops->report ? $this->moduleUrl('report', ['report' => $ops->report]) : $this->moduleUrl('reports'), 'attrs' => 'data-operations-report-status="'.e($ops->report?->status->value ?? 'none').'" data-operations-readiness="'.e($ops->readiness?->percentage() ?? '').'"'],
                ];
            @endphp

            <div {{ $grid(['default' => 2, 'lg' => 4], 4, ['data-project-operations' => '', 'data-period' => $period->label()]) }}>
                @foreach ($cards as $card)
                    @php $flagged = $card['color'] !== 'gray'; @endphp
                    <{{ $card['url'] ? 'a' : 'div' }} class="fi-wi-stats-overview-stat" style="padding: calc(var(--spacing) * 4)"{!! $card['url'] ? ' href="'.e($card['url']).'"' : '' !!} data-operations-card="{{ $card['key'] }}">
                        <div class="fi-wi-stats-overview-stat-content">
                            <div class="fi-wi-stats-overview-stat-label-ctn"><span class="fi-wi-stats-overview-stat-label">{{ $card['label'] }}</span></div>
                            <div class="fi-wi-stats-overview-stat-value{{ $flagged ? ' fi-color-'.$card['color'].' fi-text-color-600 dark:fi-text-color-400' : '' }}" style="font-size: var(--text-2xl); line-height: var(--text-2xl--line-height);{{ $flagged ? ' color: var(--text);' : '' }}" {!! $card['attrs'] !!}>{{ $card['value'] }}</div>
                            <div class="fi-wi-stats-overview-stat-description"><span>{{ $card['helper'] }}</span></div>
                        </div>
                    </{{ $card['url'] ? 'a' : 'div' }}>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    {{-- Deliverable progress | Project details --}}
    <div {{ $grid(['default' => 1, 'xl' => 2], 6) }}>
        <x-filament::section>
            <x-slot name="heading">Deliverable progress</x-slot>
            <x-slot name="description">Actual against this month's snapshotted targets. Figures may exceed 100%; the overall completion counts each target at most once.</x-slot>

            @if ($cycle === null)
                <p {!! $muted !!}>Available once the monthly cycle exists.</p>
            @else
                <div style="display: grid; gap: calc(var(--spacing) * 4)" data-operations-targets>
                    @foreach ($deliverables as $item)
                        @php
                            /** @var \App\Support\Targets\TargetProgress $progress */
                            $progress = $item['progress'];
                            $completionRow = $item['completion'];
                            $percentage = $progress->percentage();
                            $met = $progress->isComplete();
                            $links = ['backlinks' => $this->moduleUrl('backlinks', ['cycle' => $cycle->getKey()]), 'guest_posts' => $this->moduleUrl('backlinks', ['cycle' => $cycle->getKey()]), 'blogs' => $this->moduleUrl('content', ['view' => $cycle->getKey()]), 'pages_optimized' => $this->moduleUrl('pages')];
                        @endphp
                        <div data-deliverable="{{ $progress->targetKey }}" data-operations-target="{{ $progress->targetKey }}" data-percentage="{{ $completionRow?->percentage() ?? '' }}" data-contribution="{{ $completionRow?->contribution() ?? '' }}">
                            <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: baseline; gap: calc(var(--spacing) * 2)">
                                <a href="{{ $links[$progress->targetKey] ?? $this->moduleUrl('view') }}" class="fi-in-text-item" style="font-size: var(--text-sm); font-weight: var(--font-weight-medium)">{{ $progress->label }}</a>
                                <span class="fi-in-text-item" style="font-size: var(--text-sm); font-variant-numeric: tabular-nums">
                                    <span data-operations-{{ $progress->targetKey === 'pages_optimized' ? 'pages' : $progress->targetKey }}>{{ $progress->format() }}</span>
                                    @if ($percentage !== null)
                                        <span style="margin-left: calc(var(--spacing) * 2); font-weight: var(--font-weight-semibold); color: {{ $met ? 'var(--success-600)' : 'var(--primary-600)' }}" data-deliverable-percentage="{{ $percentage }}">{{ $percentage }}%</span>
                                    @endif
                                </span>
                            </div>
                            @if ($percentage !== null)
                                <div role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ min(100, $percentage) }}" aria-label="{{ $progress->label }} {{ $percentage }}%" style="margin-top: calc(var(--spacing) * 1.5); height: 6px; border-radius: 999px; background: color-mix(in oklab, var(--gray-500) 22%, transparent); overflow: hidden">
                                    <div style="height: 100%; width: {{ min(100, $percentage) }}%; border-radius: 999px; background: {{ $met ? 'var(--success-500)' : 'var(--primary-500)' }}"></div>
                                </div>
                            @endif
                            <div class="fi-section-header-description" style="margin-top: calc(var(--spacing) * 1); font-size: var(--text-xs)">
                                @if (! $progress->hasTarget())
                                    No target set for this month
                                @elseif ($progress->remainingLabel())
                                    {{ $progress->remainingLabel() }}
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Project details</x-slot>

            <dl {{ $grid(['default' => 1, 'sm' => 2], 5, ['data-project-details' => '']) }}>
                <div {!! $stack !!}><dt {!! $label !!}>Project name</dt><dd {!! $value !!}>{{ $project->name }}</dd></div>
                <div {!! $stack !!}><dt {!! $label !!}>Status</dt><dd style="margin: 0"><x-filament::badge :color="$project->status->getColor()">{{ $project->status->getLabel() }}</x-filament::badge></dd></div>
                <div {!! $stack !!}><dt {!! $label !!}>Client</dt><dd {!! $value !!}>
                    @if ($this->getClientUrl())<x-filament::link :href="$this->getClientUrl()" size="sm">{{ $project->client?->name }}</x-filament::link>@else{{ $project->client?->name ?? '—' }}@endif
                </dd></div>
                <div {!! $stack !!}><dt {!! $label !!}>Website</dt><dd {!! $value !!}>
                    @if ($project->website_url)<x-filament::link :href="$project->website_url" target="_blank" rel="noopener noreferrer" size="sm">{{ $website }}</x-filament::link>@else — @endif
                </dd></div>
                <div {!! $stack !!}><dt {!! $label !!}>Target location</dt><dd {!! $value !!}>{{ $project->target_location ?? '—' }}</dd></div>
                <div {!! $stack !!}><dt {!! $label !!}>Start date</dt><dd {!! $value !!}>{{ $project->start_date?->format('j M Y') ?? '—' }}</dd></div>
                <div {!! $stack !!}><dt {!! $label !!}>End date</dt><dd {!! $value !!}>{{ $project->end_date?->format('j M Y') ?? '—' }}</dd></div>
                @if ($project->trashed())
                    <div {!! $stack !!}><dt {!! $label !!}>Archived</dt><dd {!! $value !!}>{{ $project->deleted_at?->format('j M Y H:i') }}</dd></div>
                @endif
            </dl>
        </x-filament::section>
    </div>

    {{-- Package & monthly targets | Team --}}
    <div {{ $grid(['default' => 1, 'xl' => 2], 6) }}>
        <x-filament::section>
            <x-slot name="heading">Package &amp; monthly targets</x-slot>
            <x-slot name="description">Target changes affect future monthly cycles.</x-slot>

            @if ($project->package === null)
                <p {!! $muted !!} data-project-package="none">No package assigned.</p>
            @else
                <div {!! $row !!} data-project-package="{{ $project->package->getKey() }}">
                    <span class="fi-in-text-item" style="font-size: var(--text-lg); font-weight: var(--font-weight-semibold)">{{ $project->package->name }}{{ $project->package->is_active ? '' : ' (inactive)' }}</span>
                    @unless ($project->package->is_active)
                        <x-filament::badge color="warning" size="sm">No longer offered</x-filament::badge>
                    @endunless
                </div>

                <dl style="margin: calc(var(--spacing) * 4) 0 0; display: grid; gap: calc(var(--spacing) * 2)" data-project-targets>
                    @forelse ($targets as $target)
                        <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: calc(var(--spacing) * 2); padding-bottom: calc(var(--spacing) * 2); border-bottom: 1px solid color-mix(in oklab, var(--gray-500) 18%, transparent)" data-project-target="{{ $target->targetKey }}" data-resolved="{{ $target->resolvedValue() }}">
                            <dt class="fi-in-text-item" style="font-size: var(--text-sm)">
                                {{ $target->label }}
                                @if ($target->isOverridden())
                                    <x-filament::badge color="info" size="sm" style="margin-left: calc(var(--spacing) * 2)">Project override</x-filament::badge>
                                @endif
                            </dt>
                            <dd style="margin: 0; text-align: right; font-variant-numeric: tabular-nums">
                                <span class="fi-in-text-item" style="font-size: var(--text-lg); font-weight: var(--font-weight-semibold)">{{ $target->resolvedValue() }}</span>
                                @if ($target->isOverridden())
                                    <span class="fi-section-header-description" style="display: block; font-size: var(--text-xs)">Package default {{ $target->packageValue }}</span>
                                @endif
                            </dd>
                        </div>
                    @empty
                        <p {!! $muted !!}>This package defines no monthly targets.</p>
                    @endforelse
                </dl>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Team</x-slot>

            <div style="display: grid; gap: calc(var(--spacing) * 4)" data-project-team>
                <div {!! $stack !!}>
                    <div {!! $label !!}>Primary SEO</div>
                    @if ($project->primarySeoUser)
                        <div {!! $row !!} data-team-primary="{{ $project->primarySeoUser->getKey() }}">
                            <span class="fi-in-text-item" style="font-size: var(--text-sm); font-weight: var(--font-weight-medium)">{{ $project->primarySeoUser->name }}</span>
                            <x-filament::badge color="primary" size="sm">Primary</x-filament::badge>
                            <span {!! $muted !!}>{{ $project->primarySeoUser->role()?->getLabel() ?? '—' }}</span>
                        </div>
                    @else
                        <p {!! $muted !!} data-team-primary="none">Unassigned</p>
                    @endif
                </div>

                <div {!! $stack !!}>
                    <div {!! $label !!}>Additional team</div>
                    @forelse ($team as $member)
                        <div {!! $row !!} data-team-member="{{ $member->getKey() }}">
                            <span class="fi-in-text-item" style="font-size: var(--text-sm); font-weight: var(--font-weight-medium)">{{ $member->name }}</span>
                            <span {!! $muted !!}>{{ $member->role()?->getLabel() ?? '—' }}</span>
                        </div>
                    @empty
                        <p {!! $muted !!} data-team-empty>No additional team members assigned.</p>
                    @endforelse
                </div>
            </div>
        </x-filament::section>
    </div>

    @if (filled($project->notes))
        <x-filament::section>
            <x-slot name="heading">Internal notes</x-slot>
            <p class="fi-in-text-item" style="margin: 0; font-size: var(--text-sm); white-space: pre-line" data-project-notes>{{ $project->notes }}</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
