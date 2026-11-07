{{-- Column date filter (inline, in table header row) --}}
{{-- Variables: $column, $filter, $value, $controlClasses --}}
@php
    $name = $column->getName();
    $minDate = $filter->getMinDate();
    $maxDate = $filter->getMaxDate();
@endphp

<input
    type="date"
    wire:model.live="{{ $statePath }}"
    @if($minDate) min="{{ $minDate }}" @endif
    @if($maxDate) max="{{ $maxDate }}" @endif
    class="{{ $controlClasses }}"
>
