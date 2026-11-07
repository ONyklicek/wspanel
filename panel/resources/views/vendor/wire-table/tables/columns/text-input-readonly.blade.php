{{-- TextInputColumn readonly cell --}}
{{-- Variables: $column, $record, $state --}}
@php
    $value = (string) ($state ?? '');
    $placeholder = $column->getPlaceholder() ?? '-';
    $prefix = $column->getInputPrefix();
    $suffix = $column->getInputSuffix();
@endphp

@if($value !== '')
    <span class="text-sm text-gray-900 dark:text-gray-100">
        @if($prefix){{ $prefix }}@endif{{ $value }}@if($suffix){{ $suffix }}@endif
    </span>
@else
    <span class="text-sm text-gray-500 dark:text-gray-400 italic">{{ $placeholder }}</span>
@endif
