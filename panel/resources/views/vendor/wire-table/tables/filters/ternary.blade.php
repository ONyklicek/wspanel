{{-- Ternary (yes/no/all) filter --}}
{{-- Variables: $filter, $value --}}
@php
    $name = $filter->getName();
    $label = $filter->getLabel();
    $rawValue = is_array($value) && array_key_exists('value', $value) ? $value['value'] : $value;
    $currentValue = $rawValue ?? $filter->getDefault();
    // Normalize to the option keys so a value that arrived as a real bool or as
    // 1/0 from the URL still marks the right option selected.
    $selectedValue = match ($filter->normalizeValue($currentValue)) {
        true => 'true',
        false => 'false',
        default => null,
    };
@endphp

<div class="flex flex-col gap-1">
    {{-- Follows the control a phone sees: the native twin of ->nativeOnMobile(). --}}
    @foreach(\NyonCode\WireCore\Foundation\Support\MobileSheet::labelTargets('filter-' . $name, $filter->mobileControlIdFor('filter-' . $name), $filter->getMobileBreakpoint()) as $labelFor => $labelVisibility)
    <label for="{{ $labelFor }}" @class(array_filter(['text-sm font-medium text-gray-700 dark:text-gray-300', $labelVisibility]))>
        {{ $label }}
    </label>
    @endforeach
    {{-- The same select surface as the select filter and the forms Select.
         "All" is the placeholder — picking it clears the filter. --}}
    @include('wire-core::partials.select-control', [
        'nativeMode' => $filter->getNativeControlMode(),
        'selectId' => 'filter-' . $name,
        'statePath' => 'tableState.filters.' . $name . '.value',
        'options' => $filter->getOptions(),
        'placeholder' => $filter->getAllLabel(),
        'multiple' => false,
        'searchable' => false,
        'searchPrompt' => __('Search...'),
        'noResultsMessage' => __('No results found'),
        'sheetOnMobile' => $filter->usesSheetOnMobile(),
        'touch' => $filter->usesTouchOnMobile(),
        'sheetTitle' => $label,
        'mobileBreakpoint' => $filter->getMobileBreakpoint(),
        'live' => true,
        'selectedValues' => $selectedValue === null ? [] : [$selectedValue],
        'ariaLabel' => $label,
        'testId' => 'filter-' . $name,
    ])
</div>
