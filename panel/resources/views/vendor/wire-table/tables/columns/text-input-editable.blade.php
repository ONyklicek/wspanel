{{-- TextInputColumn editable cell --}}
{{-- Variables: $column, $record, $state, $recordVersion --}}
@php
    $recordKey = $record->getKey();
    $columnName = $column->getName();
    $value = (string) ($state ?? '');
    $valueJson = json_encode($value);

    $hasPrefix = $column->getInputPrefix() !== null;
    $hasSuffix = $column->getInputSuffix() !== null;

    $inputClasses = $column->buildInputClasses($hasPrefix, $hasSuffix);
    $attrs = $column->buildInputAttributes();

    $saveOnBlur = $column->getSaveOnBlur() ? 'true' : 'false';
    $saveOnEnter = $column->getSaveOnEnter() ? 'true' : 'false';
    $liveValidation = $column->getLiveValidation() ? 'true' : 'false';
    $debounce = $column->getLiveDebounce();

    $wireKey = "tic-{$recordKey}-{$columnName}";
@endphp

{{-- Optimistic value + rollback + optimistic-lock conflict handling + poll sync
     all live in the shared wireEditableCell component; this cell adds live
     validation, save-on-blur/enter, escape-to-revert and prefix/suffix chrome. --}}
<div wire:key="{{ $wireKey }}"
     wire:ignore.self
     x-data="wireEditableCell({
        island: 'data-region',
        value: {{ $valueJson }},
        recordVersion: '{{ $recordVersion }}',
        saveOnBlur: {{ $saveOnBlur }},
        saveOnEnter: {{ $saveOnEnter }},
        liveValidation: {{ $liveValidation }},
        debounce: {{ $debounce }},
     })"
     data-record-key="{{ $recordKey }}"
     data-column-name="{{ $columnName }}"
    data-testid="table-editable-{{ $columnName }}"
     data-msg-error="{{ __('wire-table::messages.error') }}"
     data-msg-save-failed="{{ __('wire-table::messages.save_failed') }}"
     data-msg-invalid="{{ __('wire-table::messages.invalid') }}"
     class="relative"
>
    {!! $syncHtml !!}

    {{-- Prefix --}}
    @if($hasPrefix)
        <span class="absolute inset-y-0 left-0 flex items-center pl-2 text-gray-500 dark:text-gray-400 text-sm pointer-events-none">
            {{ $column->getInputPrefix() }}
        </span>
    @endif

    {{-- Input wrapper --}}
    <div class="relative">
        <input {!! $attrs !!}
               x-model="value"
               :readonly="saving"
               :aria-busy="saving"
               @focus="onFocus()"
               @blur="onBlur()"
               @keydown.enter.prevent="onEnter()"
               @keydown.escape="onEscape()"
               :class="{'border-red-500 focus:border-red-500 focus:ring-red-500': error, 'border-green-500 focus:border-green-500 focus:ring-green-500': success}"
               x-ref="input"
               class="{{ $inputClasses }}"
        >

        {{-- Saving spinner --}}
        <span x-show="saving" x-cloak class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none">
            {!! $spinnerHtml !!}
        </span>

        {{-- Success check --}}
        <span x-show="success" x-cloak x-transition class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none">
            {!! $checkHtml !!}
        </span>

        {{-- Suffix --}}
        @if($hasSuffix)
            <span x-show="!saving && !success" class="absolute inset-y-0 right-0 flex items-center pr-2 text-gray-500 dark:text-gray-400 text-sm pointer-events-none">
                {{ $column->getInputSuffix() }}
            </span>
        @endif
    </div>

    {{-- Error message --}}
    <p x-show="error" x-cloak x-text="error" class="mt-1 text-xs text-red-600 dark:text-red-400"></p>

    {{-- Helper text --}}
    @if($column->getHelperText())
        <p x-show="!error" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            {{ $column->getHelperText() }}
        </p>
    @endif
</div>
