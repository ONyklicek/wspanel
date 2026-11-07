{{-- What a deferred widget shows until its own render arrives.

     A card rather than a spinner: the grid has already committed to a layout,
     and a bare spinner in a full-width cell reads as an error. The heading is
     drawn for real when there is one — it is the one thing the server already
     knows before the query runs.

     It does not claim to be the *height* of the widget it stands in for, and a
     screenshot of the two states side by side shows why the claim would be a
     lie: four skeleton bars are four bars, and the widget behind them has as
     many rows as its query returns. The page still settles when the real one
     lands. Sizing the placeholder honestly would mean the widget declaring how
     tall it expects to be, which is a number nobody can give before the query
     they are deferring *because* it is slow.

     `aria-hidden` on the bars, and a live region announcing the wait: a screen
     reader should hear that something is loading, not read out four grey
     rectangles.

     Variables: $widget --}}
<div class="wire-widget-placeholder rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800"
     role="status"
     aria-live="polite"
     aria-busy="true">
    @if($widget->getHeading())
        <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ $widget->getHeading() }}</h3>
    @endif
    @if($widget->getDescription())
        <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ $widget->getDescription() }}</p>
    @endif

    <span class="sr-only">{{ __('wire-core::messages.widget_loading') }}</span>

    <div class="mt-3 animate-pulse space-y-3" aria-hidden="true">
        <div class="h-8 w-1/2 rounded-sm bg-gray-200 dark:bg-gray-700"></div>
        <div class="h-3 w-full rounded-sm bg-gray-200 dark:bg-gray-700"></div>
        <div class="h-3 w-5/6 rounded-sm bg-gray-200 dark:bg-gray-700"></div>
        <div class="h-3 w-2/3 rounded-sm bg-gray-200 dark:bg-gray-700"></div>
    </div>
</div>
