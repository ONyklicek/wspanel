{{-- ProgressWidget — rows of a fill against a track.

     The width is an inline style and not a Tailwind class, deliberately: a
     percentage is a continuous value and a utility class is a fixed set, so
     `w-[73.4%]` would need the JIT to have seen that exact number at build time.
     The number itself is resolved in PHP (ProgressItem::getPercentage), clamped
     there, and never reaches a class name — so nothing a caller supplies can
     inject one.

     `role="progressbar"` with the three aria-value attributes is what makes the
     track mean anything without sight of it; the printed value is marked
     aria-hidden because the same reading is already on the bar.

     Variables: $widget, $items, $showValues, $filterOptions, $activeFilter, $filterExpression --}}
<div class="wire-progress-widget rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
    @include('wire-core::widgets.partials.widget-header', [
        'widget' => $widget,
        'filterOptions' => $filterOptions,
        'activeFilter' => $activeFilter,
        'filterExpression' => $filterExpression,
    ])

    @if($items === [])
        <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">{{ $widget->getEmptyState() }}</p>
    @else
        <div class="space-y-4">
            @foreach($items as $item)
                <div class="wire-progress-row"
                     @foreach($item->getExtraAttributes() as $attr => $val) {{ $attr }}="{{ $val }}" @endforeach>
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-2">
                            @if($item->getIcon())
                                {!! icon($item->getIcon(), 'w-4 h-4', 'h-4 w-4 shrink-0 text-gray-400 dark:text-gray-500') !!}
                            @endif
                            <span class="truncate text-sm font-medium text-gray-700 dark:text-gray-300">{{ $item->getLabel() }}</span>
                        </div>

                        @if($showValues)
                            <span class="shrink-0 text-sm font-semibold tabular-nums {{ $item->getValueColorClass() }}" aria-hidden="true">
                                {{ $item->getFormattedValue() }}
                            </span>
                        @endif
                    </div>

                    <div class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700"
                         role="progressbar"
                         aria-label="{{ $item->getLabel() }}"
                         aria-valuenow="{{ (int) round($item->getPercentage()) }}"
                         aria-valuemin="0"
                         aria-valuemax="100">
                        <div class="h-full rounded-full transition-all {{ $item->getBarColorClass() }}"
                             style="width: {{ $item->getPercentage() }}%"></div>
                    </div>

                    @if($item->getDescription())
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $item->getDescription() }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
