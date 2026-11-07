{{-- Select filter --}}
{{-- Variables: $filter, $value --}}
@php
    $name = $filter->getName();
    $label = $filter->getLabel();
    $rawValue = is_array($value) && array_key_exists('value', $value) ? $value['value'] : $value;
    $currentValue = $rawValue ?? $filter->getDefault();
    // Normalize the current value(s) to a list of comparable strings. Works for
    // both single and multiple selects and guards against array values reaching
    // a scalar/echo context (array default, multi/single mismatch, stale state).
    $selectedValues = array_values(array_map('strval', array_filter(
        is_array($currentValue) ? $currentValue : [$currentValue],
        static fn ($v) => $v !== null && $v !== '' && is_scalar($v),
    )));
@endphp

<div class="flex flex-col gap-1">
    {{-- Follows the control a phone sees: the native twin of ->nativeOnMobile(). --}}
    @foreach(\NyonCode\WireCore\Foundation\Support\MobileSheet::labelTargets('filter-' . $name, $filter->mobileControlIdFor('filter-' . $name), $filter->getMobileBreakpoint()) as $labelFor => $labelVisibility)
    <label for="{{ $labelFor }}" @class(array_filter(['text-sm font-medium text-gray-700 dark:text-gray-300', $labelVisibility]))>
        {{ $label }}
    </label>
    @endforeach
    {{-- Combobox, native <select>, or both split at the mobile breakpoint —
         the canonical core owner, shared with the forms Select. Non-searchable
         filters use the same combobox without the search input. --}}
    @include('wire-core::partials.select-control', [
        'nativeMode' => $filter->getNativeControlMode(),
        'selectId' => 'filter-' . $name,
        'statePath' => 'tableState.filters.' . $name . '.value',
        'options' => $filter->getOptions(),
        'placeholder' => $filter->getPlaceholder(),
        'multiple' => $filter->isMultiple(),
        'searchable' => $filter->isSearchable(),
        'searchPrompt' => __('Search...'),
        'noResultsMessage' => __('No results found'),
        'sheetOnMobile' => $filter->usesSheetOnMobile(),
        'touch' => $filter->usesTouchOnMobile(),
        'sheetTitle' => $label,
        'mobileBreakpoint' => $filter->getMobileBreakpoint(),
        // Filters apply immediately, whichever control draws them.
        'live' => true,
        'selectedValues' => $selectedValues,
        'ariaLabel' => $label,
        'testId' => 'filter-' . $name,
    ])
</div>
