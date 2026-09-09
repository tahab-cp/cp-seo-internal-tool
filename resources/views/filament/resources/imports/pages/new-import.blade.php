<x-filament-panels::page>
    @php
        $type = $this->getSelectedType();
        $batch = $this->getBatch();
        $steps = [1 => 'Context', 2 => 'Upload', 3 => 'Map columns', 4 => 'Validate', 5 => 'Result'];
        $label = 'style="display: block; font-size: var(--text-sm); font-weight: var(--font-weight-medium); color: var(--gray-700); margin-bottom: calc(var(--spacing) * 1.5)"';
        $hint = 'style="font-size: var(--text-xs); color: var(--gray-500); margin-top: calc(var(--spacing) * 1)"';
        $actions = 'style="margin-top: calc(var(--spacing) * 5); display: flex; flex-wrap: wrap; align-items: center; gap: calc(var(--spacing) * 3)"';
    @endphp

    {{-- Step indicator --}}
    <div style="display: flex; flex-wrap: wrap; gap: calc(var(--spacing) * 2)" data-import-step="{{ $this->step }}" data-import-type="{{ $this->importType }}" data-import-project="{{ $this->projectId }}">
        @foreach ($steps as $number => $name)
            <x-filament::badge :color="$number === $this->step ? 'primary' : ($number < $this->step ? 'success' : 'gray')" size="lg">{{ $number }}. {{ $name }}</x-filament::badge>
        @endforeach
    </div>

    {{-- Step 1: type / project / month --}}
    @if ($this->step === 1)
        <x-filament::section>
            <x-slot name="heading">What are you importing, and where?</x-slot>
            <x-slot name="description">Only projects you can access are listed. Locked (finalized) months are not offered and are refused server-side.</x-slot>

            <div
                {{
                    (new \Filament\Support\View\ComponentAttributeBag)
                        ->grid(['default' => 1, 'lg' => 3])
                        ->style(['gap: calc(var(--spacing) * 4)'])
                }}
            >
                <div>
                    <label for="importType" {!! $label !!}>Import type</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="importType" wire:model.live="importType">
                            <option value="">Choose…</option>
                            @foreach ($this->getTypeOptions() as $value => $name)
                                <option value="{{ $value }}">{{ $name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                <div>
                    <label for="projectId" {!! $label !!}>Project</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="projectId" wire:model.live="projectId">
                            <option value="">Choose…</option>
                            @foreach ($this->getProjectOptions() as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                <div>
                    <label for="monthlyCycleId" {!! $label !!}>Reporting month</label>
                    <x-filament::input.wrapper :disabled="! $this->requiresCycle()">
                        <x-filament::input.select id="monthlyCycleId" wire:model.live="monthlyCycleId" :disabled="! $this->requiresCycle()">
                            <option value="">{{ $this->requiresCycle() ? 'Choose…' : 'Not needed for this type' }}</option>
                            @foreach ($this->getCycleOptions() as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    @if ($this->requiresCycle() && filled($this->projectId) && $this->getCycleOptions() === [])
                        <p {!! $hint !!} data-import-no-cycles>This project has no unlocked reporting month. Create the month first, or ask a Super Admin to unlock it.</p>
                    @endif
                </div>
            </div>

            <div {!! $actions !!}>
                <x-filament::button wire:click="chooseContext">Continue</x-filament::button>
            </div>
        </x-filament::section>
    @endif

    {{-- Step 2: upload --}}
    @if ($this->step === 2)
        <x-filament::section>
            <x-slot name="heading">Upload the CSV</x-slot>
            <x-slot name="description">
                {{ $type?->getLabel() }} · CSV only (UTF-8, comma separated, first line = column names) · up to {{ round(config('imports.max_file_bytes') / 1048576, 1) }} MB and {{ number_format(config('imports.max_rows')) }} rows. Nothing is imported until the final step.
            </x-slot>

            <div>
                <label for="file" {!! $label !!}>CSV file</label>
                <input id="file" type="file" accept=".csv,text/csv,text/plain" wire:model="file" class="fi-input" style="padding: calc(var(--spacing) * 2)" />
                <div wire:loading wire:target="file" {!! $hint !!}>Uploading…</div>
            </div>

            @if ($type)
                <p {!! $hint !!}>Expected columns: {{ collect($this->getFields())->map(fn ($f) => $f->label.($f->required ? ' *' : ''))->implode(', ') }}. Column names are mapped in the next step.</p>
            @endif

            <div {!! $actions !!}>
                <x-filament::button wire:click="upload" wire:loading.attr="disabled">Upload and continue</x-filament::button>
                <x-filament::button color="gray" wire:click="startOver">Back</x-filament::button>
            </div>
        </x-filament::section>
    @endif

    {{-- Step 3: mapping --}}
    @if ($this->step === 3 && $batch)
        <x-filament::section>
            <x-slot name="heading">Map CSV columns to fields</x-slot>
            <x-slot name="description">{{ $batch->original_filename }} · {{ number_format($batch->total_rows) }} data row(s). Matching names were suggested automatically; required fields are marked *.</x-slot>

            @php $headers = $this->getHeaders(); @endphp

            <div
                {{
                    (new \Filament\Support\View\ComponentAttributeBag)
                        ->grid(['default' => 1, 'md' => 2, 'xl' => 3])
                        ->style(['gap: calc(var(--spacing) * 4)'])
                        ->merge(['data-import-mapping' => ''], escape: false)
                }}
            >
                @foreach ($this->getFields() as $field)
                    <div>
                        <label for="mapping-{{ $field->key }}" {!! $label !!}>{{ $field->label }}@if ($field->required) *@endif</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="mapping-{{ $field->key }}" wire:model="mapping.{{ $field->key }}">
                                <option value="">— not imported —</option>
                                @foreach ($headers as $header)
                                    <option value="{{ $header }}">{{ $header }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                        @if ($field->hint)
                            <p {!! $hint !!}>{{ $field->hint }}</p>
                        @endif
                    </div>
                @endforeach
            </div>

            <div {!! $actions !!}>
                <x-filament::button wire:click="saveMapping">Validate rows</x-filament::button>
                <x-filament::button color="gray" wire:click="startOver">Start over</x-filament::button>
            </div>
        </x-filament::section>
    @endif

    {{-- Step 4: validation preview --}}
    @if ($this->step === 4 && $batch)
        @php $report = $this->getReport(); @endphp

        @if ($report)
            @php
                $cards = [
                    ['label' => 'Total rows', 'value' => $report->totalRows(), 'key' => 'total', 'color' => 'gray'],
                    ['label' => 'Valid rows', 'value' => $report->validCount(), 'key' => 'valid', 'color' => 'gray'],
                    ['label' => 'Invalid rows', 'value' => $report->invalidCount(), 'key' => 'invalid', 'color' => $report->invalidCount() > 0 ? 'danger' : 'gray'],
                    ['label' => 'Warnings', 'value' => $report->warningCount(), 'key' => 'warnings', 'color' => $report->warningCount() > 0 ? 'warning' : 'gray'],
                ];
            @endphp

            <div
                {{
                    (new \Filament\Support\View\ComponentAttributeBag)
                        ->grid(['default' => 1, 'sm' => 2, 'xl' => 4])
                        ->style(['gap: calc(var(--spacing) * 4)'])
                        ->merge(['data-import-preview' => ''], escape: false)
                }}
            >
                @foreach ($cards as $card)
                    @php $flagged = $card['color'] !== 'gray'; @endphp
                    <div class="fi-wi-stats-overview-stat">
                        <div class="fi-wi-stats-overview-stat-content">
                            <div class="fi-wi-stats-overview-stat-label-ctn"><span class="fi-wi-stats-overview-stat-label">{{ $card['label'] }}</span></div>
                            <div class="fi-wi-stats-overview-stat-value{{ $flagged ? ' fi-color-'.$card['color'].' fi-text-color-600 dark:fi-text-color-400' : '' }}"{!! $flagged ? ' style="color: var(--text)"' : '' !!} data-preview-card="{{ $card['key'] }}" data-value="{{ $card['value'] }}">{{ $card['value'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($report->issues()->isNotEmpty())
                <x-filament::section>
                    <x-slot name="heading">Problems</x-slot>
                    <x-slot name="description">Fix the CSV and upload it again; the import only runs when every row is valid. Warnings do not block.</x-slot>

                    <ul style="margin: 0; padding: 0; list-style: none; display: grid; gap: calc(var(--spacing) * 1.5); font-size: var(--text-sm)" data-preview-issues>
                        @foreach ($report->issues()->take(200) as $issue)
                            <li data-preview-issue="{{ $issue->rowNumber }}" data-severity="{{ $issue->severity }}">
                                <x-filament::badge :color="$issue->isError() ? 'danger' : 'warning'" size="sm">Row {{ $issue->rowNumber }}</x-filament::badge>
                                @if ($issue->field)<span style="color: var(--gray-500)">{{ $issue->field }}:</span>@endif
                                {{ $issue->message }}
                            </li>
                        @endforeach
                        @if ($report->issues()->count() > 200)
                            <li style="color: var(--gray-500)">… and {{ $report->issues()->count() - 200 }} more (all are listed on the import details page).</li>
                        @endif
                    </ul>
                </x-filament::section>
            @endif

            <x-filament::section>
                <x-slot name="heading">Sample preview</x-slot>
                <x-slot name="description">The first {{ config('imports.preview_rows') }} rows as they were read from the file, with the columns you mapped.</x-slot>

                <div style="overflow-x: auto">
                    <table class="fi-ta-table" style="width: 100%; font-size: var(--text-sm)" data-preview-sample>
                        <thead>
                            <tr>
                                <th style="text-align: left; padding: calc(var(--spacing) * 2)">Row</th>
                                <th style="text-align: left; padding: calc(var(--spacing) * 2)">Result</th>
                                @foreach ($this->getFields() as $field)
                                    @if (isset($batch->mapping()[$field->key]))
                                        <th style="text-align: left; padding: calc(var(--spacing) * 2)">{{ $field->label }}</th>
                                    @endif
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report->sample((int) config('imports.preview_rows')) as $row)
                                <tr data-preview-row="{{ $row->rowNumber }}" style="border-top: 1px solid var(--gray-200)">
                                    <td style="padding: calc(var(--spacing) * 2)">{{ $row->rowNumber }}</td>
                                    <td style="padding: calc(var(--spacing) * 2)">
                                        <x-filament::badge :color="$row->isValid() ? ($row->hasWarnings() ? 'warning' : 'success') : 'danger'" size="sm">{{ $row->isValid() ? ($row->hasWarnings() ? 'Valid, warning' : 'Valid') : 'Invalid' }}</x-filament::badge>
                                    </td>
                                    @foreach ($this->getFields() as $field)
                                        @if (isset($batch->mapping()[$field->key]))
                                            {{-- Raw CSV text, escaped by Blade; never evaluated. --}}
                                            <td style="padding: calc(var(--spacing) * 2)">{{ $row->values[$field->key] ?? '' }}</td>
                                        @endif
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Import</x-slot>
                <x-slot name="description">
                    @if ($type?->replacesMonthlyDataset())
                        This replaces the month's {{ strtolower($type->getLabel()) }} dataset with the rows in this file, exactly like manual bulk entry.
                    @else
                        Every row is written in one transaction; if anything fails, nothing is imported.
                    @endif
                </x-slot>

                <div {!! $actions !!}>
                    @if ($report->isImportable())
                        <x-filament::button color="success" wire:click="import" wire:loading.attr="disabled" data-import-run>Import {{ number_format($report->validCount()) }} row(s)</x-filament::button>
                    @else
                        <x-filament::button color="gray" disabled data-import-blocked>Import blocked: fix the invalid rows first</x-filament::button>
                    @endif
                    <x-filament::button color="gray" wire:click="backToMapping">Change mapping</x-filament::button>
                    <x-filament::button color="gray" wire:click="startOver">Start over</x-filament::button>
                </div>
            </x-filament::section>
        @else
            <x-filament::section>
                <x-slot name="heading">Validation</x-slot>
                <p style="font-size: var(--text-sm); color: var(--gray-500)">The validation result is no longer available. Validate the mapping again.</p>
                <div {!! $actions !!}>
                    <x-filament::button wire:click="backToMapping">Back to mapping</x-filament::button>
                </div>
            </x-filament::section>
        @endif
    @endif

    {{-- Step 5: result --}}
    @if ($this->step === 5 && $batch)
        <x-filament::section>
            <x-slot name="heading">{{ $batch->isCompleted() ? 'Import completed' : 'Import failed' }}</x-slot>
            <x-slot name="description">{{ $batch->original_filename }} · {{ $batch->import_type->getLabel() }} · {{ $batch->project->name }}{{ $batch->monthlyCycle ? ' · '.$batch->monthlyCycle->periodLabel() : '' }}</x-slot>

            <dl style="margin: 0; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: calc(var(--spacing) * 3); font-size: var(--text-sm)" data-import-result="{{ $batch->status->value }}">
                <div><dt style="color: var(--gray-500)">Status</dt><dd style="margin: 0"><x-filament::badge :color="$batch->status->getColor()">{{ $batch->status->getLabel() }}</x-filament::badge></dd></div>
                <div><dt style="color: var(--gray-500)">Total rows</dt><dd style="margin: 0; font-weight: var(--font-weight-semibold)" data-result-total="{{ $batch->total_rows }}">{{ $batch->total_rows }}</dd></div>
                <div><dt style="color: var(--gray-500)">Imported rows</dt><dd style="margin: 0; font-weight: var(--font-weight-semibold)" data-result-imported="{{ $batch->imported_rows }}">{{ $batch->imported_rows }}</dd></div>
                <div><dt style="color: var(--gray-500)">Failed rows</dt><dd style="margin: 0; font-weight: var(--font-weight-semibold)" data-result-failed="{{ $batch->failed_rows }}">{{ $batch->failed_rows }}</dd></div>
            </dl>

            @php $issues = $this->getStoredIssues(); @endphp
            @if ($issues->isNotEmpty())
                <ul style="margin: calc(var(--spacing) * 4) 0 0; padding: 0; list-style: none; display: grid; gap: calc(var(--spacing) * 1.5); font-size: var(--text-sm)" data-result-issues>
                    @foreach ($issues as $issue)
                        <li>
                            <x-filament::badge :color="$issue->isWarning() ? 'warning' : 'danger'" size="sm">{{ $issue->row_number > 0 ? 'Row '.$issue->row_number : 'File' }}</x-filament::badge>
                            @if ($issue->field)<span style="color: var(--gray-500)">{{ $issue->field }}:</span>@endif
                            {{ $issue->message }}
                        </li>
                    @endforeach
                </ul>
            @endif

            <div {!! $actions !!}>
                <x-filament::button tag="a" :href="\App\Filament\Resources\Imports\ImportBatchResource::getUrl('view', ['record' => $batch])" color="gray">View details</x-filament::button>
                <x-filament::button wire:click="startOver">New import</x-filament::button>
                <x-filament::link :href="\App\Filament\Resources\Imports\ImportBatchResource::getUrl('index')" size="sm">All imports</x-filament::link>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
