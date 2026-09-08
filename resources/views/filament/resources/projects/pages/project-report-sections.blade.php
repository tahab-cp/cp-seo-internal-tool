<x-filament-panels::page>
    @php
        $sections = $this->getSections();
    @endphp

    <x-filament::section>
        <x-slot name="heading">Report sections</x-slot>
        <x-slot name="description">The template used when a new monthly report is started.</x-slot>

        <div class="mb-4 rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-200" data-future-reports-warning>
            Changes affect future reports only. Existing monthly reports keep their snapshotted configuration.
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-gray-500">
                    <tr>
                        <th class="py-2">Title</th>
                        <th class="py-2">Section</th>
                        <th class="py-2 text-center">Enabled</th>
                        <th class="py-2 text-center">Required</th>
                        <th class="py-2 text-right">Sort order</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sections as $section)
                        <tr class="border-t border-gray-100 dark:border-gray-800" data-report-section="{{ $section->section_key->value }}" data-enabled="{{ $section->is_enabled ? 1 : 0 }}" data-required="{{ $section->is_required ? 1 : 0 }}" data-sort-order="{{ $section->sort_order }}">
                            <td class="py-2 font-medium">{{ $section->title }}</td>
                            <td class="py-2 font-mono text-xs text-gray-500">{{ $section->section_key->value }}</td>
                            <td class="py-2 text-center">
                                <x-filament::badge :color="$section->is_enabled ? 'success' : 'gray'" size="sm">{{ $section->is_enabled ? 'Enabled' : 'Disabled' }}</x-filament::badge>
                            </td>
                            <td class="py-2 text-center">
                                <x-filament::badge :color="$section->is_required ? 'warning' : 'gray'" size="sm">{{ $section->is_required ? 'Required' : 'Optional' }}</x-filament::badge>
                            </td>
                            <td class="py-2 text-right">{{ $section->sort_order }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
