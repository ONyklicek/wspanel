{{--
    Prev/next only, for a simple paginator.

    `Table::simplePagination()` trades the COUNT query for speed, so the paginator
    cannot say how many pages there are: no `total()`, no `lastPage()`, and
    `links()` passes no `$elements`. The numbered partial next door needs all
    three, which is why this one exists rather than a branch inside it — a missing
    `$elements` is an undefined-variable error, not a degraded render.

    What it does have is `onFirstPage()` and `hasMorePages()`, both answered from
    one extra row fetched beyond the page, so prev/next are exact rather than
    guesses. The current page number is shown without a total for the same reason.

    Variables: $paginator (from `links()`).
--}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('wire-table::messages.pagination_navigation') }}" class="flex items-center gap-1">
        {{-- Previous Page Link --}}
        @if ($paginator->onFirstPage())
            <span class="relative inline-flex items-center px-2 py-1.5 text-sm font-medium text-gray-300 dark:text-gray-600 cursor-not-allowed rounded-lg">
                {!! icon('outline:chevron-left', 'w-5 h-5') !!}
            </span>
        @else
            <button
                    wire:click="previousPage"
                    wire:loading.attr="disabled"
                    data-testid="table-page-prev" @wireEl('table-page-prev')
                    aria-label="{{ __('wire-table::messages.pagination_previous') }}"
                    class="relative inline-flex items-center px-2 py-1.5 text-sm font-medium text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500"
            >
                {!! icon('outline:chevron-left', 'w-5 h-5') !!}
            </button>
        @endif

        {{-- Page number, with no total to put it out of --}}
        <span class="text-sm text-gray-600 dark:text-gray-400 px-2" data-testid="table-page-current" @wireEl('table-page-current')>
            {{ $paginator->currentPage() }}
        </span>

        {{-- Next Page Link --}}
        @if ($paginator->hasMorePages())
            <button
                    wire:click="nextPage"
                    wire:loading.attr="disabled"
                    data-testid="table-page-next" @wireEl('table-page-next')
                    aria-label="{{ __('wire-table::messages.pagination_next') }}"
                    class="relative inline-flex items-center px-2 py-1.5 text-sm font-medium text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500"
            >
                {!! icon('outline:chevron-right', 'w-5 h-5') !!}
            </button>
        @else
            <span class="relative inline-flex items-center px-2 py-1.5 text-sm font-medium text-gray-300 dark:text-gray-600 cursor-not-allowed rounded-lg">
                {!! icon('outline:chevron-right', 'w-5 h-5') !!}
            </span>
        @endif
    </nav>
@endif
