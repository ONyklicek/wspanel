{{-- Column multi-select filter (inline, in the table header row).
     The canonical select surface (wire-core::partials.select-control) in
     multiple mode, shared with the filter panel and the forms Select, matching
     any of the picked values (whereIn). The filter's ->native(),
     ->nativeOnMobile() and ->sheetOnMobile() are honoured here as in the panel. --}}
{{-- Variables: $column, $filter, $value, $controlClasses --}}
@php
    $name = $column->getName();
    $placeholder = $filter->placeholder ?? __('wire-table::messages.filter_all');
@endphp

@include('wire-core::partials.select-control', [
    'nativeMode' => $filter->getNativeControlMode(),
    'mobileBreakpoint' => $filter->getMobileBreakpoint(),
    'selectId' => 'colfilter-'.$name,
    'statePath' => $statePath,
    'options' => $filter->getOptions(),
    'placeholder' => $placeholder,
    'multiple' => true,
    'searchable' => $filter->isSearchable(),
    'searchPrompt' => __('wire-table::messages.filter_search'),
    'noResultsMessage' => __('wire-table::messages.filter_no_results'),
    'sheetOnMobile' => $filter->usesSheetOnMobile(),
    'touch' => $filter->usesTouchOnMobile(),
    'sheetTitle' => $column->getLabel(),
    'live' => true,
    'ariaLabel' => $column->getLabel(),
])
