{{-- Column number range filter (inline, in table header row) --}}
{{-- Variables: $column, $filter, $value, $controlClasses --}}
@php
    $name = $column->getName();
    $minValue = $filter->getMin();
    $maxValue = $filter->getMax();
    $step = $filter->getStep();
    $debounce = 300;
    $fromValue = is_array($value) ? ($value['min'] ?? '') : '';
    $toValue = is_array($value) ? ($value['max'] ?? '') : '';
@endphp

<div class="flex gap-1 items-center">
    <input
        type="number"
        wire:model.live.debounce.{{ $debounce }}ms="{{ $statePath }}.min"
        @if($minValue !== null) min="{{ $minValue }}" @endif
        @if($maxValue !== null) max="{{ $maxValue }}" @endif
        @if($step) step="{{ $step }}" @endif
        placeholder="{{ __('wire-table::messages.filter_min') }}"
        title="{{ __('wire-table::messages.filter_min') }}"
        class="{{ $controlClasses }}"
    >
    <span class="text-gray-400 text-xs flex-shrink-0">–</span>
    <input
        type="number"
        wire:model.live.debounce.{{ $debounce }}ms="{{ $statePath }}.max"
        @if($minValue !== null) min="{{ $minValue }}" @endif
        @if($maxValue !== null) max="{{ $maxValue }}" @endif
        @if($step) step="{{ $step }}" @endif
        placeholder="{{ __('wire-table::messages.filter_max') }}"
        title="{{ __('wire-table::messages.filter_max') }}"
        class="{{ $controlClasses }}"
    >
</div>
