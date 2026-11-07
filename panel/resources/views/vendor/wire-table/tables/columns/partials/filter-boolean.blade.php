{{-- Column boolean filter (inline, in the table header row).
     The canonical select surface (wire-core::partials.select-control) shared
     with the select filters and the forms Select, so a boolean header filter
     matches the select ones next to it. "All" is the placeholder (clears the
     filter). The filter's ->native(), ->nativeOnMobile() and ->sheetOnMobile()
     are honoured here as in the panel. --}}
{{-- Variables: $column, $filter, $value, $controlClasses --}}
@php
    $name = $column->getName();
@endphp

@include('wire-core::partials.select-control', [
    'nativeMode' => $filter->getNativeControlMode(),
    'mobileBreakpoint' => $filter->getMobileBreakpoint(),
    'selectId' => 'colfilter-'.$name,
    'statePath' => $statePath,
    'options' => $filter->getOptions(),
    'placeholder' => $filter->getAllLabel(),
    'multiple' => false,
    'searchable' => false,
    'searchPrompt' => __('wire-table::messages.filter_search'),
    'noResultsMessage' => __('wire-table::messages.filter_no_results'),
    'sheetOnMobile' => $filter->usesSheetOnMobile(),
    'touch' => $filter->usesTouchOnMobile(),
    'sheetTitle' => $column->getLabel(),
    // Column filters apply immediately.
    'live' => true,
    'ariaLabel' => $column->getLabel(),
])
