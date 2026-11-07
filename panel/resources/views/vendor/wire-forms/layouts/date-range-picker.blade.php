@php
    use NyonCode\WireCore\Foundation\Support\MobileSheet;
    use NyonCode\WireForms\Components\DateRangePicker;

    assert($layout instanceof DateRangePicker);

    $from = $layout->getFromPicker();
    $until = $layout->getUntilPicker();
    $presets = $layout->getPresets();
    $breakpoint = $from->getMobileBreakpoint();
    $fromPath = $from->getStatePath();
    $untilPath = $until->getStatePath();
    $testId = 'form-date-range-'.$fromPath;
    $withTime = $layout->hasTime();
    // The period's own bounds: the start picker's lower one and the end
    // picker's upper one. The other two are the coupling (each end bounded by
    // the other), which the one-box control enforces by ordering the clicks.
    $minBound = $from->getMinDate();
    $maxBound = $until->getMaxDate();
    $inactive = $from->isDisabled() || $from->isReadOnly();
    $typeable = $from->acceptsTypedInput();
    $hasError = $errors->has($fromPath) || $errors->has($untilPath);
    $controlId = $from->getId().'-range';
@endphp

<div class="space-y-2">
    @if($layout->getLabel())
        <label for="{{ $controlId }}" @class(['block text-sm font-medium text-gray-700 dark:text-gray-300', MobileSheet::hideBelow($breakpoint)])>
            {{ $layout->getLabel() }}
            @if($layout->isRequired())
                <span class="text-red-600 dark:text-red-400">*</span>
            @endif
        </label>
        <span @class(['block text-sm font-medium text-gray-700 dark:text-gray-300', MobileSheet::showBelow($breakpoint)])>{{ $layout->getLabel() }}</span>
    @endif

    {{-- From the breakpoint up: one box for the whole period, one panel. Bound
         to the two pickers' own state paths, so the phone's pair below and this
         control are two views of the same two columns. --}}
    <div @class([MobileSheet::hideBelow($breakpoint)])>
        @if(! $inactive)
            @once
                @include('wire-core::partials.floating-assets')
            @endonce
        @endif

        <div
            x-data="wireDateRangePicker({
                from: $wire.entangle('{{ $from->getWireModelAttribute() }}').live,
                to: $wire.entangle('{{ $until->getWireModelAttribute() }}').live,
                firstDayOfWeek: @js($from->getFirstDayOfWeek()),
                disabledDates: @js($from->getDisabledDates()),
                minDay: @js(\NyonCode\WireCore\Foundation\Support\DateBoundary::datePart($minBound)),
                maxDay: @js(\NyonCode\WireCore\Foundation\Support\DateBoundary::datePart($maxBound)),
                minTime: @js($withTime ? \NyonCode\WireCore\Foundation\Support\DateBoundary::timePart($minBound) : null),
                maxTime: @js($withTime ? \NyonCode\WireCore\Foundation\Support\DateBoundary::timePart($maxBound) : null),
                windowMin: @js($from->getMinTime()),
                windowMax: @js($from->getMaxTime()),
                withTime: @js($withTime),
                hoursStep: @js($from->getHoursStep() ?? 1),
                minutesStep: @js($from->getMinutesStep() ?? 1),
                displayFormat: @js($from->getDisplayFormat()),
                typedFormat: @js($from->getTypedFormat()),
                typeable: @js($typeable),
                presets: @js(collect($presets)->map(fn (array $range, string $label): array => ['label' => $label, 'from' => $range[0], 'to' => $range[1]])->values()),
            })"
            class="relative"
        >
            <div class="relative" x-ref="trigger">
                <input
                    type="text"
                    {{-- Never autofilled: see date-time-picker.blade.php. --}}
                    autocomplete="off"
                    id="{{ $controlId }}"
                    :value="typing ? typed : displayValue"
                    data-testid="{{ $testId }}-trigger"
                    aria-haspopup="dialog"
                    :aria-expanded="open ? 'true' : 'false'"
                    @if($layout->getLabel()) aria-label="{{ $layout->getLabel() }}" @endif
                    @if($typeable)
                        @input="onTyped($event.target.value)"
                        @blur="commitTyped()"
                        @keydown.enter.prevent="commitTyped(); open = false"
                    @elseif(! $inactive)
                        @keydown.enter.prevent="open = ! open"
                    @endif
                    @unless($inactive)
                        @click="open = true"
                        @keydown.down.prevent="open ? focusGrid() : openWithKeyboard()"
                    @endunless
                    @keydown.escape="cancelTyped(); open = false"
                    @unless($typeable) readonly @endunless
                    @if($from->isDisabled()) disabled @endif
                    {{-- Never `required`: the server holds both ends, and the
                         box is display:none on a phone. --}}
                    @if($layout->isRequired()) aria-required="true" @endif
                    @class([
                        'block w-full rounded-md border-gray-300 shadow-sm pr-10',
                        'cursor-pointer' => ! $typeable,
                        'focus:border-primary-500 focus:ring-primary-500',
                        'hover:border-gray-400 dark:hover:border-gray-500 transition-colors duration-150',
                        'dark:bg-gray-800 dark:border-gray-600 dark:text-white text-base sm:text-sm',
                        'border-red-500 focus:border-red-500 focus:ring-red-500' => $hasError,
                    ])
                />
                <button
                    type="button"
                    tabindex="-1"
                    data-testid="{{ $testId }}-toggle"
                    aria-label="{{ __('Open calendar') }}"
                    @click="open ? (open = false) : openWithKeyboard()"
                    @if($inactive) disabled @endif
                    class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-500 hover:text-gray-600 dark:hover:text-gray-200 disabled:pointer-events-none transition-colors duration-150"
                >
                    {!! icon('outline:calendar', 'h-4 w-4') !!}
                </button>
            </div>

            @unless($inactive)
            <template x-teleport="body">
                <div
                    x-ref="panel"
                    x-show="open"
                    @click.outside="$clickedInside($event) || $refs.trigger.contains($event.target) || (open = false)"
                    @keydown.escape="closeToTrigger()"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-100"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 -translate-y-1"
                    role="dialog"
                    data-testid="{{ $testId }}-panel"
                    class="absolute top-0 left-0 z-50 flex bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg shadow-lg"
                    x-cloak
                    style="display: none;"
                >
                    @if($presets !== [])
                        {{-- The periods, Fiori's DynamicDateRange way: a list
                             beside the calendar, the one in force marked. --}}
                        <div class="w-40 shrink-0 border-r border-gray-200 dark:border-gray-600 p-2">
                            <p class="px-2 pb-1 text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('wire-forms::fields.picker.presets') }}</p>
                            <template x-for="(preset, index) in presets" :key="index">
                                <button
                                    type="button"
                                    @click="applyPreset(preset)"
                                    :data-testid="'{{ $testId }}-option-' + index"
                                    :aria-pressed="isActivePreset(preset) ? 'true' : 'false'"
                                    :class="isActivePreset(preset)
                                        ? 'bg-primary-50 text-primary-700 font-medium dark:bg-primary-500/15 dark:text-primary-300'
                                        : 'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700'"
                                    class="block w-full rounded-md px-2 py-1.5 text-left text-sm transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                                    x-text="preset.label"
                                ></button>
                            </template>
                        </div>
                    @endif

                    <div class="p-4">
                        <div class="flex items-start gap-4">
                            <div>
                                <template x-if="view === 'days'">
                                    {{-- Two months, the arrows on the outer edges; each
                                         title opens the month grid for that side. --}}
                                    <div class="flex items-center justify-between gap-1 mb-2">
                                        <button type="button" @click="prevMonth()" :disabled="! canGoPrev"
                                                data-testid="{{ $testId }}-prev-month" aria-label="{{ __('wire-forms::fields.picker.previous') }}"
                                                class="p-1.5 rounded-md text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                                            {!! icon('chevron-left', 'w-4 h-4', 'h-4 w-4') !!}
                                        </button>
                                        <div class="flex flex-1 justify-around">
                                            <template x-for="offset in [0, 1]" :key="offset">
                                                <button type="button" @click="showMonths(offset)" x-text="monthLabel(offset)"
                                                        :data-testid="'{{ $testId }}-month-toggle-' + offset"
                                                        aria-label="{{ __('wire-forms::fields.picker.choose_month') }}"
                                                        class="px-2 py-1 rounded-md text-sm font-semibold capitalize text-gray-900 dark:text-white hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"></button>
                                            </template>
                                        </div>
                                        <button type="button" @click="nextMonth()" :disabled="! canGoNext"
                                                data-testid="{{ $testId }}-next-month" aria-label="{{ __('wire-forms::fields.picker.next') }}"
                                                class="p-1.5 rounded-md text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                                            {!! icon('chevron-right', 'w-4 h-4', 'h-4 w-4') !!}
                                        </button>
                                    </div>
                                </template>
                                <template x-if="view !== 'days'">
                                    @include('wire-forms::partials.calendar.header', ['testId' => $testId])
                                </template>

                                <div x-show="view === 'days'" class="flex gap-6" @mouseleave="anchor && (hoverDay = anchor)">
                                    <template x-for="offset in [0, 1]" :key="offset">
                                        <div>
                                            <div class="grid grid-cols-7 mb-1">
                                                <template x-for="name in dayNames" :key="name">
                                                    <div class="w-9 py-1 text-center text-xs font-medium text-gray-500 dark:text-gray-400" x-text="name"></div>
                                                </template>
                                            </div>
                                            <div class="grid grid-cols-7" role="grid" @keydown="onGridKey($event)">
                                                <template x-for="(cell, idx) in monthCells(offset)" :key="offset + '-' + idx">
                                                    <div class="relative h-9 w-9">
                                                        {{-- The band: half into each end, whole under the days between. --}}
                                                        <span x-show="cell.current && bandLeft(cell.date)" class="absolute inset-y-0.5 left-0 w-1/2 bg-primary-50 dark:bg-primary-500/15"></span>
                                                        <span x-show="cell.current && bandRight(cell.date)" class="absolute inset-y-0.5 right-0 w-1/2 bg-primary-50 dark:bg-primary-500/15"></span>
                                                        <button
                                                            type="button"
                                                            x-show="cell.current"
                                                            @click="pickDay(cell.date)"
                                                            @mouseenter="onDayHover(cell.date)"
                                                            :disabled="isDisabled(cell.date)"
                                                            :data-date="cell.date"
                                                            :tabindex="cell.date === focusDay ? 0 : -1"
                                                            :aria-selected="isRangeEdge(cell.date) ? 'true' : 'false'"
                                                            :data-testid="cell.current ? '{{ $testId }}-day-' + cell.date : null"
                                                            :class="{
                                                                'bg-primary-600 text-white hover:bg-primary-700': isRangeEdge(cell.date),
                                                                'text-primary-900 dark:text-primary-100 hover:bg-primary-100 dark:hover:bg-primary-500/30': ! isRangeEdge(cell.date) && bandLeft(cell.date) && bandRight(cell.date),
                                                                'text-gray-900 dark:text-white hover:bg-gray-100 dark:hover:bg-gray-700': ! isRangeEdge(cell.date) && ! (bandLeft(cell.date) && bandRight(cell.date)) && ! isDisabled(cell.date),
                                                                'opacity-40 cursor-not-allowed': isDisabled(cell.date),
                                                                'ring-1 ring-inset ring-primary-500 font-semibold': isToday(cell.date) && ! isRangeEdge(cell.date),
                                                            }"
                                                            class="relative h-9 w-9 rounded-md text-sm transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-1 dark:focus-visible:ring-offset-gray-800"
                                                            x-text="cell.day"
                                                        ></button>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                </div>

                                @include('wire-forms::partials.calendar.months', ['testId' => $testId, 'width' => 'w-full min-w-[15.75rem]'])
                            </div>

                            @if($withTime)
                                {{-- One dial for both ends: the switch above it says which
                                     end it sets, and reads each end's time. --}}
                                <div class="pl-4 border-l border-gray-200 dark:border-gray-600">
                                    <div class="mx-2 grid grid-cols-2 gap-1 rounded-lg bg-gray-100 p-1 dark:bg-gray-900/50" role="group">
                                        @foreach(['from' => $from->getLabel(), 'to' => $until->getLabel()] as $end => $endLabel)
                                            <button type="button"
                                                    @click="showDialEnd('{{ $end }}')"
                                                    :aria-pressed="dialEnd === '{{ $end }}' ? 'true' : 'false'"
                                                    data-testid="{{ $testId }}-dial-end-{{ $end }}"
                                                    :class="dialEnd === '{{ $end }}'
                                                        ? 'bg-white text-primary-700 shadow-sm dark:bg-gray-700 dark:text-primary-300'
                                                        : 'text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white'"
                                                    class="flex items-baseline justify-center gap-1.5 rounded-md px-2 py-1.5 text-xs font-medium transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                                                <span>{{ $endLabel }}</span>
                                                <span class="tabular-nums" x-text="timeStr({{ $end }}Time)"></span>
                                            </button>
                                        @endforeach
                                    </div>
                                    @include('wire-forms::partials.clock-dial', ['testId' => $testId, 'footer' => false])
                                </div>
                            @endif
                        </div>

                        <div class="flex items-center justify-between gap-6 mt-3 pt-3 border-t border-gray-200 dark:border-gray-600">
                            <span class="text-xs text-gray-500 dark:text-gray-400" aria-live="polite"
                                  x-text="anchor ? @js(__('wire-forms::fields.picker.pick_end')) : displayValue"></span>
                            <div class="flex items-center gap-4">
                                <button type="button" @click="clear()" data-testid="{{ $testId }}-clear"
                                        class="text-xs text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition-colors duration-150">
                                    {{ __('wire-forms::fields.picker.clear') }}
                                </button>
                                @if($withTime)
                                    <button type="button" @click="closeToTrigger()" data-testid="{{ $testId }}-done"
                                            class="rounded-md bg-primary-600 px-3 py-1 text-xs font-medium text-white hover:bg-primary-700 transition-colors duration-150">
                                        {{ __('wire-forms::fields.picker.done') }}
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </template>
            @endunless
        </div>

        @foreach([$fromPath, $untilPath] as $path)
            @error($path)
                <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        @endforeach
    </div>

    {{-- Below the breakpoint: the two pickers, exactly the phone controls they
         always were, and the presets as chips above them. --}}
    <div @class(['space-y-2', MobileSheet::showBelow($breakpoint)])>
        @if($presets !== [])
            {{-- One click writes both ends. The values were resolved in PHP, so the
                 browser never has to agree with the server about what "this month"
                 means — and `.live` is what makes the two pickers redraw with them. --}}
            <div
                x-data="{
                    from: @entangle($fromPath).live,
                    to: @entangle($untilPath).live,
                }"
                class="flex flex-wrap gap-2"
            >
                @foreach($presets as $label => $range)
                    <button
                        type="button"
                        @click="from = @js($range[0]); to = @js($range[1])"
                        data-testid="form-date-range-{{ $fromPath }}-preset-{{ $loop->index }}"
                        class="rounded-full border border-gray-300 px-3 py-1 text-xs text-gray-600 hover:border-primary-500 hover:text-primary-600 dark:border-gray-600 dark:text-gray-300 dark:hover:text-primary-400"
                    >{{ $label }}</button>
                @endforeach
            </div>
        @endif

        <div class="grid gap-4 sm:grid-cols-2">
            @if($from->isVisible())
                {{ $from }}
            @endif
            @if($until->isVisible())
                {{ $until }}
            @endif
        </div>
    </div>
</div>
