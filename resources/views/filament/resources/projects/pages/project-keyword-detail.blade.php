<x-filament-panels::page>
    @php
        $keyword = $this->getKeyword();
        $cycles = $this->getCycles();
        $cycle = $this->getSelectedCycle();
        $summary = $this->getMonthlySummary();
    @endphp

    <x-filament::section>
        <x-slot name="heading">Keyword</x-slot>
        <x-slot name="description">Project master data. Editable regardless of locked months; only monthly ranking history locks.</x-slot>

        <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-3 lg:grid-cols-4">
            <div class="col-span-2">
                <dt class="font-medium text-gray-500 dark:text-gray-400">Keyword</dt>
                <dd class="text-base font-semibold" data-keyword>{{ $keyword->keyword }}</dd>
            </div>
            <div class="col-span-2">
                <dt class="font-medium text-gray-500 dark:text-gray-400">Target page</dt>
                <dd class="break-all">
                    @if ($keyword->targetPage)
                        {{ $keyword->targetPage->displayName() }}
                        <span class="text-xs text-gray-500">{{ $keyword->targetPage->url }}</span>
                    @else
                        —
                    @endif
                </dd>
            </div>
            <div>
                <dt class="font-medium text-gray-500 dark:text-gray-400">Search volume</dt>
                <dd>{{ $keyword->search_volume !== null ? number_format($keyword->search_volume) : '—' }}</dd>
            </div>
            <div>
                <dt class="font-medium text-gray-500 dark:text-gray-400">Keyword difficulty</dt>
                <dd>{{ $keyword->keyword_difficulty ?? '—' }}</dd>
            </div>
            <div>
                <dt class="font-medium text-gray-500 dark:text-gray-400">Intent</dt>
                <dd>{{ $keyword->search_intent?->getLabel() ?? '—' }}</dd>
            </div>
            <div>
                <dt class="font-medium text-gray-500 dark:text-gray-400">Role</dt>
                <dd>{{ $keyword->keyword_role?->getLabel() ?? '—' }}</dd>
            </div>
            <div>
                <dt class="font-medium text-gray-500 dark:text-gray-400">Location</dt>
                <dd>{{ $keyword->displayLocation() }}</dd>
            </div>
            <div>
                <dt class="font-medium text-gray-500 dark:text-gray-400">Branded</dt>
                <dd>{{ $keyword->is_branded ? 'Branded' : 'Non-branded' }}</dd>
            </div>
            <div>
                <dt class="font-medium text-gray-500 dark:text-gray-400">Status</dt>
                <dd>
                    <x-filament::badge :color="$keyword->status->getColor()">
                        {{ $keyword->status->getLabel() }}
                    </x-filament::badge>
                </dd>
            </div>
        </dl>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Monthly ranking summary</x-slot>
        <x-slot name="description">Earliest and latest observations in the selected reporting month. Lower positions are better; movement is derived, never stored.</x-slot>

        @if ($cycles->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">No monthly cycles yet.</p>
        @else
            <div class="flex flex-wrap items-end gap-6">
                <div class="w-56">
                    <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Reporting month</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="selectedCycleId">
                            @foreach ($cycles as $option)
                                <option value="{{ $option->getKey() }}">{{ $option->periodLabel() }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>

                @if ($cycle && $summary)
                    <div>
                        <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Month start</div>
                        <div class="text-2xl font-semibold" data-month-start>{{ $summary->monthStartLabel() }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Latest</div>
                        <div class="text-2xl font-semibold" data-month-latest>{{ $summary->latestLabel() }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Movement</div>
                        @php $movement = $summary->movement(); @endphp
                        <div class="text-lg font-semibold {{ $movement?->isImprovement() ? 'text-success-600' : ($movement?->isDecline() ? 'text-danger-600' : '') }}" data-month-movement>
                            {{ $summary->movementLabel() }}
                        </div>
                        @if ($movement)
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $movement->transition() }}</div>
                        @elseif ($summary->snapshotCount === 1)
                            <div class="text-xs text-gray-500 dark:text-gray-400">Only one observation this month.</div>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Ranking history</x-slot>
        <x-slot name="description">Every observation stays. “Not Ranking” means the keyword was not found, never position zero.</x-slot>

        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
