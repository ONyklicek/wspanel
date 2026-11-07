<div class="wire-stats-overview">
    {{-- The widget's own heading. Every other widget view draws one and this one
         did not, so `StatsOverviewWidget::make()->heading(…)` was API that did
         nothing — found by building a dashboard out of them (V2.6 step 3), and
         invisible to the existing test, which asserted the getter rather than
         the markup. Not extracted into a shared partial: the four surfaces wrap
         these two lines differently on purpose (a chart's sits beside its
         filter, a table's above a border), and collapsing distinct surfaces into
         one helper is what CLAUDE.md forbids. --}}
    @if($widget->getHeading() || $widget->getDescription() || $widget->hasRenderableActions())
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
        </div>
    @endif

    {{-- Responsive: 1 col on mobile, growing toward the configured count (an
         inline repeat() ignored the viewport and crushed the cards on phones). --}}
    <div @class([
        'grid gap-4 grid-cols-1',
        'sm:grid-cols-2' => $columns === 2,
        'sm:grid-cols-2 lg:grid-cols-3' => $columns === 3,
        'sm:grid-cols-2 lg:grid-cols-4' => $columns >= 4,
    ])>
        @foreach($stats as $stat)
            <div class="wire-stat-card rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800"
                 @if($stat->getExtraAttributes()) @foreach($stat->getExtraAttributes() as $attr => $val) {{ $attr }}="{{ $val }}" @endforeach @endif>
                <div class="flex items-center justify-between">
                    <div class="flex-1">
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">
                            {{ $stat->getLabel() }}
                        </p>
                        <p class="mt-1 text-2xl font-semibold {{ $stat->getValueColorClass() }}">
                            {{ $stat->getValue() }}
                        </p>
                        @if($stat->getDescription())
                            <p class="mt-1 flex items-center gap-1 text-sm {{ $stat->getDescriptionColorClass() }}">
                                @if($stat->getDescriptionIcon())
                                    {!! icon($stat->getDescriptionIcon(), 'w-4 h-4', 'h-4 w-4') !!}
                                @endif
                                {{ $stat->getDescription() }}
                            </p>
                        @endif
                    </div>

                    @if($stat->getIcon())
                        <div class="ml-4">
                            {!! icon($stat->getIcon(), 'w-4 h-4', 'h-8 w-8 text-gray-400 dark:text-gray-500') !!}
                        </div>
                    @endif
                </div>

                {{-- The geometry belongs to Foundation\View\Sparkline, not to this
                     template: a table cell draws the same curve, and arithmetic in a
                     view is arithmetic nothing can test. --}}
                @if($sparkline = \NyonCode\WireCore\Foundation\View\Sparkline::of($stat->getChart() ?? []))
                    <div class="mt-3">
                        <svg viewBox="{{ $sparkline->viewBox() }}" class="h-8 w-full" preserveAspectRatio="none">
                            <polyline
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.5"
                                class="{{ $stat->getChartColorClass() }}"
                                points="{{ $sparkline->points() }}"
                            />
                        </svg>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</div>
