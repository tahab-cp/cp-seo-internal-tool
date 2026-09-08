<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Monthly reports</x-slot>
        <x-slot name="description">One report per reporting month. Readiness is derived live from the month's data and the report's snapshotted section configuration; it is separate from Monthly Target Completion.</x-slot>

        <p class="text-sm text-gray-500 dark:text-gray-400">
            Start a draft to snapshot the project's report sections for that month. The editor, review and PDF arrive with the report workflow.
        </p>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
