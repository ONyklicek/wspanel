{{-- Sort control for the stacked card view. --}}
{{-- Variables: $table, $component, $sortableColumns, $sortColumn, $sortDirection,
     $visibleClass, $sheetOnMobile, $sheetBpPx, $sheetBp, $sheetBackdrop,
     $sheetPanel, $sheetMotion --}}
@php
    $activeColumn = null;
    foreach ($sortableColumns as $column) {
        if ($column->getName() === $sortColumn) {
            $activeColumn = $column;
            break;
        }
    }
    $isDesc = $sortDirection === 'desc';
@endphp

@include('wire-core::partials.floating-assets')

{{-- $visibleClass is the same class the cards use, so this control exists exactly
     where the sortable header row does not. --}}
<div class="{{ $visibleClass }}">
    <div
            x-data="wireDropdown({ placement: 'bottom-end'{{ $sheetOnMobile ? ', sheetOnMobile: true, sheetBreakpoint: '.$sheetBpPx : '' }} })"
            @keydown.escape.window="close()"
            class="relative"
    >
        <button
                x-ref="trigger"
                @click="toggle()"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 px-2.5 py-2 text-sm text-gray-500 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-primary-500"
                title="{{ __('wire-table::messages.sort_by') }}"
                data-testid="table-mobile-sort" @wireEl('table-mobile-sort')
        >
            {!! icon('outline:bars-arrow-down', 'h-4 w-4 shrink-0') !!}
            {{-- The trigger names the active sort, so the current order is readable
                 without opening anything. --}}
            <span class="max-w-[8rem] truncate">
                {{ $activeColumn?->getLabel() ?? __('wire-table::messages.sort_by') }}
            </span>
            @if($activeColumn)
                {!! icon($isDesc ? 'outline:arrow-down' : 'outline:arrow-up', 'h-3.5 w-3.5 shrink-0') !!}
            @endif
        </button>

        <template x-teleport="body">
            <div>
                @if($sheetOnMobile)
                    <div
                            x-show="open"
                            x-cloak
                            x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0"
                            x-transition:enter-end="opacity-100"
                            x-transition:leave="transition ease-in duration-100"
                            x-transition:leave-start="opacity-100"
                            x-transition:leave-end="opacity-0"
                            @click="close()"
                            class="fixed inset-0 z-40 bg-gray-500/60 dark:bg-gray-900/70 {{ $sheetBackdrop }}"
                    ></div>
                @endif

                <div
                        x-ref="panel"
                        x-show="open"
                        @click.outside="$clickedInside($event) || close()"
                        @if($sheetOnMobile) x-focus-trap="open" tabindex="-1" data-sheet-bp="{{ $sheetBpPx }}" @endif
                        x-transition:enter="transition ease-out duration-100"
                        x-transition:enter-start="transform opacity-0 scale-95 {{ $sheetOnMobile ? $sheetMotion : '' }}"
                        x-transition:enter-end="transform opacity-100 scale-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-75"
                        x-transition:leave-start="transform opacity-100 scale-100 translate-y-0"
                        x-transition:leave-end="transform opacity-0 scale-95 {{ $sheetOnMobile ? $sheetMotion : '' }}"
                        @class([
                            'absolute top-0 left-0 origin-top-right w-60 rounded-xl bg-white dark:bg-gray-800 shadow-lg ring-1 ring-gray-200 dark:ring-gray-700 z-50 max-h-80 overflow-y-auto',
                            $sheetPanel => $sheetOnMobile,
                        ])
                        x-cloak
                        style="display: none;"
                >
                    @if($sheetOnMobile)
                        @include('wire-core::partials.sheet-grabber', ['dismiss' => 'close()', 'breakpoint' => $sheetBp])
                    @endif

                    <div class="p-2">
                        <div class="mb-1 border-b border-gray-100 dark:border-gray-700 px-3 py-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            {{ __('wire-table::messages.sort_by') }}
                        </div>

                        @foreach($sortableColumns as $column)
                            @php $isActive = $column->getName() === $sortColumn; @endphp
                            {{-- Tapping the active column flips the direction, the same rule
                                 the desktop header follows. --}}
                            <button
                                    type="button"
                                    wire:click="sortTable('{{ $column->getName() }}')"
                                    @click="close()"
                                    data-testid="table-mobile-sort-{{ $column->getName() }}"
                                    aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                                    class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-700/50 {{ $isActive ? 'text-primary-600 dark:text-primary-400 font-medium' : 'text-gray-700 dark:text-gray-300' }}"
                            >
                                <span class="flex-1 truncate">{{ $column->getLabel() }}</span>
                                @if($isActive)
                                    {!! icon($isDesc ? 'outline:arrow-down' : 'outline:arrow-up', 'h-4 w-4 shrink-0') !!}
                                    <span class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ $isDesc ? __('wire-table::messages.sort_desc') : __('wire-table::messages.sort_asc') }}
                                    </span>
                                @endif
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>
        </template>
    </div>
</div>
