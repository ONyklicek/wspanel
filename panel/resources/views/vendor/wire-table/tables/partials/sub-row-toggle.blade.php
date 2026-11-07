{{-- Sub-row expand/collapse toggle button --}}
{{-- Variables: $keyJs (the record key, already encoded for an Alpine expression),
     $isExpanded --}}
{{-- ⌥/Alt-click promotes the click to the master toggle, the shortcut spreadsheet
     and grid users already expect. Plain clicks toggle just this row. --}}
<button
    type="button"
    x-on:click="$event.altKey
        ? $wire.toggleAllRowExpansion()
        : $wire.toggleRowExpansion({!! $keyJs !!})"
    data-testid="table-row-expand" @wireEl('table-row-expand')
    aria-expanded="{{ $isExpanded ? 'true' : 'false' }}"
    aria-label="{{ $isExpanded ? __('wire-table::messages.collapse') : __('wire-table::messages.expand') }}"
    class="inline-flex items-center justify-center w-6 h-6 rounded-sm transition-colors hover:bg-gray-100 dark:hover:bg-gray-700 focus:outline-none"
    title="{{ $isExpanded ? __('wire-table::messages.collapse') : __('wire-table::messages.expand') }}"
>{{-- The tags touch: this partial is compiled into a per-shape skeleton and its markup
    is emitted on every expandable row, so a whitespace run here is a DOM text node the
    morph walks once per row. Whitespace between the attributes above costs nothing. --}}{!! icon('outline:chevron-right', 'w-4 h-4', 'text-gray-500 dark:text-gray-400 transition-transform duration-200 '.($isExpanded ? 'rotate-90' : '')) !!}</button>
