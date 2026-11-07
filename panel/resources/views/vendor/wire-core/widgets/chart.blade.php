{{-- The `wire:key` carries the active filter, and that is load-bearing rather
     than hygiene. A filter change answers with this widget's region and the
     response morphs it in — but Alpine never re-evaluates `x-data` on an element
     it has already initialised, so the new labels and datasets baked into the
     attribute would be read by nobody and the chart would keep drawing the old
     series. A changed key makes the morph replace the element instead of
     patching it, which tears the old Chart.js instance down through the
     controller's `destroy()` and builds a new one over the new data. --}}
<div class="wire-chart-widget rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800"
     wire:key="wire-chart-{{ $widget->getKey() }}-{{ $activeFilter }}"
     x-data="wireChart(@js($type), @js($labels), @js($datasets), @js($options))">

    @if($widget->getHeading() || $widget->hasFilter() || $widget->hasRenderableActions())
        <div class="mb-4 flex items-center justify-between gap-4">
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

    {{-- wire:ignore: Chart.js owns this canvas DOM. Without it a Livewire morph
         can touch the canvas mid-render and fight Chart.js for it. --}}
    <div class="wire-chart-canvas" wire:ignore>
        <canvas x-ref="canvas" data-testid="chart-canvas" @wireEl('chart-canvas') style="width: 100%; height: 250px;"></canvas>
    </div>
</div>

@include('wire-core::widgets.partials.chart-assets')
