{{-- The widgets a user can put on this dashboard but has not.

     One drag with the grid, through `x-sort:group`: dropping a tile in here
     takes it off the dashboard, dropping one over there puts it on. Buttons do
     the same two things for anybody not using a pointer — and they are what
     makes the feature testable without a browser, which is why they are not an
     afterthought.

     The tray is empty when everything is placed, and says so rather than
     collapsing to nothing: a strip that disappears reads as a bug in the middle
     of an editing session.

     At the dashboard's `maxWidgets()` the add buttons go and the tray says why,
     rather than a click that silently does nothing — the server refuses the
     widget either way.

     Variables: $available (group => widgets), $trayGroup, $atWidgetLimit, $maxWidgets --}}
<div class="wire-widget-tray mb-6 rounded-lg border border-dashed border-gray-300 bg-gray-50 p-4 dark:border-gray-600 dark:bg-gray-800/50"
     x-sort="$wire.removeWidget($item)"
     x-sort:group="{{ $trayGroup }}"
     data-testid="widget-tray" @wireEl('widget-tray')>
    <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
        {{ __('wire-core::messages.widget_tray') }}
    </p>

    @php
        $trayEmpty = collect($available)->flatten()->isEmpty();
        $atWidgetLimit = $atWidgetLimit ?? false;
    @endphp

    @if($atWidgetLimit && ! $trayEmpty)
        <p class="mb-3 text-sm text-amber-700 dark:text-amber-400" data-testid="widget-tray-limit" @wireEl('widget-tray-limit')>
            {{ __('wire-core::messages.widget_limit_reached', ['max' => $maxWidgets ?? 0]) }}
        </p>
    @endif

    @if($trayEmpty)
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('wire-core::messages.widget_tray_empty') }}</p>
    @else
        @foreach($available as $trayGroupName => $trayWidgets)
            @if($trayGroupName !== '')
                <p class="mb-2 mt-3 text-xs font-medium text-gray-500 dark:text-gray-400 first:mt-0">{{ $trayGroupName }}</p>
            @endif

            <div class="flex flex-wrap gap-2">
                @foreach($trayWidgets as $trayWidget)
                    @php
                        // The size it will actually arrive at — the first size
                        // it offers, clamped to this dashboard's columns. Asked
                        // of the same owner the drop asks, so the label and the
                        // tile cannot disagree.
                        [$trayWidth, $trayHeight] = \NyonCode\WireCore\Widgets\Support\WidgetSizeOffer::for($trayWidget, $columns ?? 2)->arrivalSize();
                    @endphp
                    <div wire:key="tray-{{ $trayWidget->getKey() }}"
                         x-sort:item="@js($trayWidget->getKey())"
                         data-testid="widget-tray-{{ $trayWidget->getKey() }}"
                         class="flex items-center gap-2 rounded-md border border-gray-200 bg-white px-2.5 py-1.5 text-sm text-gray-700 shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                        <span x-sort:handle class="cursor-grab text-gray-500 dark:text-gray-400">
                            {!! icon('outline:bars-3', 'w-4 h-4', 'h-4 w-4') !!}
                        </span>

                        {{-- The description under the name: a tray is where
                             somebody decides what to put on their dashboard,
                             and a heading alone rarely says what a widget
                             shows. --}}
                        <span class="flex flex-col">
                            <span>{{ $trayWidget->getHeading() ?? $trayWidget->getKey() }}</span>
                            @if($trayWidget->getDescription())
                                <span class="text-xs text-gray-500 dark:text-gray-400" data-testid="widget-tray-description-{{ $trayWidget->getKey() }}">{{ $trayWidget->getDescription() }}</span>
                            @endif
                        </span>

                        {{-- The size it will arrive at, said out loud: a widget
                             that only looks right at 2×2 should not be a
                             surprise once it is on the grid. --}}
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $trayWidth }}×{{ $trayHeight }}</span>

                        @unless($atWidgetLimit)
                        <button type="button"
                                wire:click="{{ $trayWidget->getPlaceExpression() }}"
                                data-testid="widget-add-{{ $trayWidget->getKey() }}"
                                aria-label="{{ __('wire-core::messages.widget_add') }}"
                                class="rounded-sm p-0.5 text-gray-500 hover:text-gray-600 dark:hover:text-gray-300">
                            {!! icon('outline:plus', 'w-4 h-4', 'h-4 w-4') !!}
                        </button>
                        @endunless
                    </div>
                @endforeach
            </div>
        @endforeach
    @endif
</div>
