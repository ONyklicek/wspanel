@php
    use Illuminate\Contracts\Pagination\LengthAwarePaginator;
    use Illuminate\Support\Collection;
    use NyonCode\WireTable\Table;

    assert($table instanceof Table);

    /** @var LengthAwarePaginator|Collection $records */
    /** @var mixed $component */

    // What this render resolved, in PHP. Asked for here rather than handed in,
    // because a view may reconfigure the table before this one renders — see
    // WithTable::tableRenderPlan(), and wire-sortable's column order for the case
    // that proves it.
    $plan = $component->tableRenderPlan();

    // This is the WRAPPER's half of the plan. The table, the stacked cards and the
    // pagination footer moved to partials/data-region.blade.php, which is an
    // ISLAND: Livewire renders an island body from its own view file, with the
    // component and its public properties and nothing else, so it cannot inherit
    // one line of what follows. It reads its own half from the same plan.
    //
    // What is left here is everything the region does not touch: the toolbar and
    // its panels, the selection root, the polling wrapper and the modals.

    // The frame around the rows — whether it renders yet (lazy), how it keeps
    // itself current (poll, and the broadcast channel `live(broadcast: true)`
    // adds), and which optional regions exist at all. All resolved in
    // ShellRenderPlan, including the three feature guards this block used to
    // write as `&&` pairs.
    $isLazy = $plan->shell()->isLazy;
    $isTableReady = $plan->shell()->isTableReady;
    $lazyPlaceholder = $plan->shell()->lazyPlaceholder;
    $pollingConfig = $plan->shell()->pollingConfig;
    $pollingAttribute = $plan->shell()->pollingAttribute;
    $liveChannel = $plan->shell()->liveChannel;
    $filters = $plan->shell()->filters;
    $hasFilters = $plan->shell()->hasFilters;
    $isSubRowsExpandable = $plan->shell()->isSubRowsExpandable;
    $allRowsExpanded = $plan->shell()->allRowsExpanded;
    // The view menu earns its place from either section it can hold.
    $hasViewMenu = $plan->shell()->hasViewMenu;
    $hasSavedViews = $plan->shell()->hasSavedViews;
    $savedViews = $plan->shell()->savedViews;
    $viewMenuLabel = $plan->shell()->viewMenuLabel;

    // Table state. Which filters count as ACTIVE is a rule with a sharp edge (see
    // TableQueryState) that used to live here as a recursive closure. The
    // magic-property hazard this block used to warn about goes with it: the plan
    // reads the state container, so there is nothing here to reach for.
    $tableFilters = $plan->state()->filters;
    $activeTableFilters = $plan->state()->activeFilters;
    // The mobile sort control names the current column and direction.
    $sortColumn = $plan->state()->sortColumn;
    $sortDirection = $plan->state()->sortDirection;

    // Floating filter/column-toggle panels present as a bottom sheet on mobile
    // unless disabled via Table::sheetOnMobile(false) or the global config. The
    // five class strings all derive from the one breakpoint — LayoutRenderPlan
    // resolves them together so a partial cannot recompute them differently.
    $sheetOnMobile = $plan->layout()->sheetOnMobile;
    $sheetBp = $plan->layout()->sheetBreakpoint;
    $sheetBpPx = $plan->layout()->sheetBreakpointPx;
    $sheetPanel = $plan->layout()->sheetPanel;
    $sheetMotion = $plan->layout()->sheetMotion;
    $sheetBackdrop = $plan->layout()->sheetBackdrop;
    $cardsVisibleClass = $plan->layout()->cardsVisibleClass;

    // Actions — the two surfaces the toolbar owns (bulk and header), their
    // collapsed dropdown forms, and the click resolver that keeps wire-core's
    // action views host-agnostic. Row and mobile-card actions belong to the
    // region.
    $bulkActions = $plan->actions()->bulk;
    $headerActions = $plan->actions()->header;
    $hasBulkActions = $plan->actions()->hasBulk;
    $hasHeaderActions = $plan->actions()->hasHeader;
    $collapseHeaderActions = $plan->actions()->collapseHeader;
    $mobileHeaderActionGroup = $plan->actions()->mobileHeaderGroup;
    $headerActionClick = $plan->actions()->headerClick;

    // The `?` shortcut help. Both look unused below: they reach
    // partials.shortcut-help-modal through @include scope inheritance.
    $shortcutLegend = $plan->interaction()->shortcutLegend;
    $shortcutHelpEvent = $plan->interaction()->shortcutHelpEvent;

    // Selection lives on the wrapper, not in the region: the Alpine root has to
    // enclose both renderings of the page (desktop rows and mobile cards) and the
    // bulk bar above them, and it must survive a targeted render of the region.
    $isSelectable = $plan->row()->isSelectable;
    $pageRecordKeys = $plan->row()->pageRecordKeys;
    $selectionSyncLive = $plan->row()->selectionSyncLive;
    $selectionAnnouncements = $plan->row()->selectionAnnouncements;

    // What the toolbar's column menu, the mobile sort control and the clipboard
    // controller each read.
    $toggleableColumns = $plan->columns()->toggleable;
    $visibleToggleableCount = $plan->columns()->visibleToggleableCount;
    $hasColumnToggles = $plan->columns()->hasToggles;
    $mobileSortableColumns = $plan->columns()->mobileSortable;
    $hasMobileSort = $plan->columns()->hasMobileSort;
    $hasCopyableColumn = $plan->columns()->hasCopyable;

    // The size of the whole result set, for the selection strip's "n of N".
    $recordCount = $plan->paging()->recordCount;
