{{-- Master expand/collapse for every row's sub-rows. --}}
{{-- Variables: $allRowsExpanded, $label --}}
@php
    $title = $allRowsExpanded
        ? __('wire-table::messages.collapse_all')
        : __('wire-table::messages.expand_all');
@endphp

<button
    type="button"
    wire:click="toggleAllRowExpansion"
    data-testid="subrows-master-toggle" @wireEl('subrows-master-toggle')
    aria-expanded="{{ $allRowsExpanded ? 'true' : 'false' }}"
    aria-label="{{ $title }}"
    title="{{ $title }}{{ $label ? ' — '.$label : '' }}"
    class="inline-flex items-center justify-center w-6 h-6 rounded-sm transition-colors hover:bg-gray-100 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary-500"
>
    {!! icon(
        $allRowsExpanded ? 'outline:chevron-double-down' : 'outline:chevron-double-right',
        'w-4 h-4',
        'text-gray-500 dark:text-gray-500 transition-transform duration-200',
    ) !!}
</button>
