{{-- Column select filter (inline, in the table header row).
     The canonical select surface shared with the filter panel and the forms
     Select (wire-core::partials.select-control): the combobox by default, and the
     filter's ->native(), ->nativeOnMobile() and ->sheetOnMobile() honoured here
     exactly as in the panel. --}}
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
    'multiple' => false,
    'searchable' => $filter->isSearchable(),
    'searchPrompt' => __('wire-table::messages.filter_search'),
    'noResultsMessage' => __('wire-table::messages.filter_no_results'),
    // The filter's own resolution: searchable filters keep the floating panel
    // on mobile so the search input stays usable, and ->sheetOnMobile() wins.
    'sheetOnMobile' => $filter->usesSheetOnMobile(),
    'touch' => $filter->usesTouchOnMobile(),
    'sheetTitle' => $column->getLabel(),
    // Column filters apply immediately.
    'live' => true,
    'ariaLabel' => $column->getLabel(),
])
