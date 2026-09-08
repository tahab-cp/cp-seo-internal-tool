<x-filament-panels::page>
    @php
        $cycles = $this->getCycles();
        $cycle = $this->getSelectedCycle();
    @endphp

    <x-filament::section>
        <x-slot name="heading">Reporting month</x-slot>
        <x-slot name="description">Each month is a separate cycle with its own snapshotted targets.</x-slot>

        @if ($cycles->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No monthly cycles yet. Active projects receive a cycle automatically at the start of each month.
                @if ($this->currentCycleIsMissing() && \Illuminate\Support\Facades\Gate::allows('ensureMonthlyCycle', $this->getProject()))
                    Use “Ensure {{ $this->getCurrentPeriod()->label() }}” above to create the current month now.
                @endif
            </p>
        @else
            <div class="max-w-xs">
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="selectedCycleId">
                        @foreach ($cycles as $option)
                            <option value="{{ $option->getKey() }}">{{ $option->periodLabel() }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
        @endif
    </x-filament::section>

    @if ($cycle)
        <x-filament::section>
            <x-slot name="heading">{{ $cycle->periodLabel() }}</x-slot>

            <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
                <div>
                    <dt class="font-medium text-gray-500 dark:text-gray-400">Status</dt>
                    <dd>
                        <x-filament::badge :color="$cycle->status->getColor()">
                            {{ $cycle->status->getLabel() }}
                        </x-filament::badge>
                    </dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-500 dark:text-gray-400">Started</dt>
                    <dd>{{ $cycle->started_at?->format('j M Y') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-500 dark:text-gray-400">Locked</dt>
                    <dd>{{ $cycle->locked_at?->format('j M Y') ?? 'Not locked' }}</dd>
                </div>
            </dl>

            <h3 class="mt-6 text-base font-semibold">Monthly targets</h3>
            <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
                Snapshotted when this cycle was created. Later package or override changes do not alter these values.
            </p>

            @if ($cycle->targets->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    No targets were configured when this cycle was created (the project had no package).
                </p>
            @else
                <table class="w-full max-w-md text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="py-1 pr-4 font-medium">Target</th>
                            <th class="py-1 text-right font-medium">Monthly target</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($cycle->targets as $target)
                            <tr class="border-t border-gray-100 dark:border-white/10">
                                <td class="py-1 pr-4">{{ $target->label }}</td>
                                <td class="py-1 text-right font-semibold" data-target-key="{{ $target->target_key }}">{{ $target->target_value }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
