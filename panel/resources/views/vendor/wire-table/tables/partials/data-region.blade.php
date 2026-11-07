@php
    use NyonCode\WireCore\Foundation\View\Palette;
@endphp
{{--
    The data region: the desktop table, the stacked mobile cards, and the
    pagination footer under both.

    An ISLAND, which is why it has a scope of its own instead of inheriting the
    wrapper's. Livewire extracts an `@island` body into its own view file at
    compile time and renders it with the component plus its public properties —
    `HandlesIslands::renderIslandView()` — and nothing else. Not one of the
    wrapper's locals reaches here, so the region asks the same plan the wrapper
    asks and reads its own half.

    What that buys: an action fired from anything inside these markers targets the
    island automatically (`closestIsland()` in Livewire's JS — no attribute
    needed), so a sort, a page, a cell save, a sub-row expansion or a row action
    makes the server render THIS ALONE and skip the toolbar, the filter panels and
    the modals. The boundary is drawn where it is for that reason: everything a
    row change can alter is inside it, including the "showing 1 - 10 of 240" line
    and the page links, which move when an edit drops a row out of the filter.

    Rendered through `@island(always: true)`, so a request that does NOT target it
    still renders it — a search, a filter, a column toggle, a poll tick. Without
    `always` those would leave the rows behind stale.
--}}
@php
    use NyonCode\WireTable\Table;

    /** @var mixed $__livewire */
    $component = $__livewire;
    $table = $component->getTable();
    $records = $component->getTableRecords();
    $plan = $component->tableRenderPlan();

    // The row, assembled from compiled pieces instead of laid out here. See
    // Support\RowRenderer: the loop's own `@if`s emitted 459-999 B of morph
    // markers per row, and a row that exists only inside this loop cannot be sent
    // back on its own when a write changes it.
    $rowRenderer = \NyonCode\WireTable\Support\RowRenderer::for($table, $component, $plan);

    // The card is a SECOND full rendering of every record — the rows above are in
    // the same document, hidden by CSS at this width — so it is assembled the same
    // way, and for the same two reasons.
    $cardRenderer = \NyonCode\WireTable\Support\CardRenderer::for($table, $component, $plan);

    // The totals, for the two footers that show them — and for a write that has
    // to re-render them on their own, which is what let a summarised table use
    // row partials at all.
    $summaryRenderer = \NyonCode\WireTable\Support\SummaryRenderer::for($table, $component, $plan);

    assert($table instanceof Table);

    // Sub-rows and grouping — the regions inside the body that are their own
    // shape of row.
    $hasSubRows = $plan->shell()->hasSubRows;
    $isSubRowsExpandable = $plan->shell()->isSubRowsExpandable;
    $allRowsExpanded = $plan->shell()->allRowsExpanded;
    $hasGrouping = $plan->shell()->hasGrouping;
    // A collapsed group renders no rows at all, rather than hiding them with CSS.
    // That is the whole reason collapsing is the large-table answer here and
    // windowing was not: every gesture that reads its rows out of the DOM — the
    // keyboard grid, the fill handle's range, the live cell sync — still sees one
    // consistent list, because what is not shown is not there. Hidden-but-present
    // rows would put arrow keys on invisible rows and let a fill drag paint them.
    //
    // Said here and not in the row loop: a Blade comment between two directives
    // leaves its newlines behind, and inside the loop that is bytes on every row.
    // TablePayloadFuseTest measures exactly that, and caught this comment.
    $hasCollapsibleGroups = $table->hasCollapsibleGroups();
    $hasGroupSummaries = $plan->shell()->hasGroupSummaries;

    // What the header row's own filter inputs and sort indicators show.
    $columnFilterValues = $plan->state()->columnFilters;
    $activeColumnFilters = $plan->state()->activeColumnFilters;
    $sortColumn = $plan->state()->sortColumn;
    $sortDirection = $plan->state()->sortDirection;
    $perPage = $plan->state()->perPage;

    // Row and mobile-card actions, and the resolver that keeps wire-core's action
    // views host-agnostic.
    $actions = $plan->actions()->row;
    $hasActions = $plan->actions()->hasAny;
    $mobileActions = $plan->actions()->mobile;
    $hasMobileActions = $plan->actions()->hasMobile;
    $collapseMobileActions = $plan->actions()->collapseMobile;
    $mobileActionGroup = $plan->actions()->mobileGroup;
    $actionClick = $plan->actions()->click;
    $actionsPosition = $plan->actions()->position; // 'start' or 'end'
    $actionsAlignmentClass = $plan->actions()->alignmentClass; // literal text-* utility
    $actionsJustifyClass = $plan->actions()->justifyClass; // literal justify-* utility
    $actionsColumnLabel = $plan->actions()->columnLabel;
    $actionsColumnWidth = $plan->actions()->columnWidth;
    // Empty strings unless Table::stickyActions() pinned the column, so every
    // cell below carries the same shape whether or not anything is pinned.
    $stickyCellClass = $plan->actions()->stickyCellClass;
    $stickyHeaderCellClass = $plan->actions()->stickyHeaderCellClass;
    $stickyLayers = $plan->actions()->stickyLayers;

    // Row interaction — the pointer bindings, the two independently switchable
    // halves of the gesture layer, and the active-row marker.
    $rowContextMenuEnabled = $plan->interaction()->rowContextMenuEnabled;
    $touchMenuEnabled = $plan->interaction()->touchMenuEnabled;
    $recordActionBindings = $plan->interaction()->recordActionBindings;
    $keyboardNav = $plan->interaction()->keyboardNav;
    $tableRole = $plan->interaction()->tableRole;
    $recordKeyboardConfig = $plan->interaction()->recordKeyboardConfig;
    $recordActionsRootEnabled = $plan->interaction()->recordActionsRootEnabled;
    $gestureConfig = $plan->interaction()->gestureConfig;
    $activeRowConfig = $plan->interaction()->activeRowConfig;

    // The row itself: the compiled <tr>, the checkbox cell and the per-row class
    // binding. Compiled once per TABLE — see RowRenderPlan.
    $rowSkeleton = $plan->row()->rowSkeleton;
    $selectionCellSkeleton = $plan->row()->selectionCellSkeleton;
    $rowClassBinding = $plan->row()->rowClassBinding;
    $isSelectable = $plan->row()->isSelectable;
    $selectCheckIcon = $plan->row()->selectCheckIcon;
    $selectIndeterminateIcon = $plan->row()->selectIndeterminateIcon;
    $hasSummaries = $plan->row()->hasSummaries;
    $pageRecordKeys = $plan->row()->pageRecordKeys;

    // Columns, and everything derived from them — the visible set, the per-column
    // render metadata (including each column's compiled <td> skeleton), and the
    // lists the fill handle and the column filters read.
    $visibleColumns = $plan->columns()->visible;
    $hasVisibleColumns = $plan->columns()->hasVisible;
    $columnMeta = $plan->columns()->meta;
    $fillColumns = $plan->columns()->fillable;
    $isFillEnabled = $plan->columns()->isFillEnabled;
    $filterableColumns = $plan->columns()->filterable;
    $hasColumnFilters = $plan->columns()->hasFilters;
    $subRowColumns = $plan->columns()->subRow;
    $visibleSubRowColumns = $plan->columns()->visibleSubRow;
    $colSpan = $plan->columns()->colSpan;

    // Table styling. Row hover/striping/tint is composed in
    // Table::getRowClasses($record, $rowIndex), not here.
    $isBordered = $plan->layout()->isBordered;
    $cellPadding = $plan->layout()->cellPadding;
    $headerPadding = $plan->layout()->headerPadding;
    $stickyHeaderClass = $plan->layout()->stickyHeaderClass;
    $scrollRegionStyle = $plan->layout()->scrollRegionStyle;
    $stickyHeaderFollowsPage = $plan->layout()->stickyHeaderFollowsPage;
    $stickyLayerClass = $plan->layout()->stickyLayerClass;
    $isStackedOnMobile = $plan->layout()->isStackedOnMobile;
    $tableHiddenClass = $plan->layout()->tableHiddenClass;
    $cardsVisibleClass = $plan->layout()->cardsVisibleClass;
    // Which halves are EMITTED, as opposed to which is visible. Under the list
    // layout the <table> is not in the document at all — one rendering per
    // record instead of two chosen by CSS.
    $rendersTable = $plan->layout()->rendersTable;
    $rendersCards = $plan->layout()->rendersCards;
    // With a `wire_viewport` cookie only one of the two halves above is emitted;
    // the marker below tells `wire-table-viewport.js` which, so crossing the
    // breakpoint can ask for the other one.
    $tracksClientLayout = $plan->layout()->tracksClientLayout;

    // Where this page sits in the whole result set — read by the footer's
    // "from - to of total" line and, before it, by aria-rowindex, since an ARIA
    // row index counts through the entire grid rather than the page.
    $isPaginated = $plan->paging()->isPaginated;
    $hasPaginator = $plan->paging()->hasPaginator;
    $recordCount = $plan->paging()->recordCount;
    $isEmptyDueToFilter = $plan->paging()->isEmptyDueToFilter;
    $rangeFrom = $plan->paging()->rangeFrom;
    $rangeTo = $plan->paging()->rangeTo;
    $headerRowCount = $plan->paging()->headerRowCount;
