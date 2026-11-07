{{-- ToggleColumn cell --}}
@php
    /** @var bool $state */
    /** @var string $recordKey */
    /** @var string $columnName */
    /** @var bool $disabled */
    /** @var string $onColorClass canonical "on" track fill from HasColor::getSolidBgClass */
    /** @var string $offColorClass canonical "off" track fill from HasColor::getSoftBgClass */
    /** @var string $recordVersion optimistic-lock version (updated_at timestamp) */

    $cursorClass = $disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer';
    $wireKey = "tgl-{$recordKey}-{$columnName}";
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

    <button
        type="button"
        role="switch"
        :aria-checked="value ? 'true' : 'false'"
        :title="error"
        @if($disabled) disabled @else @click="change(! value)" :aria-busy="saving" @endif
        :class="{
            '{{ $onColorClass }}': value && ! error,
            '{{ $offColorClass }}': ! value && ! error,
            'bg-red-100 dark:bg-red-900/30 ring-2 ring-red-500': error,
        }"
        class="relative inline-flex h-6 w-11 flex-shrink-0 {{ $cursorClass }} rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
    >
        <span class="sr-only">Toggle</span>
        <span
            aria-hidden="true"
            :class="value ? 'translate-x-5' : 'translate-x-0'"
            class="pointer-events-none inline-flex items-center justify-center h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
        >
            {{-- Optional state icons ride inside the knob; x-show swaps them with
                 the live Alpine value, so the knob stays a single moving element. --}}
            @if($onIcon)
                <span x-show="value" x-cloak>{!! $onIcon !!}</span>
            @endif
            @if($offIcon)
                <span x-show="!value" x-cloak>{!! $offIcon !!}</span>
            @endif
        </span>
    </button>

    {{-- Inline error (e.g. optimistic-lock conflict) — self-contained, no toast needed. --}}
    <p x-show="error" x-cloak x-text="error" class="mt-1 text-xs text-red-600 dark:text-red-400"></p>
</div>
