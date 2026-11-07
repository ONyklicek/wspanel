{{-- Vertical finance bars: value above, colored fill on a light max-height track, caption below.
     With ->verticalLabels() the caption moves to a rotated label beside each bar. --}}
@php $verticalLabels = $widget->hasVerticalLabels(); @endphp
<div class="flex items-end justify-between gap-3 sm:gap-5">
    @foreach($items as $item)
        @php $percent = $widget->percentageFor($item); @endphp
        <div class="flex min-w-0 flex-1 flex-col items-center gap-3">
            <span class="text-sm font-semibold text-slate-900 dark:text-white">{{ $item->getFormattedValue() }}</span>

            @if($verticalLabels)
                {{-- Rotated label beside the bar so long names fit without overflowing. --}}
                <div class="flex w-full items-end justify-center gap-1.5" style="height: {{ $widget->getHeight() }}px">
                    <span class="max-h-full shrink-0 truncate whitespace-nowrap text-xs font-medium text-slate-500 dark:text-slate-400"
                          style="writing-mode: vertical-rl; transform: rotate(180deg);"
                          title="{{ $item->getLabel() }}">{{ $item->getLabel() }}</span>
                    <div class="relative flex h-full w-full max-w-[3.5rem] items-end overflow-hidden rounded-xl bg-slate-100 dark:bg-slate-700/50">
                        <div class="w-full rounded-xl bg-gradient-to-t {{ $widget->fillClassesFor($item) }} h-[var(--value)] transition-[height] duration-500 ease-out"
                             style="--value: {{ $percent }}%"
                             role="img"
                             aria-label="{{ $item->getLabel() }}: {{ $item->getFormattedValue() }}"></div>
                    </div>
                </div>
            @else
                <div class="relative flex w-full max-w-[3.5rem] items-end overflow-hidden rounded-xl bg-slate-100 dark:bg-slate-700/50"
                     style="height: {{ $widget->getHeight() }}px">
                    <div class="w-full rounded-xl bg-gradient-to-t {{ $widget->fillClassesFor($item) }} h-[var(--value)] transition-[height] duration-500 ease-out"
                         style="--value: {{ $percent }}%"
                         role="img"
                         aria-label="{{ $item->getLabel() }}: {{ $item->getFormattedValue() }}"></div>
                </div>

                <span class="text-center text-xs font-medium text-slate-500 dark:text-slate-400">{{ $item->getLabel() }}</span>
            @endif
        </div>
    @endforeach
</div>
