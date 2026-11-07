{{-- CheckboxColumn cell --}}
@php
    /** @var bool $state */
    /** @var string $recordKey */
    /** @var string $columnName */
    /** @var bool $disabled */
    /** @var string $accentColorClass canonical accent from HasColor::getTextColorClasses */
    /** @var string $recordVersion optimistic-lock version (updated_at timestamp) */

    $wireKey = "chk-{$recordKey}-{$columnName}";
@endphp

<div
    wire:key="{{ $wireKey }}"
    wire:ignore.self
    x-data="wireEditableCell({
        island: 'data-region',
        value: {{ $state ? 'true' : 'false' }},
        recordVersion: '{{ $recordVersion }}',
        parse: (v) => v === true || v === 1 || v === '1' || v === 'true',
    })"
    data-record-key="{{ $recordKey }}"
    data-column-name="{{ $columnName }}"
    data-testid="table-editable-{{ $columnName }}"
    data-msg-error="{{ __('wire-table::messages.error') }}"
    data-msg-save-failed="{{ __('wire-table::messages.save_failed') }}"
>
    {!! $syncHtml !!}

    <input
        type="checkbox"
        :checked="value"
        :title="error"
        aria-label="{{ $columnName }}"
        @if($disabled) disabled @else @change="change($event.target.checked, $event)" :aria-busy="saving" @endif
        :class="{ 'ring-2 ring-red-500': error }"
        @class([
            'h-4 w-4 rounded-sm border-gray-300 dark:border-gray-600 dark:bg-gray-800 focus:ring-primary-500',
            $accentColorClass,
            'cursor-not-allowed opacity-50' => $disabled,
            'cursor-pointer' => ! $disabled,
        ])
    >

    {{-- Inline error (e.g. optimistic-lock conflict) — self-contained, no toast needed. --}}
    <p x-show="error" x-cloak x-text="error" class="mt-1 text-xs text-red-600 dark:text-red-400"></p>
</div>
