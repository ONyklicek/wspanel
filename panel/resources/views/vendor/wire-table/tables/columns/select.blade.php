{{-- SelectColumn editable cell --}}
{{-- Variables: $column, $record, $state, $recordVersion --}}
@php
    $recordKey = $record->getKey();
    $columnName = $column->getName();
    $placeholder = $column->getPlaceholder() ?? __('wire-table::messages.select_placeholder');
    $options = $column->getOptions();
    $disabled = $column->isDisabled($record);

    $valueJson = json_encode((string) ($state ?? ''));
    $wireKey = "sel-{$recordKey}-{$columnName}";
@endphp

<div
    wire:key="{{ $wireKey }}"
    wire:ignore.self
    x-data="wireEditableCell({
        island: 'data-region',
        value: {{ $valueJson }},
        recordVersion: '{{ $recordVersion }}',
    })"
    data-record-key="{{ $recordKey }}"
    data-column-name="{{ $columnName }}"
    data-testid="table-editable-{{ $columnName }}"
    data-msg-error="{{ __('wire-table::messages.error') }}"
    data-msg-save-failed="{{ __('wire-table::messages.save_failed') }}"
>
    {!! $syncHtml !!}

    <select
        x-model="value"
        @change="change($event.target.value, $event)"
        :aria-busy="saving"
        :title="error"
        :class="{ 'border-red-500 focus:border-red-500 focus:ring-red-500': error }"
        @if($disabled) disabled @endif
        {{-- Match the searchable combobox trigger so all selects share one design. --}}
        class="block w-full rounded-md border border-gray-300 bg-white shadow-sm text-base sm:text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 hover:border-gray-400 dark:hover:border-gray-500 transition-colors duration-150 disabled:opacity-50 disabled:cursor-not-allowed dark:bg-gray-800 dark:border-gray-600 dark:text-white"
    >
        @if($placeholder)
            {{-- Server-rendered selected is a fallback before Alpine (x-model) drives the value. --}}
            <option value="" @if($state === null) selected @endif disabled>{{ $placeholder }}</option>
        @endif

        @foreach($options as $value => $label)
            <option value="{{ $value }}" @if((string) $state === (string) $value) selected @endif>{{ $label }}</option>
        @endforeach
    </select>

    {{-- Inline error (e.g. optimistic-lock conflict) — self-contained, no toast needed. --}}
    <p x-show="error" x-cloak x-text="error" class="mt-1 text-xs text-red-600 dark:text-red-400"></p>
</div>