@endphp

{{-- Lazy loading: trigger load when visible --}}
@if($isLazy && !$isTableReady)
    {{-- This used to force all three bundles out with the PLACEHOLDER render,
         because each registered its Alpine components from an `alpine:init`
         listener and that event fires exactly once, when Alpine boots: a bundle
         first emitted by the deferred render subscribed to an event that would
         never fire again and registered nothing.

         The bundles now register unconditionally (see AI_CODING_STANDARD.md
         § Rendering and js-asset-registration.md §3.A), so arriving late is safe,
         and Livewire already guarantees they are not late in the way that would
         matter here: on an AJAX round trip it awaits `payload.intercept` — which
         loads and runs the new @assets to completion — before `handleSuccess`
         morphs the markup in. The factory therefore exists before the deferred
         table is ever initialised, and pre-emitting would only pull bundles onto
         a page whose whole point is to defer them. --}}
    <div
            x-data="{ loaded: false }"
            x-intersect.once="if (!loaded) { loaded = true; $wire.loadTable(); }"
            class="w-full"
            wire:key="table-lazy-wrapper"
    >
        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700">
            <div class="p-8 flex flex-col items-center justify-center min-h-[300px]">
                @if($lazyPlaceholder)
                    {!! $lazyPlaceholder !!}
                @else
                    {{-- Default loading skeleton --}}
                    {{--<div class="w-full max-w-3xl space-y-4 animate-pulse">--}}
                    <div class="w-full space-y-4 animate-pulse">
                        {{-- Header skeleton --}}
                        <div
                                class="flex items-center justify-between pb-4 border-b border-gray-200 dark:border-gray-700">
                            <div class="h-10 bg-gray-200 dark:bg-gray-700 rounded-lg w-64"></div>
                            <div class="h-10 bg-gray-200 dark:bg-gray-700 rounded-lg w-32"></div>
                        </div>

                        {{-- Table header skeleton --}}
                        <div class="flex gap-4 py-3">
                            <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded-sm w-1/4"></div>
                            <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded-sm w-1/4"></div>
                            <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded-sm w-1/4"></div>
                            <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded-sm w-1/4"></div>
                        </div>

                        {{-- Row skeletons --}}
                        @for($i = 0; $i < 5; $i++)
                            <div class="flex gap-4 py-4 border-t border-gray-100 dark:border-gray-700/50">
                                <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded-sm w-1/4"></div>
                                <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded-sm w-1/4"></div>
                                <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded-sm w-1/4"></div>
                                <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded-sm w-1/4"></div>
                            </div>
                        @endfor

                        {{-- Footer skeleton --}}
                        <div
                                class="flex items-center justify-between pt-4 border-t border-gray-200 dark:border-gray-700">
                            <div class="h-8 bg-gray-200 dark:bg-gray-700 rounded-sm w-32"></div>
                            <div class="h-8 bg-gray-200 dark:bg-gray-700 rounded-sm w-48"></div>
                        </div>
                    </div>

                    <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('wire-table::messages.loading_table') }}</p>
                @endif
            </div>
        </div>
    </div>
