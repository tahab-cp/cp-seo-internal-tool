<x-filament-panels::page>
    @php
        $project = $this->getProject();
        $page = $this->getPage();
        $period = $this->getCurrentPeriod();
        $cycle = $this->getCurrentCycle();
        $monthEvents = $this->getCurrentMonthOptimisations();
        $count = $this->getOptimisationCount();
        $lastOptimised = $this->getLastOptimisedAt();
        $canRecord = ! $page->isRemoved() && \Illuminate\Support\Facades\Gate::allows('managePages', $project);
        $displayUrl = \Illuminate\Support\Str::limit($page->url, 80);

        $label = 'class="fi-section-header-description" style="font-size: var(--text-xs); font-weight: var(--font-weight-medium); text-transform: uppercase; letter-spacing: 0.04em"';
        $value = 'class="fi-in-text-item" style="margin: 0; font-size: var(--text-sm); overflow-wrap: anywhere"';
        $muted = 'class="fi-section-header-description" style="font-size: var(--text-sm)"';
        $row = 'style="display: flex; flex-wrap: wrap; align-items: center; gap: calc(var(--spacing) * 3)"';
        $stack = 'style="display: grid; gap: calc(var(--spacing) * 1)"';
        $grid = fn (array $cols, int $gap = 4, array $attrs = []) => (new \Filament\Support\View\ComponentAttributeBag)->grid($cols)->style(["gap: calc(var(--spacing) * {$gap})"])->merge($attrs, escape: false);
    @endphp

    @include('filament.resources.projects.partials.project-workspace-header', ['project' => $project, 'modules' => $this->getWorkspaceModules('pages')])

    {{-- Page header: title, type / status badges and the live URL --}}
    <div {!! $stack !!} data-page-header data-page-status="{{ $page->status->value }}">
        <div {!! $row !!}>
            <h2 class="fi-section-header-heading" style="margin: 0; font-size: var(--text-xl); line-height: var(--text-xl--line-height)" data-page-title>{{ $page->displayName() }}</h2>
            @if ($page->page_type)
                <x-filament::badge color="gray" data-page-type>{{ $page->page_type }}</x-filament::badge>
            @endif
            <x-filament::badge :color="$page->status->getColor()">{{ $page->status->getLabel() }}</x-filament::badge>
        </div>
        <x-filament::link :href="$page->url" target="_blank" rel="noopener noreferrer" icon="heroicon-m-arrow-top-right-on-square" icon-position="after" size="sm" :tooltip="$page->url" data-page-url>{{ $displayUrl }}</x-filament::link>
    </div>

    {{-- Page details | Optimisation this month --}}
    <div {{ $grid(['default' => 1, 'xl' => 2], 6) }}>
        <x-filament::section>
            <x-slot name="heading">Page details</x-slot>
            <x-slot name="description">Page details can still be updated at any time. Optimisation records from a locked reporting month are read-only.</x-slot>

            <dl {{ $grid(['default' => 1, 'sm' => 2], 5, ['data-page-details' => '']) }}>
                <div {!! $stack !!}><dt {!! $label !!}>Title</dt><dd {!! $value !!}>{{ $page->title ?? '—' }}</dd></div>
                <div {!! $stack !!}><dt {!! $label !!}>Page type</dt><dd {!! $value !!}>{{ $page->page_type ?? '—' }}</dd></div>
                <div {!! $stack !!}><dt {!! $label !!}>URL</dt><dd {!! $value !!}><x-filament::link :href="$page->url" target="_blank" rel="noopener noreferrer" size="sm" :tooltip="$page->url">{{ $displayUrl }}</x-filament::link></dd></div>
                <div {!! $stack !!}><dt {!! $label !!}>Status</dt><dd style="margin: 0"><x-filament::badge :color="$page->status->getColor()">{{ $page->status->getLabel() }}</x-filament::badge></dd></div>
                <div {!! $stack !!}><dt {!! $label !!}>Path</dt><dd {!! $value !!}>{{ $page->path ?? '—' }}</dd></div>
                <div {!! $stack !!}><dt {!! $label !!}>Last optimised</dt><dd {!! $value !!} data-page-last-optimised="{{ $lastOptimised?->toDateString() ?? 'never' }}">
                    @if ($lastOptimised)
                        {{ $lastOptimised->format('j M Y') }} <span class="fi-section-header-description" style="display: inline; font-size: var(--text-xs)">· {{ $lastOptimised->diffForHumans() }}</span>
                    @else
                        Never optimised
                    @endif
                </dd></div>
                <div {!! $stack !!}><dt {!! $label !!}>Optimisation records</dt><dd {!! $value !!} data-page-optimisation-count="{{ $count }}">{{ $count }}</dd></div>
                <div {!! $stack !!}><dt {!! $label !!}>Current month</dt><dd {!! $value !!}>{{ $period->label() }}</dd></div>
            </dl>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Optimisation this month</x-slot>
            <x-slot name="description">Monthly progress counts this page once per reporting month, however many events are recorded.</x-slot>
            <x-slot name="afterHeader">
                <div {!! $row !!} data-page-month="{{ $period->label() }}">
                    <span class="fi-in-text-item" style="font-size: var(--text-sm); font-weight: var(--font-weight-medium)">{{ $period->label() }}</span>
                    @if ($cycle)
                        <x-filament::badge :color="$cycle->status->getColor()" data-page-cycle-status="{{ $cycle->status->value }}">{{ $cycle->status->getLabel() }}</x-filament::badge>
                    @else
                        <x-filament::badge color="gray" data-page-cycle-status="none">No cycle</x-filament::badge>
                    @endif
                </div>
            </x-slot>

            @if ($cycle === null)
                <p {!! $muted !!} data-page-month-summary="no-cycle">No monthly cycle for {{ $period->label() }} yet, so nothing can be recorded for this month.</p>
            @elseif ($monthEvents->isEmpty())
                <div {!! $stack !!} data-page-month-summary="none">
                    <p {!! $muted !!}>Not recorded yet.</p>
                    @if ($canRecord && ! $cycle->isLocked())
                        <div><x-filament::button size="sm" icon="heroicon-o-wrench-screwdriver" wire:click="mountAction('recordOptimization')" data-page-record-this-month>Record optimisation</x-filament::button></div>
                    @endif
                </div>
            @else
                @php
                    $latest = $monthEvents->first();
                    $changes = $monthEvents->flatMap(fn ($event) => $event->changeLabels())->unique()->values();
                @endphp
                <div style="display: grid; gap: calc(var(--spacing) * 3)" data-page-month-summary="recorded" data-page-month-events="{{ $monthEvents->count() }}">
                    <div {!! $value !!} style="margin: 0; font-size: var(--text-sm); font-weight: var(--font-weight-medium)">
                        Recorded {{ $latest->optimized_at->format('j M Y') }}{{ $latest->user ? ' by '.$latest->user->name : '' }}
                    </div>
                    @if ($monthEvents->count() > 1)
                        <p {!! $muted !!}>{{ $monthEvents->count() }} optimisation events this month · counts once towards the monthly target.</p>
                    @endif
                    <div {!! $stack !!}>
                        <div {!! $label !!}>Changes</div>
                        <div style="display: flex; flex-wrap: wrap; gap: calc(var(--spacing) * 2)">
                            @forelse ($changes as $change)
                                <x-filament::badge color="gray" data-page-month-change>{{ $change }}</x-filament::badge>
                            @empty
                                <span {!! $muted !!}>—</span>
                            @endforelse
                        </div>
                    </div>
                    @if ($cycle->isLocked())
                        <p {!! $muted !!} data-page-month-locked>This reporting month is locked; these records are read-only.</p>
                    @endif
                </div>
            @endif
        </x-filament::section>
    </div>

    {{-- Optimisation history --}}
    <x-filament::section>
        <x-slot name="heading">Optimisation history</x-slot>
        <x-slot name="description">Every recorded event stays visible; records from a locked month are read-only.</x-slot>

        @if ($count === 0)
            <div style="display: grid; gap: calc(var(--spacing) * 2); padding: calc(var(--spacing) * 2) 0" data-page-history-empty>
                <div class="fi-in-text-item" style="font-size: var(--text-sm); font-weight: var(--font-weight-medium)">No optimisation work recorded yet</div>
                <p {!! $muted !!}>Record the SEO work completed on this page so it appears in monthly progress and reporting.</p>
                @if ($canRecord)
                    <div><x-filament::button size="sm" icon="heroicon-o-wrench-screwdriver" wire:click="mountAction('recordOptimization')" data-page-record-first>Record optimisation</x-filament::button></div>
                @endif
            </div>
        @else
            {{ $this->table }}
        @endif
    </x-filament::section>

    {{--
        Filament's page layout leaves the action-modal container to the table view on
        HasTable pages. The table is only rendered once the page has history, so the
        container is included here explicitly (Filament renders it at most once per
        component) to keep "Record optimisation" working on a page without records.
    --}}
    <x-filament-actions::modals />
</x-filament-panels::page>