@endphp
                    @if($isSelectable)
                        {{-- The page's keys and the matching count, for the selection
                             controller on the wrapper. The wrapper carries them too,
                             but a poll renders this island alone and leaves the
                             wrapper as it was, so the controller reads this copy. --}}
                        <span hidden data-selection-sync data-page-keys="{{ json_encode($pageRecordKeys) }}" data-matching="{{ $recordCount }}"></span>
                    @endif
                    @if($tracksClientLayout)
                        <span hidden data-wire-layout="{{ $plan->layout()->clientLayout ?? 'both' }}" data-wire-layout-query="{{ $plan->layout()->stackedMediaQuery }}"></span>
                    @endif
                    {{-- Delivered here rather than beside the handle's own markup,
                         because THIS is the element that names `wireFillHandle` —
                         and it does so whether or not any column is visible, while
                         the handle partial below renders only when one is. The two
                         used to be allowed to disagree: the controller arrived
                         inside `wire-core-dropdown.js`, which every page ships. It
                         does not any more (ADR 0025 § step 10), so delivery has to
                         track the reference exactly, or Alpine dies on the whole
                         data region with `wireFillHandle is not defined`. --}}
                    @if($isFillEnabled)
                        @include('wire-table::tables.partials.fill-assets')
                    @endif
                    {{-- The scroll region.

                         A region that clips is clipped silently: `overflow` draws nothing
                         at the edge it cuts, so a table three screens wide looks, at
                         rest, exactly like one that fits — a whole actions column
                         off-screen, or the last visible row sliced through the middle
                         with nothing to say more follow.

                         What says so is the scrollbar, made visible by
                         `wire-core::partials.scroller-assets`. It used to be three gradients laid over the
                         edges, kept in sync with the scroller's own offsets by the
                         Alpine component that stood here: an `x-data` on a frame
                         wrapping this element (the scroller already names
                         `wireFillHandle` on an editable table, and one element takes one
                         `x-data`), a `scroll` listener, and a ResizeObserver on both
                         boxes because a column toggled off changes the answer without
                         scrolling anything. They marked an edge without saying how far
                         it went or letting anyone go there, and they dimmed what they
                         covered — the pinned header row, the rules between rows, the
                         first characters of the first column. A scrollbar says both,
                         answers a drag, and is a property of the box rather than state
                         that can be wrong after a morph. The frame, the three overlays
                         and all of the wiring went with it. --}}
                    @if($rendersTable)
                        @include('wire-core::partials.scroller-assets')
                        @if($stickyHeaderFollowsPage)
                            @include('wire-table::tables.partials.sticky-header-assets')
                        @endif
                    <div class="relative overflow-x-auto wire-scroller {{ $tableHiddenClass }} {{ $stickyLayerClass }}"
                         @if($scrollRegionStyle) style="{{ $scrollRegionStyle }}" @endif
                         @if($isFillEnabled)
                             {{-- Deliberately NOT island-targeted, unlike the editable
                                  cells inside it: the fill suppresses morphs while a drag
                                  is in flight through Livewire's `morph.updating` hook, and
                                  an island fragment morph does not go through it. Targeted,
                                  a fill's own response wipes the cells it just painted —
                                  verify-spa-navigate.mjs is what says so. It needs no render
                                  anyway; it reconciles each cell from the response payload. --}}
                             x-data="wireFillHandle()"
                             data-fill-root
                             data-fill-columns="{{ json_encode($fillColumns) }}"
                             data-fill-max="{{ $table->getFillMaxRecords() }}"
                         @endif
                    >
                        @if($hasVisibleColumns)
                            <table
                                    @if($tableRole)
                                        role="{{ $tableRole }}"
                                        {{-- Counts the whole result set plus the header rows, not
                                             the page: a grid tells assistive tech how much there is
                                             to page through, and the row indices below match it. --}}
                                        aria-rowcount="{{ $recordCount + $headerRowCount }}"
                                        @if($isSelectable) aria-multiselectable="true" @endif
                                    @endif
                                    class="w-full {{ $isBordered ? 'border-collapse' : '' }} {{ $table->getTableClass() }}">
                                <thead
                                        @if($stickyHeaderFollowsPage) data-wire-sticky-head @endif
                                        class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider {{ $stickyHeaderClass ?: 'bg-gray-50 dark:bg-gray-800/50' }} {{ $table->getHeaderClass() }}">
                                {{-- `bg-inherit`: the header's colour sits on the <thead>, and a
                                     pinned <th> gets its opaque backdrop by inheriting down the
                                     chain (Support\StickyColumn) — which stops at a <tr> that
                                     declares nothing. Painted identically to before. --}}
                                <tr class="bg-inherit" @if($tableRole) aria-rowindex="1" @endif>
                                    {{-- Select All Checkbox --}}
                                    @if($isSelectable)
                                        <th scope="col" class="w-12 {{ $headerPadding }}">
                                            <div class="flex items-center justify-center">
                                                <button
                                                        type="button"
                                                        x-on:click="toggleAll()"
                                                        role="checkbox"
                                                        :aria-checked="allSelected ? 'true' : (someSelected ? 'mixed' : 'false')"
                                                        aria-label="{{ __('wire-table::messages.select_all') }}"
                                                        data-testid="table-select-all" @wireEl('table-select-all')
                                                        class="relative h-4 w-4 rounded-sm border focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition-colors"
                                                        :class="(allSelected || someSelected) ? 'bg-primary-600 border-primary-600' : 'bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600'"
                                                >
                                                    {{-- The same tick as the rows below, from the same
                                                         resolved string, drawing itself in the same way —
                                                         see tables.partials.selection-cell for why the
                                                         transition sits on the span and why its visible
                                                         end is the value an absent utility computes to. --}}
                                                    <span x-show="allSelected" x-cloak
                                                          x-transition:enter="transition-[stroke-dashoffset] duration-200 ease-out"
                                                          x-transition:enter-start="[stroke-dashoffset:12]"
                                                          x-transition:enter-end="[stroke-dashoffset:0]">
                                                        {!! $selectCheckIcon !!}
                                                    </span>
                                                    <span x-show="someSelected" x-cloak>
                                                        {!! $selectIndeterminateIcon !!}
                                                    </span>
                                                </button>
                                            </div>
                                        </th>
                                    @endif

                                    {{-- Sub-row Toggle Header: master expand/collapse, directly
                                         above the row chevrons it drives. --}}
                                    @if($hasSubRows)
                                        <th scope="col" class="w-10 {{ $headerPadding }}">
                                            @if($isSubRowsExpandable)
                                                @include('wire-table::tables.partials.sub-rows-master-toggle', [
                                                    'allRowsExpanded' => $allRowsExpanded,
                                                    'label' => $table->getSubRowsToggleLabel(),
                                                ])
                                            @else
                                                {{ $table->getSubRowsToggleLabel() ?? '' }}
                                            @endif
                                        </th>
                                    @endif

                                    {{-- Actions Header (Start Position) --}}
                                    @if($hasActions && $actionsPosition === 'start')
                                        <th
                                                scope="col"
                                                class="{{ $headerPadding }} font-semibold {{ $actionsAlignmentClass }} {{ $stickyHeaderCellClass }}"
                                                @if($actionsColumnWidth) style="width: {{ $actionsColumnWidth }}" @endif
                                        >{!! $stickyLayers !!}<span class="relative">{{ $actionsColumnLabel }}</span></th>
                                    @endif

                                    {{-- Column Headers --}}
                                    @foreach($visibleColumns as $column)
                                        @php $hm = $columnMeta[$column->getName()]; @endphp
                                        <th
                                                scope="col"
                                                data-column="{{ $column->getName() }}"
                                                class="{{ $headerPadding }} {{ $hm['alignment'] }} font-semibold {{ $isBordered ? 'border border-gray-200 dark:border-gray-700' : '' }} {{ $hm['responsive'] }}"
                                                @if($hm['widthStyles']) style="{{ $hm['widthStyles'] }}" @endif
                                                @if($hm['extraHeader']) {!! $hm['extraHeader'] !!} @endif
                                        >
                                            @if($column->isSortable() && $table->isSortable())
                                                <button
                                                        type="button"
                                                        wire:click="sortTable('{{ $column->getName() }}')"
                                                        data-testid="table-sort-{{ $column->getName() }}"
                                                        {{-- `uppercase` is restated here, not inherited. The `<thead>`
                                                             above carries it, but a `<button>` is a form control: the UA
                                                             stylesheet sets `text-transform: none` on it and Tailwind's
                                                             preflight resets font family, size and weight without
                                                             touching that one. So a sortable header quietly rendered
                                                             "Name" beside a non-sortable "ROLE" in the same row. --}}
                                                        class="group inline-flex items-center gap-1 uppercase hover:text-gray-700 dark:hover:text-gray-200"
                                                >
                                                    <span>{{ $column->getLabel() }}</span>
                                                    <span class="flex-none">
                                                @if($sortColumn === $column->getName())
                                                            @if($sortDirection === 'asc')
                                                                {!! icon('outline:chevron-up', 'h-4 w-4', 'text-gray-500 dark:text-gray-400') !!}
                                                            @else
                                                                {!! icon('outline:chevron-down', 'h-4 w-4', 'text-gray-500 dark:text-gray-400') !!}
                                                            @endif
                                                        @else
                                                            {!! icon('outline:chevron-up-down', 'h-4 w-4', 'text-gray-500 dark:text-gray-400 opacity-0 group-hover:opacity-100') !!}
                                                        @endif
                                            </span>
                                                </button>
                                            @else
                                                {{ $column->getLabel() }}
                                            @endif
                                        </th>
                                    @endforeach

                                    {{-- Actions Header (End Position - Default) --}}
                                    @if($hasActions && $actionsPosition === 'end')
                                        <th
                                                scope="col"
                                                class="{{ $headerPadding }} font-semibold {{ $actionsAlignmentClass }} {{ $stickyHeaderCellClass }}"
                                                @if($actionsColumnWidth) style="width: {{ $actionsColumnWidth }}" @endif
                                        >{!! $stickyLayers !!}<span class="relative">{{ $actionsColumnLabel }}</span></th>
                                    @endif
                                </tr>

                                {{-- Row Filters --}}
                                @if($hasColumnFilters)
                                    <tr @if($tableRole) aria-rowindex="2" @endif
                                        class="bg-gray-50/50 dark:bg-gray-800/30 border-t border-gray-100 dark:border-gray-700/50">
                                        @if($isSelectable)
                                            <th class="{{ $headerPadding }}"></th>
                                        @endif

                                        {{-- Sub-row Toggle Filter Cell --}}
                                        @if($hasSubRows)
                                            <th class="{{ $headerPadding }}"></th>
                                        @endif

                                        {{-- Actions Filter Cell (Start Position) --}}
                                        @if($hasActions && $actionsPosition === 'start')
                                            <th class="{{ $headerPadding }} {{ $stickyHeaderCellClass }}">{!! $stickyLayers !!}</th>
                                        @endif

                                        @foreach($visibleColumns as $column)
                                            <th class="{{ $headerPadding }}" @if($column->isFilterable()) data-testid="table-filter-{{ $column->getName() }}" @endif>
                                                @if($column->isFilterable())
                                                    {!! $column->renderFilter($columnFilterValues[$column->getName()] ?? null) !!}
                                                @endif
                                            </th>
                                        @endforeach

                                        {{-- Actions Filter Cell (End Position) --}}
                                        @if($hasActions && $actionsPosition === 'end')
                                            <th class="{{ $headerPadding }} text-right {{ $stickyHeaderCellClass }}">{!! $stickyLayers !!}
                                                @if($activeColumnFilters !== [])
                                                    <button
                                                            type="button"
                                                            wire:click="resetColumnFilters"
                                                            class="relative inline-flex items-center justify-center p-1 text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 rounded-sm hover:bg-gray-100 dark:hover:bg-gray-700"
                                                            title="{{ __('wire-table::messages.filter_reset_column') }}"
                                                    >
                                                        {!! icon('outline:x-mark', 'w-4 h-4') !!}
                                                    </button>
                                                @endif
                                            </th>
                                        @endif
                                    </tr>
                                @endif
                                </thead>

                                <tbody
                                        {{-- With a touch menu, a long press is the row's right click: iOS must
                                             not answer it with its text callout, and a double tap must not zoom. --}}
                                        class="divide-y divide-gray-100 dark:divide-gray-700{{ $stickyLayerClass ? ' '.$stickyLayerClass : '' }}{{ $touchMenuEnabled ? ' [&>tr]:touch-manipulation [@media(pointer:coarse)]:[&>tr]:select-none [@media(pointer:coarse)]:[&>tr]:[-webkit-touch-callout:none]' : '' }}"
                                        @if($recordActionsRootEnabled)
                                            x-data="wireRecordActions({ bindings: @js($recordActionBindings), contextMenu: {{ $rowContextMenuEnabled ? 'true' : 'false' }}, touch: {{ $touchMenuEnabled ? 'true' : 'false' }}, keyboard: @js($recordKeyboardConfig), active: @js($activeRowConfig), gestures: @js($gestureConfig) })"
                                            {{-- Bound whenever the controller is mounted, not only for pointer
                                                 bindings: a click also moves the active row, which is what makes a
                                                 clicked row visibly the one the arrow keys continue from. --}}
                                            @click="onPointer('click', $event)"
                                            @dblclick="onPointer('dblclick', $event)"
                                            @if($rowContextMenuEnabled || $touchMenuEnabled)
                                                @contextmenu="onContextMenu($event)"
                                            @endif
                                            @if($keyboardNav)
                                                @keydown="onKeydown($event)"
                                                @focusin="onRowFocus($event)"
                                            @endif
                                        @endif
                                >
                                @if($recordActionsRootEnabled)
                                    @once
                                        @include('wire-table::tables.partials.record-actions-assets')
                                    @endonce
                                @endif
                                @if($rowContextMenuEnabled || $touchMenuEnabled)
                                    {{-- Core dropdown bundle for any nested action-group dropdown inside a
                                         context-menu item; emitted once per request, not once per row. --}}
                                    @once
                                        @include('wire-core::partials.floating-assets')
                                    @endonce
                                @endif
                                @forelse($records as $record)
                                    @php
                                        $recordKey = $record->{$table->getPrimaryKey()};
                                        $rowIndex = $loop->index;

                                        $groupValue = $hasGrouping ? $table->getGroupComparisonKey($record) : null;
                                        $prevRecord = $hasGrouping && $rowIndex > 0 ? $records[$rowIndex - 1] : null;
                                        $nextRecord = $hasGrouping ? ($records[$rowIndex + 1] ?? null) : null;
                                        $isGroupStart = $hasGrouping && ($prevRecord === null || $table->getGroupComparisonKey($prevRecord) !== $groupValue);
                                        $isGroupEnd = $hasGrouping && ($nextRecord === null || $table->getGroupComparisonKey($nextRecord) !== $groupValue);

                                        // Per-record sub-rows (subRowsVisible): decides this row's
                                        // chevron and panel only — the expander cell itself still
                                        // renders, empty, or the columns stop lining up.
                                        $recordHasSubRows = $hasSubRows && $table->hasSubRowsFor($record);
                                        $isGroupCollapsed = $hasCollapsibleGroups
                                            && $component->isGroupCollapsed((string) $groupValue);
                                    @endphp

                                    {{-- Group header. Compiled once per shape for the table;
                                         this group supplies its label and its own key. --}}
                                    @if($isGroupStart){!! $table->getGroupHeaderRow($record, $colSpan, $isGroupCollapsed) !!}@endif
                                    @unless($isGroupCollapsed){!! $rowRenderer->render($record, $rowIndex) !!}@endunless

                                    {{-- Sub-rows --}}
                                    @if(! $isGroupCollapsed && $recordHasSubRows && $component->isRowExpanded($recordKey))
                                        @php
                                            $subRows = $component->getSubRows($record);
                                        @endphp
                                        @include('wire-table::tables.partials.sub-rows', [
                                            'table' => $table,
                                            'component' => $component,
                                            'record' => $record,
                                            'recordKey' => $recordKey,
                                            'subRows' => $subRows,
                                            'visibleSubRowColumns' => $visibleSubRowColumns,
                                            'colSpan' => $colSpan,
                                            'cellPadding' => $cellPadding,
                                            'isBordered' => $isBordered,
                                        ])
                                    @endif

                                    {{-- Group subtotal --}}
                                    @if($isGroupEnd && $hasGroupSummaries)
                                        @include('wire-table::tables.partials.group-subtotal', [
                                            'table' => $table,
                                            'component' => $component,
                                            'groupSummaries' => $component->computeGroupSummaries($groupValue),
                                            'partialAnchors' => $summaryRenderer->groupAnchors($groupValue),
                                            'visibleColumns' => $visibleColumns,
                                            'colSpan' => $colSpan,
                                            'cellPadding' => $cellPadding,
                                            'isBordered' => $isBordered,
                                            'isSelectable' => $isSelectable,
                                            'hasActions' => $hasActions,
                                            'actionsPosition' => $actionsPosition,
                                        ])
                                    @endif
                                @empty
                                    <tr>
                                        <td colspan="{{ $colSpan }}" class="px-6 py-16 text-center">
                                            {{-- Canonical empty-state surface; filter-empty adds a reset action. --}}
                                            @include('wire-core::partials.empty-state', [
                                                'icon' => $isEmptyDueToFilter
                                                    ? 'outline:magnifying-glass'
                                                    : ($table->getEmptyStateIcon() ?? 'outline:inbox'),
                                                'heading' => $isEmptyDueToFilter
                                                    ? __('wire-table::messages.empty_filter_heading')
                                                    : $table->getEmptyStateHeading(),
                                                'description' => $isEmptyDueToFilter
                                                    ? __('wire-table::messages.empty_no_records_match')
                                                    : $table->getEmptyStateDescription(),
                                                'actions' => $isEmptyDueToFilter
                                                    ? [view('wire-table::tables.partials.reset-filters-button')->render()]
                                                    : $table->getEmptyStateActionsHtml(),
                                            ])
                                        </td>
                                    </tr>
                                @endforelse
                                </tbody>

                                {{-- Summary footer --}}
                                @if($hasSummaries)
                                    {!! $summaryRenderer->desktop() !!}
                                @endif
                            </table>

                            @if($isFillEnabled)
                                @include('wire-table::tables.partials.fill-handle', [
                                    'fillColumns' => $fillColumns,
                                    'fillMax' => $table->getFillMaxRecords(),
                                ])
                            @endif
                        @else
                            {{-- No columns visible state --}}
                            <div class="px-6 py-16 text-center">
                                <div class="flex flex-col items-center gap-3">
                                    <div class="rounded-full {{ Palette::getModalIconBgClass('warning') }} p-3">
                                        {!! icon('outline:eye-slash', 'h-8 w-8', Palette::getModalIconTextClass('warning')) !!}
                                    </div>
                                    <div>
                                        <h3 class="text-base font-medium text-gray-900 dark:text-white">
                                            {{ __('wire-table::messages.empty_no_columns') }}
                                        </h3>
                                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                            {{ __('wire-table::messages.empty_no_columns_hint') }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>{{-- /scroll region --}}
                    @endif

                    {{-- The card rendering: the second half under stackedOnMobile(),
                         and the only half under layout('list'). --}}
                    @if($rendersCards && $hasVisibleColumns)
                        <div class="{{ $cardsVisibleClass }}">
                            {{-- The card view's select-all. It has to live here because the
                                 header row that carries it on desktop is hidden at this
                                 width — without it, selecting a page on a phone means
                                 tapping every card. Always rendered, never behind a
                                 gesture: what is not visible is not found. --}}
                            @if($isSelectable)
                                <div class="flex items-center gap-3 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40 px-4 py-2.5">
                                    <button
                                            type="button"
                                            x-on:click="toggleAll()"
                                            role="checkbox"
                                            :aria-checked="allSelected ? 'true' : (someSelected ? 'mixed' : 'false')"
                                            aria-label="{{ __('wire-table::messages.select_all_on_page') }}"
                                            data-testid="table-card-select-all" @wireEl('table-card-select-all')
                                            class="relative h-5 w-5 shrink-0 rounded-sm border transition-colors focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800"
                                            :class="(allSelected || someSelected) ? 'bg-primary-600 border-primary-600' : 'bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600'"
                                    >
                                        <span x-show="allSelected" x-cloak
                                              x-transition:enter="transition-[stroke-dashoffset] duration-200 ease-out"
                                              x-transition:enter-start="[stroke-dashoffset:12]"
                                              x-transition:enter-end="[stroke-dashoffset:0]">{!! $selectCheckIcon !!}</span>
                                        <span x-show="someSelected" x-cloak>
                                            {!! $selectIndeterminateIcon !!}
                                        </span>
                                    </button>
                                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        {{ __('wire-table::messages.select_all') }}
                                    </span>
                                    <span class="ml-auto text-xs tabular-nums text-gray-500 dark:text-gray-400"
                                          data-testid="table-card-select-count" @wireEl('table-card-select-count')>
                                        <span x-show="selectedCount === 0">{{ __('wire-table::messages.selection_page_of_total', ['page' => count($pageRecordKeys), 'total' => $recordCount]) }}</span>
                                        <span x-show="selectedCount > 0" x-cloak
                                              x-text="@js(__('wire-table::messages.selection_selected_of_total', ['count' => ':count', 'total' => $recordCount])).replace(':count', selectedCount)"></span>
                                    </span>
                                </div>
                            @endif

                            @php
                                // A card is a record, not the column order in disguise: the
                                // slots below carry the hierarchy (what it is, whose it is,
                                // how much), and MobileCard derives them when nothing says.
                                $card = $table->getMobileCard(array_values($visibleColumns));
                                $cardTitle = $card->title();
                                $cardSubtitle = $card->subtitle();
                                $cardMetric = $card->metric();
                                $cardMeta = $card->meta();
                                $cardDetails = $card->details();
                                $mobileCell = fn($column, $record) => $column->hasResponsiveDisplay()
                                    ? $column->renderMobileCell($record)
                                    : $column->renderCellFast($record);
                                // One group with a null heading when the table asked for none,
                                // so the loop below has one shape rather than two. The branch it
                                // costs is per GROUP, never per card: an `@if` inside the card
                                // loop is a pair of morph markers on every row and the payload
                                // fuse budgets those.
                                $cardGroups = $table->groupCards($records);
                            @endphp
                            {{-- Record actions are a desktop pointer affordance: the delegated
                                 controller lives on the desktop <tbody> only, so click/dblclick/
                                 right-click record actions do not apply to these touch cards. The
                                 same actions reach a finger as ordinary buttons instead — see
                                 $mobileActions, which folds the behaviour-only bindings in. --}}
                            {{-- Mind the whitespace below: the tags touch on purpose.

                                 This is a SECOND full rendering of every record — the desktop
                                 rows are in the same document, hidden by CSS at this width — so
                                 the card's layout is emitted per row and every run of whitespace
                                 between two tags is one more DOM text node the morph walks on
                                 every commit. Measured before this was closed up: 4391 B and 36
                                 whitespace nodes per row, 225% on top of the row itself.

                                 Whitespace BETWEEN ATTRIBUTES is free (no node), so the
                                 attributes stay laid out, and every conditional stays exactly
                                 where it was — the morph markers an @if emits are load-bearing
                                 (see §8f in architecture/plans/render-engine-htmlable-first.md).
                                 Mind also that a directive must never be glued straight onto a
                                 Blade comment: Livewire then fails to inject its opening marker.
                            --}}
                            @forelse($cardGroups as $cardGroup)
                                @if($cardGroup['heading'] !== null)
                                    {{-- A run of cards under the day it landed on: a grid says
                                         *when* in a column, and a list has no column. Sticky, so
                                         the heading stays legible while its own run scrolls past
                                         — which is the whole value of having one. --}}
                                    <p class="sticky top-0 z-10 bg-gray-50/95 px-4 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-500 backdrop-blur sm:px-6 dark:bg-gray-900/95 dark:text-gray-400">{{ $cardGroup['heading'] }}</p>
                                @endif
                                @foreach($cardGroup['records'] as $record)
                                {!! $cardRenderer->render($record) !!}
                                @endforeach
                            @empty
                                <div class="px-4 py-12 text-center bg-white dark:bg-gray-800">
                                    {{-- The same canonical surface the desktop table's empty state
                                         uses, so a custom icon/description, the filter-empty reset
                                         and the empty-state actions reach a phone too. The action
                                         copies drop their keyboard shortcut: both layouts are in
                                         the document at every width, and a rendered button binds
                                         its shortcut as a window listener. --}}
                                    @include('wire-core::partials.empty-state', [
                                        'icon' => $isEmptyDueToFilter
                                            ? 'outline:magnifying-glass'
                                            : ($table->getEmptyStateIcon() ?? 'outline:inbox'),
                                        'iconSize' => 'h-6 w-6',
                                        'heading' => $isEmptyDueToFilter
                                            ? __('wire-table::messages.empty_filter_heading')
                                            : $table->getEmptyStateHeading(),
                                        'description' => $isEmptyDueToFilter
                                            ? __('wire-table::messages.empty_no_records_match')
                                            : $table->getEmptyStateDescription(),
                                        'actions' => $isEmptyDueToFilter
                                            ? [view('wire-table::tables.partials.reset-filters-button')->render()]
                                            : $table->getMobileEmptyStateActionsHtml(),
                                    ])
                                </div>
                            @endforelse

                            {{-- Totals for the card view. The desktop <tfoot> lives inside
                                 the table this layout hides, so without this a phone shows
                                 no totals at all. --}}
                            @if($hasSummaries)
                                {!! $summaryRenderer->mobile() !!}
                            @endif
                        </div>
                    @endif

                    {{-- Footer / Pagination --}}
                    @if($isPaginated && $hasVisibleColumns)
                        @php
                            // The range comes from the preamble — aria-rowindex needs it before
                            // the body renders. Which of the three questions may be asked of the
                            // records is PagingRenderPlan's business, not this view's.
                            $hasMultiplePages = $plan->paging()->hasLinks;
                            $knowsTotal = $hasPaginator;
                            $knowsRange = $plan->paging()->knowsRange;
                            $total = $recordCount;
                            $from = $rangeFrom;
                            $to = $rangeTo;
                        @endphp

                        <div
                                class="px-4 lg:px-6 py-4 border-t border-gray-100 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                {{-- Per Page Selector. Present when paginated unless the table
                                     turned it off: on a list it is the last control that makes
                                     the page announce itself as a table. The empty <div> stays
                                     either way so `justify-between` keeps the count on the
                                     right instead of pulling it to the left edge. --}}
                                <div class="flex items-center gap-2">
                                @if($table->showsPerPageSelector())
                                    <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('wire-table::messages.show') }}</span>
                                    <select
                                            wire:model.live="tableState.pagination.perPage"
                                            data-testid="table-per-page" @wireEl('table-per-page')
                                            aria-label="{{ __('wire-table::messages.show') }}"
                                            class="rounded-lg border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 text-base sm:text-sm text-gray-700 dark:text-gray-300 focus:border-primary-500 focus:ring-primary-500 py-1.5"
                                    >
                                        @foreach($table->getPerPageOptions() as $option)
                                            {{-- Mark the live value server-side: without it the first
                                                 paint shows the first option regardless of state, and a
                                                 morph can snap the control back to it. --}}
                                            <option value="{{ $option }}" @selected((int) $perPage === $option)>{{ $option }}</option>
                                        @endforeach
                                    </select>
                                    <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('wire-table::messages.records') }}</span>
                                @endif
                                </div>

                                {{-- Results info. A simple paginator knows its offsets but not
                                     the total, so it says "showing 1 - 10" and stops; a cursor
                                     paginator knows neither and says nothing rather than a
                                     confident wrong number. --}}
                                @if($knowsRange)
                                    <div class="text-sm text-gray-500 dark:text-gray-400">
                                        {{ __('wire-table::messages.showing') }} <span
                                                class="font-medium text-gray-700 dark:text-gray-300">{{ $from }}</span> -
                                        <span class="font-medium text-gray-700 dark:text-gray-300">{{ $to }}</span>@if($knowsTotal) {{ __('wire-table::messages.of') }} <span
                                                class="font-medium text-gray-700 dark:text-gray-300">{{ $total }}</span>@endif
                                        {{ __('wire-table::messages.records') }}
                                    </div>
                                @endif

                                {{-- Pagination Links - Only when multiple pages --}}
                                @if($hasMultiplePages)
                                    <div>
                                        {{ $records->links($plan->paging()->linksView) }}
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif
