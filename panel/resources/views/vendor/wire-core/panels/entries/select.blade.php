@php
    use NyonCode\WireCore\Panels\Components\SelectEntry;

    assert($field instanceof SelectEntry);

    // The canonical owner, not a sixth copy of its `match`: HasColumnSpan
    // exists so a span means the same thing on every grid surface, and five
    // views had quietly kept their own.
    $spanClass = $field->getColumnSpanClass();

    $name = $field->getName();
    $state = $field->getState();
    $stateString = $state === null ? '' : (string) $state;
    $options = $field->getOptions();
    $placeholder = $field->getPlaceholder();
    $disabled = ! $field->isEditable() || $field->isDisabled();
@endphp

<div class="{{ $spanClass }}">
    @if($field->hasVisibleLabel())
        @include('wire-core::partials.entry-label', ['text' => $field->getLabel()])
    @endif

    <div
        wire:key="panel-sel-{{ $name }}"
        wire:ignore.self
        x-data="wireEditableCell({
            value: {{ \Illuminate\Support\Js::from($stateString) }},
            recordVersion: '{{ $field->getRecordVersion() }}',
            commitMethod: 'updatePanelEntry',
        })"
        data-record-key="{{ $field->getRecordKey() }}"
        data-column-name="{{ $name }}"
        data-testid="panel-editable-{{ $name }}"
        data-msg-error="{{ __('wire-core::messages.error') }}"
        data-msg-save-failed="{{ __('wire-core::messages.save_failed') }}"
    >
        {!! $field->getSyncHtml($stateString) !!}
        <select
            x-model="value"
            @change="change($event.target.value, $event)"
            :aria-busy="saving"
            aria-label="{{ $field->getLabel() ?? $name }}"
            :title="error"
            :class="{ 'border-red-500 focus:border-red-500 focus:ring-red-500': error }"
            @if($disabled) disabled @endif
            class="block w-full rounded-md border border-gray-300 bg-white shadow-sm text-base sm:text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 hover:border-gray-400 dark:hover:border-gray-500 transition-colors duration-150 disabled:opacity-50 disabled:cursor-not-allowed dark:bg-gray-800 dark:border-gray-600 dark:text-white"
        >
            @if($placeholder)
                <option value="" @if($stateString === '') selected @endif disabled>{{ $placeholder }}</option>
            @endif

            @foreach($options as $value => $label)
                <option value="{{ $value }}" @if($stateString === (string) $value) selected @endif>{{ $label }}</option>
            @endforeach
        </select>

        <p x-show="error" x-cloak x-text="error" class="mt-1 text-xs text-red-600 dark:text-red-400"></p>
    </div>
</div>
