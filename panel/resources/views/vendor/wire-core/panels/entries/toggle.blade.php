@php
    use NyonCode\WireCore\Panels\Components\ToggleEntry;

    assert($field instanceof ToggleEntry);

    // The canonical owner, not a sixth copy of its `match`: HasColumnSpan
    // exists so a span means the same thing on every grid surface, and five
    // views had quietly kept their own.
    $spanClass = $field->getColumnSpanClass();

    $name = $field->getName();
    $state = (bool) $field->getState();
    $disabled = ! $field->isEditable() || $field->isDisabled();
    $cursorClass = $disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer';
@endphp

<div class="{{ $spanClass }}">
    @if($field->hasVisibleLabel())
        @include('wire-core::partials.entry-label', ['text' => $field->getLabel()])
    @endif

    <div
        wire:key="panel-tgl-{{ $name }}"
        wire:ignore.self
        x-data="wireEditableCell({
            value: {{ $state ? 'true' : 'false' }},
            recordVersion: '{{ $field->getRecordVersion() }}',
            commitMethod: 'updatePanelEntry',
            parse: (v) => v === true || v === 1 || v === '1' || v === 'true',
        })"
        data-record-key="{{ $field->getRecordKey() }}"
        data-column-name="{{ $name }}"
        data-testid="panel-editable-{{ $name }}"
        data-msg-error="{{ __('wire-core::messages.error') }}"
        data-msg-save-failed="{{ __('wire-core::messages.save_failed') }}"
    >
        {!! $field->getSyncHtml($state ? '1' : '0') !!}
        <button
            type="button"
            role="switch"
            :aria-checked="value ? 'true' : 'false'"
            aria-label="{{ $field->getLabel() ?? $name }}"
            :title="error"
            @if($disabled) disabled @else @click="change(! value)" :aria-busy="saving" @endif
            :class="{
                '{{ $field->getOnColorClass() }}': value && ! error,
                '{{ $field->getOffColorClass() }}': ! value && ! error,
                'bg-red-100 dark:bg-red-900/30 ring-2 ring-red-500': error,
            }"
            class="relative inline-flex h-6 w-11 flex-shrink-0 {{ $cursorClass }} rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2"
        >
            <span
                aria-hidden="true"
                :class="value ? 'translate-x-5' : 'translate-x-0'"
                class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
            ></span>
        </button>

        <p x-show="error" x-cloak x-text="error" class="mt-1 text-xs text-red-600 dark:text-red-400"></p>
    </div>
</div>