@else
    {{-- Polling wrapper. Also the live listener's root, so `busy()` sees exactly
         the cells this table owns and a nested table cannot be mistaken for it.

         Which couples the two halves of live(): this wrapper only renders while
         shouldPoll() is true, so pausing the poll (the Stop control, or a
         pollWhen() condition turning false) takes the broadcast listener with it
         — and refreshTable() would refuse the nudge anyway. Intended for the
         Stop control, where "stop the table changing under me" should mean both.
         Not obviously right for pollWhen(), which is a cost condition rather than
         a statement of intent; a table combining it with broadcast: true loses
         the push exactly while the condition is false. Documented in
         docs/table/advanced.md rather than worked around here, because splitting
         them needs a second wrapper and a push-only host method. --}}
    @if($liveChannel)
        @include('wire-table::tables.partials.live-assets')
    @endif
    @if($plan->layout()->tracksClientLayout)
        @include('wire-table::tables.partials.viewport-assets')
    @endif
    @if($pollingAttribute)
        <div {!! $pollingAttribute !!}
             @if($liveChannel) x-data="wireTableLive(@js(['channel' => $liveChannel]))" @endif
        >
            @endif

            <div
                    class="w-full"
                    wire:key="table-wrapper"
                    {{-- The rows and the modals are islands; the poll, the search
                         box and the filters are not, and all of them write the same
                         table state. Livewire runs an island request and a root
                         request side by side and lets the later response win, so a
                         page-size change landing beside a poll tick was undone by
                         the tick. This asks wire-core to sequence them the way
                         Livewire sequences one scope (support/island-coordination.js). --}}
                    data-wire-islands="shared-state"
                    data-msg-unsaved-edits="{{ __('wire-table::messages.unsaved_edits') }}"
                    @if($isSelectable)
                        data-page-keys="{{ json_encode($pageRecordKeys) }}"
                        data-matching="{{ $recordCount }}"
                        data-selection-root
                        {{-- Contract marker between this (publishable) view and the
                             packaged JS: the bundled record-actions controller refuses a
                             stale published view out loud instead of selecting wrong
                             ranges silently. Bump only with the selection contract. --}}
                        data-selection-version="1"
                        {{-- One shared selection component (wireRecordSelection, shipped in
                             the package bundle): the checkboxes, both select-all toggles,
                             the bulk bar, the mobile cards and the keyboard gestures all
                             drive this state. PHP hands over the semantics — the state
                             path and the commit policy — so they stay assertable here. --}}
                        x-data="wireRecordSelection({ statePath: 'tableState.selection', syncLive: {{ $selectionSyncLive ? 'true' : 'false' }}, commitDelay: 350, announcements: @js($selectionAnnouncements) })"
                    @endif
            >
                @if($isSelectable)
                    {{-- Selection announcements. Deliberately NOT in the bulk bar: that
                         is behind x-show, and a hidden live region announces nothing —
                         worse, it disappears at zero selected, so "selection cleared"
                         could never be heard. This one is in the DOM from the first
                         render and empty, which is what a live region needs to work. --}}
                    <div
                            class="sr-only"
                            aria-live="polite"
                            aria-atomic="true"
                            data-testid="selection-live" @wireEl('selection-live')
                            x-text="announcement"
                    ></div>

                    {{-- Inside the selectable wrapper, NOT inside the table body: the
                         body is not rendered without visible columns, but the selection
                         is live in the stacked cards too. --}}
                    @once
                        @include('wire-table::tables.partials.selection-assets')
                    @endonce
                @endif
                {{-- `overflow-clip`, because the radius on this element is a promise
                     nothing inside it keeps on its own. A selected last row paints a
                     rectangle to the card's bottom edge and squares off the corner the
                     border is still curving around; the scroll-edge gradients do the
                     same at all four; a stripe, a hover tint and a row colour do it
                     the moment the row is the last one. Every one of those is a
                     background on a `<tr>` or on a full-height overlay, and neither
                     can be given a corner of its own — a `<tr>` has no border-box to
                     round, and an overlay does not know which of its ends is at the
                     card's edge.

                     Clip and not `overflow-hidden`: hidden makes this a scroll
                     container, and the nearest scroll container is what a
                     `position: sticky` descendant sticks inside. The pinned header
                     and the pinned actions column are safe either way — their
                     scroller is nearer — but the stacked cards' group headings
                     (`sticky top-0` in the data region) stick against the VIEWPORT,
                     and a scroll container here would silently leave them in flow on
                     a phone. `overflow: clip` clips without becoming one, so nothing
                     above it in the sticky chain changes. --}}
                <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 overflow-clip">

                    {{-- Header --}}
                    <div class="px-4 lg:px-6 py-4 border-b border-gray-200 dark:border-gray-700" @wireEl('table-toolbar')>
                        {{-- The two sides share a line while they fit and wrap when they do
                             not: the right side drops whole onto the next line, and then its
                             buttons wrap among themselves. Neither side is squeezed below its
                             content — before, the search box shrank to its icon and the last
                             buttons were clipped at the card's edge. --}}
                        <div class="flex flex-wrap items-center justify-between gap-4">
                            {{-- Left side: Search & Filters --}}
                            <div class="flex w-full sm:w-auto sm:grow sm:basis-[min-content] items-center gap-3">
                                {{-- Global Search: from `sm` up never narrower than something you can
                                     type into — the right side wraps before it gets squeezed. --}}
                                @if($table->isSearchable())
                                    <div class="relative flex-1 sm:min-w-[12rem] max-w-xs">
                                        <div
                                                class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                            {!! icon('outline:magnifying-glass', 'h-4 w-4', 'text-gray-400') !!}
                                        </div>
                                        <input
                                                type="search"
                                                wire:model.live.debounce.300ms="tableState.search"
                                                placeholder="{{ __('wire-table::messages.search') }}..."
                                                aria-label="{{ __('wire-table::messages.search') }}"
                                                data-testid="table-search" @wireEl('table-search')
                                                class="block w-full rounded-lg border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50 pl-9 pr-3 py-2 text-base sm:text-sm placeholder:text-gray-500 dark:placeholder:text-gray-400 focus:border-primary-500 focus:ring-primary-500 dark:text-white"
                                        >
                                    </div>
                                @endif

                                @wireRenderHook('table.toolbar.end')

                                {{-- Filters Toggle --}}
                                @if($hasFilters)
                                    @include('wire-core::partials.floating-assets')

                                    <div x-data="wireDropdown({ placement: 'bottom-start', offset: 8{{ $sheetOnMobile ? ', sheetOnMobile: true, sheetBreakpoint: '.$sheetBpPx : '' }} })" @keydown.escape.window="close()" class="relative">
                                        <button
                                                x-ref="trigger"
                                                @click="toggle()"
                                                type="button"
                                                data-testid="table-filters-trigger" @wireEl('table-filters-trigger')
                                                aria-label="{{ __('wire-table::messages.filters') }}"
                                                class="inline-flex items-center gap-2 rounded-lg border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 px-3 py-2 text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-primary-500"
                                        >
                                            {!! icon('outline:funnel', 'h-4 w-4') !!}
                                            <span>{{ __('wire-table::messages.filters') }}</span>
                                            @if($activeTableFilters !== [])
                                                <span
                                                        class="inline-flex items-center justify-center w-5 h-5 text-xs font-bold text-white bg-primary-600 rounded-full">
                                        {{ count($activeTableFilters) }}
                                    </span>
                                            @endif
                                        </button>

                                        {{-- Filters dropdown: floating panel from sm up, bottom sheet on
                                             a phone (max-sm: classes; wireDropdown skips Floating UI). --}}
                                        <template x-teleport="body">
                                            <div>
                                                @if($sheetOnMobile)
                                                {{-- Backdrop: mobile-only, taps to close. --}}
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
                                                        x-transition:enter-start="opacity-0 scale-95 {{ $sheetOnMobile ? $sheetMotion : '' }}"
                                                        x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                                                        x-transition:leave="transition ease-in duration-75"
                                                        x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                                                        x-transition:leave-end="opacity-0 scale-95 {{ $sheetOnMobile ? $sheetMotion : '' }}"
                                                        @class([
                                                            'absolute top-0 left-0 z-50 w-72 origin-top-left rounded-xl bg-white dark:bg-gray-800 shadow-lg ring-1 ring-gray-200 dark:ring-gray-700',
                                                            $sheetPanel => $sheetOnMobile,
                                                        ])
                                                        x-cloak
                                                        style="display: none;"
                                                >
                                                    @if($sheetOnMobile)
                                                        @include('wire-core::partials.sheet-grabber', ['dismiss' => 'close()', 'breakpoint' => $sheetBp])
                                                    @endif
                                                    <div class="p-4 space-y-4">
                                                        @foreach($filters as $filter)
                                                            @if($filter->canView())
                                                                {!! $filter->render($tableFilters[$filter->getName()] ?? null) !!}
                                                            @endif
                                                        @endforeach

                                                        <div class="pt-3 border-t border-gray-100 dark:border-gray-700">
                                                            <button
                                                                    type="button"
                                                                    wire:click="resetTableFilters"
                                                                    class="w-full text-center text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                                                            >
                                                                {{ __('wire-table::messages.filter_reset') }}
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                @endif
                            </div>

                            {{-- Right side: Polling, Header Actions, Column Toggle --}}
                            <div class="flex flex-wrap items-center gap-2">
                                {{-- Polling Indicator --}}
                                @include('wire-table::tables.partials.polling-indicator')

                                {{-- Sort, for the stacked card view only: the header row that
                                     carries the sort buttons is hidden at this width, which
                                     left a phone with no way to sort at all. --}}
                                @if($hasMobileSort)
                                    @include('wire-table::tables.partials.mobile-sort', [
                                        'table' => $table,
                                        'component' => $component,
                                        'sortableColumns' => $mobileSortableColumns,
                                        'sortColumn' => $sortColumn,
                                        'sortDirection' => $sortDirection,
                                        'visibleClass' => $cardsVisibleClass,
                                        'sheetOnMobile' => $sheetOnMobile,
                                        'sheetBpPx' => $sheetBpPx,
                                        'sheetBp' => $sheetBp,
                                        'sheetBackdrop' => $sheetBackdrop,
                                        'sheetPanel' => $sheetPanel,
                                        'sheetMotion' => $sheetMotion,
                                    ])
                                @endif

                                {{-- Plugin Toolbar Widgets --}}
                                @if(method_exists($component, 'getTableToolbarWidgets'))
                                    @foreach($component->getTableToolbarWidgets() as $widget)
                                        {!! $widget !!}
                                    @endforeach
                                @endif

                                {{-- Header Actions, as one group that wraps button by button with
                                     every label kept on one line. With
                                     collapseHeaderActionsOnMobile() the group hides below the mobile
                                     breakpoint and one dropdown trigger takes its place. --}}
                                @if($hasHeaderActions)
                                    <div class="{{ $collapseHeaderActions ? $table->getInlineHeaderActionsClass() : 'flex' }} flex-1 flex-wrap items-center gap-2 whitespace-nowrap" data-testid="table-header-actions" @wireEl('table-header-actions')>
                                        @include('wire-table::tables.partials.header-actions', ['headerActions' => $headerActions])
                                    </div>

                                    @if($collapseHeaderActions)
                                        <div class="{{ $table->getMobileHeaderActionsVisibleClass() }}" data-testid="table-header-actions-mobile" @wireEl('table-header-actions-mobile')>
                                            {!! $mobileHeaderActionGroup->render(null, $headerActionClick) !!}
                                        </div>
                                    @endif
                                @endif

                                {{-- View menu: column visibility + sub-row expansion, the two
                                     "how I look at this table" settings. Opens as a bottom sheet
                                     on a phone, which is where the master chevron cannot follow
                                     (the stacked card layout has no header row). --}}
                                @if($hasViewMenu)
                                    @include('wire-core::partials.floating-assets')

                                    <div
                                            x-data="wireDropdown({ placement: 'bottom-end'{{ $sheetOnMobile ? ', sheetOnMobile: true, sheetBreakpoint: '.$sheetBpPx : '' }} })"
                                            @keydown.escape.window="close()"
                                            class="relative"
                                    >
                                        <button
                                                x-ref="trigger"
                                                @click="toggle()"
                                                type="button"
                                                class="inline-flex items-center justify-center rounded-lg border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 p-2 text-gray-500 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-primary-500"
                                                title="{{ $viewMenuLabel }}"
                                                aria-label="{{ $viewMenuLabel }}"
                                                data-testid="table-column-toggle" @wireEl('table-column-toggle')
                                        >
                                            {!! icon('outline:view-columns', 'h-5 w-5') !!}
                                        </button>

                                        {{-- Column toggle: floating panel from sm up, bottom sheet on a
                                             phone (max-sm: classes; wireDropdown skips Floating UI). --}}
                                        <template x-teleport="body">
                                            <div>
                                                @if($sheetOnMobile)
                                                {{-- Backdrop: mobile-only, taps to close. --}}
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
                                                        'absolute top-0 left-0 origin-top-right w-56 rounded-xl bg-white dark:bg-gray-800 shadow-lg ring-1 ring-gray-200 dark:ring-gray-700 z-50 max-h-80 overflow-y-auto',
                                                        $sheetPanel => $sheetOnMobile,
                                                    ])
                                                    x-cloak
                                                    style="display: none;"
                                            >
                                                @if($sheetOnMobile)
                                                    @include('wire-core::partials.sheet-grabber', ['dismiss' => 'close()', 'breakpoint' => $sheetBp])
                                                @endif
                                                <div class="p-2">
                                                {{-- Saved views. First in the menu because switching view is
                                                     the coarsest thing here: it replaces the sort, the search,
                                                     the filters and the columns in one go, so reading the
                                                     column checkboxes below it before choosing a view would be
                                                     reading a layout that is about to be replaced. --}}
                                                @if($hasSavedViews)
                                                    <div class="px-3 py-2 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-100 dark:border-gray-700 mb-1">
                                                        {{ __('wire-table::messages.views_section') }}
                                                    </div>
                                                    @foreach($savedViews as $savedView)
                                                        <div class="flex items-center gap-1 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                                            <button
                                                                    type="button"
                                                                    wire:click="applyTableView(@js($savedView))"
                                                                    wire:loading.attr="disabled"
                                                                    wire:target="applyTableView(@js($savedView))"
                                                                    data-testid="table-view-{{ $savedView }}"
                                                                    class="flex-1 min-w-0 truncate px-3 py-2 text-left text-sm text-gray-700 dark:text-gray-300"
                                                            >{{ $savedView }}</button>
                                                            <button
                                                                    type="button"
                                                                    wire:click="deleteTableView(@js($savedView))"
                                                                    wire:loading.attr="disabled"
                                                                    wire:target="deleteTableView(@js($savedView))"
                                                                    data-testid="table-view-delete-{{ $savedView }}"
                                                                    title="{{ __('wire-table::messages.delete_view') }}"
                                                                    aria-label="{{ __('wire-table::messages.delete_view') }}"
                                                                    class="shrink-0 rounded-md p-1.5 mr-1 text-gray-500 hover:text-red-600 dark:hover:text-red-400"
                                                            >{!! icon('outline:trash', 'h-4 w-4') !!}</button>
                                                        </div>
                                                    @endforeach
                                                    {{-- The name is asked for in the browser rather than with a
                                                         field in this panel: the panel is teleported to <body>
                                                         and closes on click-outside, so an input in it would
                                                         lose what was typed to the first stray click. --}}
                                                    <button
                                                            type="button"
                                                            x-on:click="$wire.saveTableView(window.prompt(@js(__('wire-table::messages.save_view_prompt'))) ?? '')"
                                                            data-testid="table-view-save" @wireEl('table-view-save')
                                                            @class([
                                                                'mt-1 flex w-full items-center gap-3 px-3 py-2 text-left text-sm text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700/50 rounded-lg',
                                                                'border-t border-gray-100 dark:border-gray-700' => $savedViews !== [],
                                                            ])
                                                    >
                                                        {!! icon('outline:bookmark', 'h-4 w-4') !!}
                                                        {{ __('wire-table::messages.save_current_view') }}
                                                    </button>
                                                @endif
                                                @if($hasColumnToggles)
                                                <div
                                                        class="px-3 py-2 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-100 dark:border-gray-700 mb-1 {{ $hasSavedViews ? 'border-t mt-1' : '' }}">
                                                    {{ __('wire-table::messages.columns_section') }}
                                                </div>
                                                @endif
                                                @foreach($toggleableColumns as $column)
                                                    @php
                                                        $isVisible = $component->isColumnVisible($column->getName());
                                                        $isLastVisible = $isVisible && $visibleToggleableCount <= 1;
                                                    @endphp
                                                    <label
                                                            class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700/50 cursor-pointer select-none {{ $isLastVisible ? 'opacity-50' : '' }}">
                                                        <div class="flex items-center justify-center w-5 h-5 shrink-0">
                                                            <input
                                                                    type="checkbox"
                                                                    @if(!$isLastVisible)
                                                                        wire:click="toggleColumn('{{ $column->getName() }}')"
                                                                    @endif
                                                                    @checked($isVisible)
                                                                    @disabled($isLastVisible)
                                                                    class="h-4 w-4 rounded-sm border-gray-300 dark:border-gray-600 text-primary-600 focus:ring-primary-500 dark:bg-gray-700 {{ $isLastVisible ? 'cursor-not-allowed' : 'cursor-pointer' }}"
                                                            >
                                                        </div>
                                                        <span
                                                                class="text-sm text-gray-700 dark:text-gray-300">{{ $column->getLabel() }}</span>
                                                    </label>
                                                @endforeach
                                                {{-- Sub-row expansion: the baseline that survives paging,
                                                     and the only bulk expand/collapse a phone gets. --}}
                                                @if($isSubRowsExpandable)
                                                    <div
                                                            class="px-3 py-2 mt-1 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider {{ $hasColumnToggles ? 'border-t border-gray-100 dark:border-gray-700' : 'border-b border-gray-100 dark:border-gray-700 mb-1' }}">
                                                        {{ __('wire-table::messages.details_section') }}
                                                    </div>
                                                    <label
                                                            class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700/50 cursor-pointer select-none">
                                                        <div class="flex items-center justify-center w-5 h-5 shrink-0">
                                                            <input
                                                                    type="checkbox"
                                                                    wire:click="toggleAllRowExpansion"
                                                                    @checked($allRowsExpanded)
                                                                    data-testid="subrows-expand-all-rows" @wireEl('subrows-expand-all-rows')
                                                                    class="h-4 w-4 rounded-sm border-gray-300 dark:border-gray-600 text-primary-600 focus:ring-primary-500 dark:bg-gray-700 cursor-pointer"
                                                            >
                                                        </div>
                                                        <span class="text-sm text-gray-700 dark:text-gray-300">
                                                            {{ __('wire-table::messages.expand_all_rows') }}
                                                        </span>
                                                    </label>
                                                @endif

                                                {{-- Reset to the configured defaults (clears the saved layout). --}}
                                                @if($hasColumnToggles && $table->getRememberColumnsKey() !== null)
                                                    <button
                                                            type="button"
                                                            wire:click="resetColumns"
                                                            class="mt-1 flex w-full items-center gap-3 border-t border-gray-100 dark:border-gray-700 px-3 py-2 text-left text-sm text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700/50 rounded-lg"
                                                    >
                                                        {!! icon('outline:arrow-path', 'h-4 w-4') !!}
                                                        {{ __('wire-table::messages.reset_columns') }}
                                                    </button>
                                                @endif
                                                </div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- Active Filter Indicators --}}
                    @if($hasFilters)
                        @include('wire-table::tables.partials.filter-indicators', ['component' => $component])
                    @endif

                    {{-- Selection Bar (Alpine-driven — appears instantly, no roundtrip) --}}
                    @if($isSelectable)
                        <div
                                x-show="selectedCount > 0"
                                x-cloak
                                data-testid="table-bulk-bar" @wireEl('table-bulk-bar')
                                class="px-4 lg:px-6 py-3 bg-primary-50 dark:bg-primary-900/20 border-b border-primary-100 dark:border-primary-800/30">
                            {{-- Stacks on mobile so multiple bulk-action buttons wrap instead of overflowing. --}}
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div class="flex items-center gap-3">
                                    <div
                                            class="flex items-center justify-center w-8 h-8 rounded-full bg-primary-100 dark:bg-primary-800/50">
                                <span
                                        class="text-sm font-semibold text-primary-700 dark:text-primary-300 tabular-nums" x-text="selectedCount"></span>
                                    </div>
                                    <span class="text-sm font-medium text-primary-700 dark:text-primary-300">
                            {{-- Plural forms resolved client-side: representative counts cover {1} / [2,4] / [5,*] --}}
                            <span x-show="selectedCount === 1">{{ trans_choice('{1} record selected|[2,*] records selected', 1) }}</span>
                            <span x-show="selectedCount >= 2 && selectedCount <= 4">{{ trans_choice('{1} record selected|[2,*] records selected', 2) }}</span>
                            <span x-show="selectedCount >= 5">{{ trans_choice('{1} record selected|[2,*] records selected', 5) }}</span>
                        </span>
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    {{-- Bulk Actions in selection bar --}}
                                    @if($hasBulkActions)
                                        @foreach($bulkActions as $bulkAction)
                                            @if($bulkAction->canExecute())
                                                {!! $bulkAction->render() !!}
                                            @endif
                                        @endforeach
                                    @endif

                                    {{-- Deselect button --}}
                                    <button
                                            type="button"
                                            x-on:click="deselectAll()"
                                            data-testid="table-deselect" @wireEl('table-deselect')
                                            aria-label="{{ __('wire-table::messages.deselect') }}"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-primary-700 dark:text-primary-300 hover:text-primary-800 dark:hover:text-primary-200 hover:bg-primary-100 dark:hover:bg-primary-800/50 rounded-lg transition-colors"
                                    >
                                        {!! icon('outline:x-mark', 'w-4 h-4') !!}
                                        {{ __('wire-table::messages.deselect') }}
                                    </button>
                                </div>
                            </div>

                            {{-- Scope: this page, or every row the filter matches. Always
                                 present while something is selected, so the wider reach is
                                 never a surprise the user has to discover. --}}
                            {{-- Shown and counted by the controller, not by the server: a
                                 poll renders the rows' island alone, and this strip sits
                                 outside it, so a printed count or an @if would go stale. --}}
                                <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-primary-700 dark:text-primary-300"
                                     data-testid="table-selection-scope" @wireEl('table-selection-scope')
                                     x-show="matching > pageKeys.length"
                                     @if($recordCount <= count($pageRecordKeys)) style="display: none;" @endif>
                                    <template x-if="selectsAll">
                                        <span x-text="@js(__('wire-table::messages.selection_all_matching', ['count' => ':count'])).replace(':count', matching)">{{ __('wire-table::messages.selection_all_matching', ['count' => $recordCount]) }}</span>
                                    </template>
                                    <template x-if="!selectsAll">
                                        <span x-text="@js(__('wire-table::messages.selection_on_page')).replace(':count', selectedCount)"></span>
                                    </template>

                                    <button
                                            type="button"
                                            x-show="!selectsAll"
                                            x-on:click="selectAllMatching()"
                                            data-testid="table-select-all-matching" @wireEl('table-select-all-matching')
                                            class="font-semibold underline underline-offset-2 hover:no-underline"
                                            x-text="@js(__('wire-table::messages.selection_select_all_matching', ['count' => ':count'])).replace(':count', matching)"
                                    >
                                        {{ __('wire-table::messages.selection_select_all_matching', ['count' => $recordCount]) }}
                                    </button>
                                    <button
                                            type="button"
                                            x-show="selectsAll"
                                            x-cloak
                                            x-on:click="selectOnlyPage()"
                                            data-testid="table-select-only-page" @wireEl('table-select-only-page')
                                            class="font-semibold underline underline-offset-2 hover:no-underline"
                                    >
                                        {{ __('wire-table::messages.selection_only_this_page') }}
                                    </button>
                                </div>
                        </div>
                    @endif

                    {{-- The table, the stacked cards and the pagination footer.

                         An island, so a row change costs the rows instead of the
                         page. Anything that fires an action from inside these
                         markers targets it automatically — Livewire's JS walks up
                         to the nearest island fragment, no attribute needed — so a
                         sort, a page, a cell save, a sub-row expansion or a row
                         action renders THIS ALONE and leaves the toolbar, the
                         filter panels and the modals untouched and un-morphed.

                         `always` matters for the other direction: a request that
                         does not target the island — a search, a filter, a column
                         toggle, a poll tick — must still re-render it, or the rows
                         behind it go stale. With it, every untargeted path behaves
                         exactly as before.

                         The region has its own `@php` scope because an island body
                         cannot see one line of this view's locals. See the partial.
                         --}}
                    @island('data-region', always: true)
                        @include('wire-table::tables.partials.data-region')
                    @endisland
                </div>

                {{-- Action Modal.

                     An island, so opening one costs the modal instead of the whole
                     table. The saving is not in skipping this island — a closed
                     modal renders nothing anyway — it is that a call TARGETING an
                     island makes Livewire skipRender() the component and return
                     only the island, which is why the buttons carry
                     wire:island="action-modals".

                     `always` is load-bearing: without it an island is SKIPPED on
                     every request after the mount unless that request targeted it,
                     and a modal opened by anything other than a wire:island button
                     — a keyboard shortcut, the row context menu, openOn(), a test
                     calling the method — would simply never render. With it the
                     behaviour is exactly today's, and a targeted call is the only
                     thing that changes: it skips the rest of the table.

                     $component is a view variable, and an island body sees only
                     the component plus its public properties, so it is re-bound
                     from $__livewire here. --}}
                @island('action-modals', always: true)
                    @php($component = $__livewire)
                    @include('wire-table::tables.partials.action-modal')
                @endisland

                {{-- Halt Modal --}}
                @include('wire-table::tables.partials.halt-modal')

                {{-- Keyboard shortcut help (`?`) --}}
                @include('wire-table::tables.partials.shortcut-help-modal')

                {{-- One clipboard controller and one feedback pill for the page, not
                     one per cell — see record-copy.js. Inside the wrapper because the
                     pill is a real element and a Livewire component may have only one
                     root; `@once` because a page may hold several tables and the
                     controller binds to the document, not to this table. --}}
                @if($hasCopyableColumn)
                    @once
                        @include('wire-core::partials.copy-assets')
                    @endonce
                @endif

                </div> {{-- Close table wrapper --}}

                {{-- Close polling wrapper --}}
                        @if($pollingAttribute)
            </div>
    @endif
@endif {{-- End lazy loading wrapper --}}
