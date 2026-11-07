@php
    use NyonCode\WireCore\Panels\Components\CheckboxEntry;

    assert($field instanceof CheckboxEntry);

    // The canonical owner, not a sixth copy of its `match`: HasColumnSpan
    // exists so a span means the same thing on every grid surface, and five
    // views had quietly kept their own.
    $spanClass = $field->getColumnSpanClass();

    $name = $field->getName();
    $state = (bool) $field->getState();
    $disabled = ! $field->isEditable() || $field->isDisabled();
@endphp

<div class="{{ $spanClass }}">
    <div
        wire:key="panel-chk-{{ $name }}"
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
        <label @class(['inline-flex items-center gap-2', 'cursor-not-allowed opacity-50' => $disabled, 'cursor-pointer' => ! $disabled])>
            <input
                type="checkbox"
                :checked="value"
                aria-label="{{ $field->getLabel() ?? $name }}"
                @if($disabled) disabled @else @change="change($event.target.checked, $event)" :aria-busy="saving" @endif
                :class="{ 'ring-2 ring-red-500': error }"
                class="h-4 w-4 rounded-sm border-gray-300 dark:border-gray-600 dark:bg-gray-800 {{ $field->getAccentColorClass() }} focus:ring-primary-500"
            >
            @if($field->getLabel())
                <span class="text-sm text-gray-900 dark:text-white">{{ $field->getLabel() }}</span>
            @endif
        </label>

        <p x-show="error" x-cloak x-text="error" class="mt-1 text-xs text-red-600 dark:text-red-400"></p>
    </div>
</div>
