<x-filament-panels::page>
    @php
        $project = $this->getProject();
        $sections = $this->getSections();
        $enabled = $sections->where('is_enabled', true);
        $muted = 'class="fi-section-header-description" style="font-size: var(--text-sm)"';
    @endphp

    @include('filament.resources.projects.partials.project-workspace-header', ['project' => $project, 'modules' => $this->getWorkspaceModules('report-sections')])

    {{-- Module title --}}
    <div style="display: grid; gap: calc(var(--spacing) * 1)" data-report-sections-header>
        <h2 class="fi-section-header-heading" style="margin: 0; font-size: var(--text-xl); line-height: var(--text-xl--line-height)">Report sections</h2>
        <p {!! $muted !!}>Choose which sections appear in future monthly reports.</p>
    </div>

    <x-filament::section>
        <x-slot name="heading">Report layout</x-slot>
        <x-slot name="description">{{ $enabled->count() }} of {{ $sections->count() }} sections included · {{ $enabled->where('is_required', true)->count() }} required for readiness.</x-slot>

        <p class="fi-in-text-item" style="margin: 0; padding: calc(var(--spacing) * 3) calc(var(--spacing) * 4); font-size: var(--text-sm); border: 1px solid var(--warning-500); border-left-width: 4px; border-radius: var(--radius-lg); background: color-mix(in oklab, var(--warning-500) 8%, transparent)" data-future-reports-warning>Changes affect future reports only. Existing reports keep their saved section layout.</p>

        <ol style="display: grid; gap: calc(var(--spacing) * 2); margin: calc(var(--spacing) * 4) 0 0; padding: 0; list-style: none" data-report-sections-list>
            @foreach ($sections as $section)
                @php
                    $renamed = $section->title !== $section->section_key->defaultTitle();
                @endphp
                <li style="display: flex; flex-wrap: wrap; align-items: center; gap: calc(var(--spacing) * 3); padding: calc(var(--spacing) * 3) calc(var(--spacing) * 4); border: 1px solid color-mix(in oklab, var(--gray-500) 22%, transparent); border-radius: var(--radius-lg);{{ $section->is_enabled ? '' : ' opacity: 0.6;' }}" data-report-section="{{ $section->section_key->value }}" data-enabled="{{ $section->is_enabled ? 1 : 0 }}" data-required="{{ $section->is_required ? 1 : 0 }}" data-sort-order="{{ $section->sort_order }}">
                    <span class="fi-section-header-description" style="display: inline-flex; width: 2rem; height: 2rem; align-items: center; justify-content: center; border-radius: 999px; background: color-mix(in oklab, var(--gray-500) 15%, transparent); font-size: var(--text-xs); font-weight: var(--font-weight-semibold); font-variant-numeric: tabular-nums" data-section-position>{{ $loop->iteration }}</span>
                    <div style="display: grid; gap: calc(var(--spacing) * 0.5); flex: 1 1 14rem; min-width: 0">
                        <span class="fi-in-text-item" style="font-size: var(--text-sm); font-weight: var(--font-weight-semibold); overflow-wrap: anywhere">{{ $section->title }}</span>
                        @if ($renamed)
                            <span class="fi-section-header-description" style="font-size: var(--text-xs)">{{ $section->section_key->defaultTitle() }}</span>
                        @endif
                    </div>
                    <div style="display: flex; flex-wrap: wrap; align-items: center; gap: calc(var(--spacing) * 2)">
                        <x-filament::badge :color="$section->is_enabled ? 'success' : 'gray'">{{ $section->is_enabled ? 'Included' : 'Not included' }}</x-filament::badge>
                        @if ($section->is_enabled)
                            <x-filament::badge :color="$section->is_required ? 'warning' : 'gray'">{{ $section->is_required ? 'Required' : 'Optional' }}</x-filament::badge>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>

        <p {!! $muted !!} style="margin-top: calc(var(--spacing) * 4); font-size: var(--text-sm)">Required sections must have their data before a report can be marked ready for review. Optional sections appear in the report when data exists.</p>
    </x-filament::section>
</x-filament-panels::page>
