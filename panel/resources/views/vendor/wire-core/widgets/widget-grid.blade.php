@php
    use NyonCode\WireCore\Foundation\Support\ResponsiveGrid;

    $columns = $columns ?? 2;

    // How many columns this grid has *at each width*, asked once and used
    // twice — for the grid and for every tile in it. They have to be the same
    // numbers: a tile spanning more columns than the grid has does not clip, it
    // makes CSS Grid invent the missing track and squeezes every other tile into
    // what is left. See ResponsiveGrid::span().
    $ladder = ResponsiveGrid::cardColumns($columns);
    // A polling, deferred or filtered widget answers with a `wire:partial`
    // region, which is inert without the applier — and a widget-only dashboard
    // has no other surface that would pull the bundle in.
    $anyAnchored = collect($widgets)->contains(fn ($widget) => $widget->usesPartialAnchor());

    // A row baseline only when something actually spans rows. `row-span-2` needs
    // rows of a known height to mean anything, but fixing that height for every
    // dashboard would change how every existing one looks — a card would stop
    // being as tall as its contents. Asked of the widgets, so a grid nobody has
    // resized emits exactly the markup it always did.
    $anySpansRows = collect($widgets)->contains(fn ($widget) => $widget->spansRows());

    // Edit mode is the host's, and a host that does not have it is every
    // dashboard that existed before this: a plain `WithWidgets` component with
    // no layout key answers false and this template emits nothing extra.
    $editing = ($editing ?? null) ?? false;

    // Handed in, never reached for: this view is also rendered by
    // `<x-wire::widget-grid>`, where there is no Livewire host and `$this` is
    // not a component at all. A host passes the lot through
    // `WithWidgets::widgetGridData()`; the component path passes neither and
    // gets no tray, which is right — there is nothing for a tile to be dropped
    // into.
    $trayGroup = $trayGroup ?? 'wire-widgets';
    $available = $available ?? [];

    // Whether the dashboard's filters are narrowing it right now — what puts
    // the "not filtered" mark on a widget that declared it ignores them. Absent
    // on the component path, where there are no dashboard filters at all.
    $filtersNarrowed = isset($filterState) && $filterState->isNarrowed();
@endphp

@if($anyAnchored)
    @include('wire-core::partials.partial-assets')
@endif

{{-- `widget-grid` is the dashboard as a whole — what a theme scopes to and what
     a guided tour points at when it introduces the cards rather than one of
     them. A hook name, so it is kept once it has shipped. --}}
