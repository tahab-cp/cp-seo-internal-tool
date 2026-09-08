<x-filament-panels::page>
    @php
        $cycles = $this->getCycles();
        $cycle = $this->getSelectedCycle();
        $groups = $this->getGroupedNotes();
        $progress = $this->getTargetProgress();
        $canManage = $this->canManageSelectedCycle();
        $lanes = [
            'wins' => ['heading' => 'Wins', 'type' => \App\Enums\MonthlyNoteType::Win, 'empty' => 'No wins recorded yet. Capture notable results during the month so reporting is easier later.'],
            'challenges' => ['heading' => 'Challenges / Observations', 'type' => \App\Enums\MonthlyNoteType::Challenge, 'empty' => 'No challenges or observations yet. Note blockers and things worth explaining to the client.'],
            'recommendations' => ['heading' => 'Recommendations / Next month focus', 'type' => \App\Enums\MonthlyNoteType::Recommendation, 'empty' => 'No recommendations yet. At least one recommendation or next-month focus is needed for the report.'],
        ];
    @endphp

    <x-filament::section>
        <x-slot name="heading">Reporting month</x-slot>
        <x-slot name="description">Notes belong to the month they describe and feed that month's report.</x-slot>

        <div class="flex flex-wrap items-center gap-6">
            <div class="w-56">
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="selectedCycle">
                        @foreach ($cycles as $option)
                            <option value="{{ $option->getKey() }}">{{ $option->periodLabel() }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>

            @if ($cycle)
                <div class="text-sm text-gray-600 dark:text-gray-300" data-selected-cycle="{{ $cycle->getKey() }}">
                    {{ $cycle->periodLabel() }}
                    @if ($cycle->isLocked())
                        <x-filament::badge color="gray" size="sm">Locked</x-filament::badge>
                        <span class="text-xs text-gray-500">Read-only until a Super Admin unlocks the month.</span>
                    @else
                        <x-filament::badge color="success" size="sm">{{ $cycle->status->getLabel() }}</x-filament::badge>
                    @endif
                </div>

                @if ($progress !== [])
                    <dl class="flex flex-wrap gap-6 text-sm">
                        @foreach ($progress as $item)
                            <div>
                                <dt class="text-xs text-gray-500">{{ $item->label }}</dt>
                                <dd class="font-semibold" data-target-progress="{{ $item->targetKey }}">{{ $item->format() }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">This project has no monthly cycles yet.</p>
            @endif
        </div>
    </x-filament::section>

    @if ($cycle)
        <div class="grid gap-6 lg:grid-cols-3">
            @foreach ($lanes as $lane => $config)
                <x-filament::section>
                    <x-slot name="heading">{{ $config['heading'] }}</x-slot>
                    @if ($canManage)
                        <x-slot name="afterHeader">
                            {{ ($this->addNoteAction)(['type' => $config['type']->value]) }}
                        </x-slot>
                    @endif

                    @if ($groups[$lane]->isEmpty())
                        <p class="text-sm text-gray-500 dark:text-gray-400" data-empty-lane="{{ $lane }}">{{ $config['empty'] }}</p>
                    @else
                        <ul class="space-y-3">
                            @foreach ($groups[$lane] as $note)
                                <li class="rounded-lg border border-gray-100 p-3 dark:border-gray-800" data-note="{{ $note->getKey() }}">
                                    <div class="mb-1 flex items-center justify-between gap-2">
                                        <x-filament::badge :color="$note->type->getColor()" size="sm">{{ $note->type->getLabel() }}</x-filament::badge>
                                        @if ($canManage)
                                            <div class="flex items-center gap-2">
                                                {{ ($this->editNoteAction)(['note' => $note->getKey()]) }}
                                                {{ ($this->deleteNoteAction)(['note' => $note->getKey()]) }}
                                            </div>
                                        @endif
                                    </div>
                                    @if ($note->title)
                                        <div class="text-sm font-semibold">{{ $note->title }}</div>
                                    @endif
                                    <p class="whitespace-pre-line text-sm text-gray-700 dark:text-gray-200">{{ $note->body }}</p>
                                    <p class="mt-1 text-xs text-gray-500">{{ $note->createdBy?->name ?? 'Unknown' }} · {{ $note->created_at?->format('j M') }}</p>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
