{{--
    Prev/next for a cursor paginator.

    Separate from the simple partial because the controls cannot call
    `previousPage`/`nextPage`: Livewire's pagination is page-based and has no
    cursor equivalent. A cursor is an opaque position in an ordering, so the only
    thing that can produce the next one is the paginator holding the current page
    — which is what these buttons hand back through `setTableCursor()`.

    `previousCursor()` is null on the first page and `nextCursor()` is null on the
    last, so the disabled states come from the cursors themselves rather than from
    a page count the paginator does not have. There is no page number either: a
    cursor paginator does not know which page it is on.

    Variables: $paginator (from `links()`).
--}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('wire-table::messages.pagination_navigation') }}" class="flex items-center gap-1">
        @php
            $previousCursor = $paginator->previousCursor();
            $nextCursor = $paginator->nextCursor();
        @endphp

        {{-- Previous --}}
        @if ($previousCursor === null)
            <span class="relative inline-flex items-center px-2 py-1.5 text-sm font-medium text-gray-300 dark:text-gray-600 cursor-not-allowed rounded-lg">
                {!! icon('outline:chevron-left', 'w-5 h-5') !!}
            </span>
        @else
            <button
                    wire:click="setTableCursor('{{ $previousCursor->encode() }}')"
                    wire:loading.attr="disabled"
                    data-testid="table-page-prev" @wireEl('table-page-prev')
                    aria-label="{{ __('wire-table::messages.pagination_previous') }}"
                    class="relative inline-flex items-center px-2 py-1.5 text-sm font-medium text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500"
            >
                {!! icon('outline:chevron-left', 'w-5 h-5') !!}
            </button>
        @endif

        {{-- Next --}}
        @if ($nextCursor === null)
            <span class="relative inline-flex items-center px-2 py-1.5 text-sm font-medium text-gray-300 dark:text-gray-600 cursor-not-allowed rounded-lg">
                {!! icon('outline:chevron-right', 'w-5 h-5') !!}
            </span>
        @else
            <button
                    wire:click="setTableCursor('{{ $nextCursor->encode() }}')"
                    wire:loading.attr="disabled"
                    data-testid="table-page-next" @wireEl('table-page-next')
                    aria-label="{{ __('wire-table::messages.pagination_next') }}"
                    class="relative inline-flex items-center px-2 py-1.5 text-sm font-medium text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500"
            >
                {!! icon('outline:chevron-right', 'w-5 h-5') !!}
            </button>
        @endif
    </nav>
@endif