<div class="wire-widget-grid" @wireEl('widget-grid')>
    @if($editing)
        @include('wire-core::widgets.partials.widget-tray', [
            'available' => $available,
            'trayGroup' => $trayGroup,
            'columns' => $columns,
            'atWidgetLimit' => $atWidgetLimit ?? false,
            'maxWidgets' => $maxWidgets ?? null,
        ])
    @endif

    {{-- Responsive: 1 col on mobile, growing toward the configured count.

         `x-sort` is Livewire's own Alpine plugin, which carries SortableJS and a
         ghost with it — so dragging a dashboard costs no JavaScript of ours.
         `wireSortableList` is deliberately not used here: what that controller
         adds over the plugin is reverting the drop, locking a dragged `<tr>`'s
         cell widths and guarding a morph mid-drag, and none of the three applies
         to a keyed grid of cards that the server re-renders wholesale. --}}
    {{-- `placeWidget`, not `moveWidget`: a drop here can be a tile moving inside
         the grid or one arriving from the tray, and the browser cannot tell
         them apart. Neither should it have to — where the tile came from is the
         layout's business. --}}
    <div @if($editing) x-sort="$wire.placeWidget($item, $position)" x-sort:group="{{ $trayGroup }}" @endif
         @class([
        'grid gap-6',
        ResponsiveGrid::cols($ladder),
        // Tall tiles need a row to be a fixed size; a card taller than two of
        // them scrolls inside itself rather than stretching the row, or the
        // span would mean nothing. Only ever emitted on a grid that has one.
        //
        // The cell is a column and only its LAST child — the widget, always
        // drawn after any chrome — fills and scrolls. Stretching every child
        // made the edit strip a row tall and gave the unfiltered mark the
        // whole height of the cell.
        //
        // No quote characters in comments inside this directive: Livewire reads
        // its arguments from the raw template, comments included, and an odd
        // quote makes it lose the loop below. See WidgetGridChromeTest.
        'auto-rows-[minmax(11rem,auto)] md:auto-rows-[11rem] [&>*]:min-h-0 [&>*]:flex [&>*]:flex-col [&>*>:last-child]:min-h-0 [&>*>:last-child]:flex-1 [&>*>:last-child]:overflow-auto' => $anySpansRows,
    ])>
        @foreach($widgets as $widget)
            @if($widget->isVisible())
                @php($unfiltered = $filtersNarrowed && $widget->isIgnoringDashboardFilters())
                {{-- `wire:key` is what lets the server be the one that places a
                     tile. SortableJS leaves the DOM in the dropped order and the
                     re-render arrives over it; paired by key, the two cannot
                     disagree, so nothing has to be dragged back first. --}}
                <div wire:key="widget-cell-{{ $widget->getKey() }}"
                     {{-- Told which grid it is in, then asked — the same two
                          steps every other grid in the stack takes. A card grid
                          ramps later than a field grid, and a span it cannot
                          honour is the one thing that must never reach the page:
                          CSS Grid answers it by adding the column. --}}
                     {{-- While a tile is dragged SortableJS keeps a `sortable-ghost`
                          copy where it would land. Styled as a drop target — a
                          dashed outline over a faded tile — so the reader sees
                          where the widget goes before letting go, with no
                          JavaScript of ours. --}}
                     class="{{ $widget->inGridOf($ladder)->getColumnSpanClass() }} {{ $widget->getRowSpanClass() }} {{ $editing || $unfiltered ? 'relative' : '' }} {{ $editing ? 'rounded-lg [&.sortable-ghost]:opacity-60 [&.sortable-ghost]:outline-dashed [&.sortable-ghost]:outline-2 [&.sortable-ghost]:outline-offset-2 [&.sortable-ghost]:outline-primary-500' : '' }}"
                     {{-- `@js`, not the bare key: the plugin *evaluates* what
                          `x-sort:item` holds (`el._x_sort_key = evaluate(expression)`),
                          so a bare `revenue` is an identifier and throws
                          `ReferenceError: revenue is not defined` — which kills
                          the whole Alpine tree and leaves a grid with handles
                          that do nothing. Markup a PHP test reads as correct;
                          only the browser sees it. --}}
                     @if($editing) x-sort:item="@js($widget->getKey())" @endif
                     @if($widget->isPolling()) {!! $widget->getPollingDirective() !!} @endif
                     @if($widget->isLazy()) wire:init="loadWidget('{{ $widget->getKey() }}')" @endif>
                    @if($editing)
                        @include('wire-core::widgets.partials.widget-edit-controls', ['widget' => $widget, 'columns' => $columns])
                    @endif
                    {{-- Said, not hidden: the widget still shows everything,
                         and a reader comparing it with its filtered neighbours
                         needs to know it is not one of them.

                         Laid over the cell's bottom edge, half in the gap below
                         it, and taking no room: in the flow it pushed its card a
                         line below its neighbours whenever a filter narrowed,
                         which is exactly when a reader compares them. The
                         bottom rather than the top, because the top of a card is
                         its heading and its header actions, and the edit strip
                         sits above it. --}}
                    @if($unfiltered)
                        <p class="pointer-events-none absolute bottom-0 right-3 z-10 translate-y-1/2 rounded-full border border-gray-200 bg-white px-2 py-px text-[11px] font-medium leading-4 text-gray-500 shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400"
                           data-testid="widget-unfiltered-{{ $widget->getKey() }}" @wireEl('widget-unfiltered')>
                            {{ __('wire-core::messages.widget_ignores_filters') }}
                        </p>
                    @endif
                    {{-- Only a widget something replaces on its own needs an
                         anchor, so a dashboard that neither polls, defers nor
                         filters emits exactly the markup it always did. See
                         widget-cell for why the anchor is nested rather than on
                         the polling element itself. --}}
                    @if($widget->usesPartialAnchor())
                        @include('wire-core::widgets.widget-cell', ['widget' => $widget])
                    @else
                        {{ $widget }}
                    @endif
                </div>
            @endif
        @endforeach
    </div>
</div>
