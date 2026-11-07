{{-- Horizontal progress bars: label left, value right, colored fill on a light track. --}}
<div class="flex flex-col gap-5">
    @foreach($items as $item)
        @php $percent = $widget->percentageFor($item); @endphp
        <div>
            <div class="mb-2 flex items-center justify-between gap-3">
                <span class="flex min-w-0 items-center gap-2 text-sm font-medium text-slate-600 dark:text-slate-300">
                    @if($item->getIcon())
                        {!! icon($item->getIcon(), 'w-4 h-4', 'h-4 w-4 shrink-0 '.$widget->textClassesFor($item)) !!}
                    @endif
                    <span class="truncate">{{ $item->getLabel() }}</span>
                </span>
                <span class="shrink-0 text-sm font-semibold {{ $widget->textClassesFor($item) }}">{{ $item->getFormattedValue() }}</span>
            </div>

            <div class="h-2.5 w-full overflow-hidden rounded-xl bg-slate-100 dark:bg-slate-700/50">
                <div class="h-full rounded-xl bg-gradient-to-r {{ $widget->fillClassesFor($item) }} w-[var(--value)] transition-[width] duration-500 ease-out"
                     style="--value: {{ $percent }}%"
                     role="img"
                     aria-label="{{ $item->getLabel() }}: {{ $item->getFormattedValue() }}"></div>
            </div>
        </div>
    @endforeach
</div>
