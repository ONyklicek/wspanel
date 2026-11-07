{{-- Vertical system bars: icon + label + percentage above, colored fill on a 0–100% track, optional grid lines.
     With ->verticalLabels() the label moves out of the header to a rotated caption beside each bar. --}}
@php $verticalLabels = $widget->hasVerticalLabels(); @endphp
<div>
    {{-- Metric headers, one per bar (icon + value; label here only in the default layout). --}}
    <div class="mb-3 flex items-end justify-between gap-4">
        @foreach($items as $item)
            <div class="flex min-w-0 flex-1 flex-col items-center gap-1 text-center">
                @if($item->getIcon())
                    {!! icon($item->getIcon(), 'w-4 h-4', 'h-5 w-5 '.$widget->textClassesFor($item)) !!}
                @endif
                @unless($verticalLabels)
                    <span class="truncate text-xs font-medium text-slate-600 dark:text-slate-300">{{ $item->getLabel() }}</span>
                @endunless
                <span class="text-sm font-semibold {{ $widget->textClassesFor($item) }}">{{ $item->getFormattedValue() }}</span>
            </div>
        @endforeach
    </div>

    {{-- Plot area with optional grid lines shared across all bars. --}}
    <div class="relative" style="height: {{ $widget->getHeight() }}px">
        @if($showGrid)
            @foreach([100, 75, 50, 25, 0] as $line)
                <div class="absolute inset-x-0 flex items-center" style="bottom: {{ $line }}%">
                    <span class="-ml-1 w-9 shrink-0 pr-2 text-right text-[10px] leading-none text-slate-500 dark:text-slate-400">{{ $line }}%</span>
                    <span class="h-px flex-1 border-t border-dashed border-slate-100 dark:border-slate-700"></span>
                </div>
            @endforeach
        @endif

        <div class="absolute inset-0 flex items-end justify-between gap-4 {{ $showGrid ? 'pl-9' : '' }}">
            @foreach($items as $item)
                @php $percent = $widget->percentageFor($item); @endphp
                <div class="flex h-full min-w-0 flex-1 items-end justify-center gap-1.5">
                    @if($verticalLabels)
                        {{-- Rotated label beside the bar so long names fit without overflowing. --}}
                        <span class="max-h-full shrink-0 truncate whitespace-nowrap text-xs font-medium text-slate-600 dark:text-slate-300"
                              style="writing-mode: vertical-rl; transform: rotate(180deg);"
                              title="{{ $item->getLabel() }}">{{ $item->getLabel() }}</span>
                    @endif
                    <div class="relative flex h-full w-full max-w-[2.75rem] items-end overflow-hidden rounded-xl bg-slate-100 dark:bg-slate-700/50">
                        <div class="w-full rounded-xl bg-gradient-to-t {{ $widget->fillClassesFor($item) }} h-[var(--value)] transition-[height] duration-500 ease-out"
                             style="--value: {{ $percent }}%"
                             role="img"
                             aria-label="{{ $item->getLabel() }}: {{ $item->getFormattedValue() }}"></div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
