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

        {{-- Page Numbers --}}
        <div class="hidden sm:flex items-center gap-1">
            @foreach ($elements as $element)
                {{-- "Three Dots" Separator --}}
                @if (is_string($element))
                    <span class="relative inline-flex items-center px-2 py-1.5 text-sm font-medium text-gray-500 dark:text-gray-400">
                        {{ $element }}
                    </span>
                @endif

                {{-- Array Of Links --}}
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="relative inline-flex items-center justify-center w-9 h-9 text-sm font-semibold text-white bg-primary-600 rounded-lg">
                                {{ $page }}
                            </span>
                        @else
                            <button
                                    wire:click="gotoPage({{ $page }})"
                                    data-testid="table-page-{{ $page }}"
                                    aria-label="{{ __('wire-table::messages.pagination_goto', ['page' => $page]) }}"
                                    class="relative inline-flex items-center justify-center w-9 h-9 text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500"
                            >
                                {{ $page }}
                            </button>
                        @endif
                    @endforeach
                @endif
            @endforeach
        </div>

        {{-- Mobile Page Info --}}
        <span class="sm:hidden text-sm text-gray-600 dark:text-gray-400 px-2">
            {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}
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