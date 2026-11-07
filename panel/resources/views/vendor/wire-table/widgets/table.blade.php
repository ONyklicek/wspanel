{{-- TableWidget — a few rows of a table inside a dashboard card.

     Deliberately not the table's own row markup. `tables.index` carries the
     toolbar, the selection column, the morph markers, the skeleton splices and
     the payload fuse budget, none of which a five-row read-only card needs or
     should pay for. What it *does* share is the one thing that must not drift:
     each cell is `Column::renderCell()`, so a badge, a money format or a
     relation path draws here exactly as it draws on a full table.

     `@include` per cell is what a table's row loop must never do — but a widget
     header is not a row loop: five rows of three columns is fifteen cells drawn
     once per dashboard render, against a table's V×R. The cost model applies
     where the multiplication is.

     Variables: $widget, $columns, $records --}}
{{-- `overflow-clip` for the same reason the full table's card carries it: the
     radius is this element's, and the thead's rule, the rows' dividers and the
     scrollbar below them all run to the edge without one. Clip and not hidden,
     so the card never becomes a scroll container. --}}
<div class="wire-table-widget rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 overflow-clip">
    @if($widget->getHeading() || $widget->getDescription() || $widget->hasRenderableActions())
        <div class="flex items-start justify-between gap-4 border-b border-gray-200 px-4 py-3 dark:border-gray-700">
            <div>
                @if($widget->getHeading())
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ $widget->getHeading() }}</h3>
                @endif
                @if($widget->getDescription())
                    <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ $widget->getDescription() }}</p>
                @endif
            </div>

            @include('wire-core::widgets.partials.widget-actions', ['widget' => $widget])
        </div>
    @endif

    {{-- Same scrollbar rules as the full table's region: a widget three columns
         wider than its card is the same silence, on a smaller box. --}}
    @include('wire-core::partials.scroller-assets')
    <div class="wire-table-widget-content overflow-x-auto wire-scroller">
        @if($columns === [] || $records === [])
            <p class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">{{ $widget->getEmptyState() }}</p>
        @else
            <table class="w-full text-left text-sm">
                <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    <tr>
                        @foreach($columns as $widgetColumn)
                            <th scope="col" class="whitespace-nowrap px-4 py-2 font-medium">{{ $widgetColumn->getLabel() }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($records as $widgetRecord)
                        <tr class="text-gray-700 dark:text-gray-300">
                            @foreach($columns as $widgetColumn)
                                <td class="px-4 py-2 align-middle">{!! $widgetColumn->renderCell($widgetRecord) !!}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
