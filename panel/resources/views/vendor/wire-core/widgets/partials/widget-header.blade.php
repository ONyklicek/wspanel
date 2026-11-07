{{-- Heading, description and filter for the two widgets whose card wraps them
     the same way (progress, list).

     Not a heading partial for *every* widget: the stats grid, the chart and the
     table widget each wrap these lines differently on purpose — a chart's sits
     beside its canvas, a table's above a border — and collapsing distinct
     surfaces into one helper is what CLAUDE.md forbids. Two surfaces that really
     are identical sharing one partial is the other half of the same rule.

     Variables: $widget, $filterOptions, $activeFilter, $filterExpression --}}
@if($widget->getHeading() || $widget->getDescription() || $filterOptions || $widget->hasRenderableActions())
    <div class="mb-4 flex items-start justify-between gap-4">
        <div>
            @if($widget->getHeading())
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ $widget->getHeading() }}</h3>
            @endif
            @if($widget->getDescription())
                <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ $widget->getDescription() }}</p>
            @endif
        </div>

        @include('wire-core::widgets.partials.widget-actions', ['widget' => $widget])

        @if($filterOptions)
            @include('wire-core::widgets.partials.widget-filter', [
                'widget' => $widget,
                'filterOptions' => $filterOptions,
                'activeFilter' => $activeFilter,
                'filterExpression' => $filterExpression,
            ])
        @endif
    </div>
@endif
