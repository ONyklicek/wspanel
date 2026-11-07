<div class="wire-bar-chart-widget {{ $widget->getCardRadiusClass() }} border border-gray-100 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
    @if($widget->getHeading() || $widget->getDescription() || $showMenu || $widget->hasRenderableActions())
        <div class="mb-6 flex items-start justify-between gap-4">
            <div>
                @if($widget->getHeading())
                    <h3 class="text-xl font-semibold text-slate-900 dark:text-white">{{ $widget->getHeading() }}</h3>
                @endif
                @if($widget->getDescription())
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $widget->getDescription() }}</p>
                @endif
            </div>

            @include('wire-core::widgets.partials.widget-actions', ['widget' => $widget])

            @if($showMenu)
                @include('wire-core::partials.floating-assets')

                <div class="relative" x-data="wireDropdown({ placement: 'bottom-end' })" @keydown.escape.window="close()">
                    <button type="button"
                            x-ref="trigger"
                            x-on:click="toggle()"
                            class="rounded-lg p-1.5 text-slate-500 dark:text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-700"
                            aria-label="{{ __('Options') }}">
                        {!! icon('ellipsis-horizontal', 'w-4 h-4', 'h-5 w-5') !!}
                    </button>
                    <template x-teleport="body">
                        <div x-ref="panel" x-show="open" x-cloak x-transition @click.outside="$clickedInside($event) || close()"
                             style="display: none;"
                             class="absolute top-0 left-0 z-50 w-40 origin-top-right rounded-xl border border-gray-100 bg-white py-1 text-sm text-slate-600 shadow-lg dark:border-gray-700 dark:bg-gray-800 dark:text-slate-300">
                            {{-- Menu affordance; host dashboards can wire actions here. --}}
                            <button type="button" class="block w-full px-3 py-1.5 text-left hover:bg-slate-50 dark:hover:bg-slate-700">{{ __('Refresh') }}</button>
                        </div>
                    </template>
                </div>
            @endif
        </div>
    @endif

    @include('wire-core::widgets.bar-chart.'.$widget->getPartialName(), ['items' => $items])
</div>
