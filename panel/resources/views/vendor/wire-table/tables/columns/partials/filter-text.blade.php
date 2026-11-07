{{-- Column text filter (inline, in table header row) --}}
{{-- Variables: $column, $filter, $value, $controlClasses --}}
@php
    $name = $column->getName();
    $placeholder = $filter->placeholder ?? __('wire-table::messages.filter_placeholder');
    $debounce = $filter->getDebounce() ?? 300;
@endphp

<input
    type="text"
    wire:model.live.debounce.{{ $debounce }}ms="{{ $statePath }}"
    placeholder="{{ $placeholder }}"
    class="{{ $controlClasses }}"
>
