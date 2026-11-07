@php
    use NyonCode\WireCore\Foundation\Support\MobileSheet;
    use NyonCode\WireForms\Components\DateTimePicker;

    assert($field instanceof DateTimePicker);

    $wireModifier = $field->getWireModelModifier();
    $wireAttr = 'wire:model' . ($wireModifier ? ".{$wireModifier}" : '');
    $mode = $field->getMode();
    $hasDate = in_array($mode, ['date', 'datetime']);
    $hasTime = in_array($mode, ['time', 'datetime']);
    $firstDayOfWeek = $field->getFirstDayOfWeek();
    $disabledDates = $field->getDisabledDates();
    // Bounds arrive already in the widget's own format. The calendar compares
    // days and the clock compares times, so each half is split out once here
    // rather than re-parsed on every cell.
    $minBound = $field->getMinDate();
    $maxBound = $field->getMaxDate();
    $minDay = \NyonCode\WireCore\Foundation\Support\DateBoundary::datePart($minBound);
    $maxDay = \NyonCode\WireCore\Foundation\Support\DateBoundary::datePart($maxBound);
    $minTime = $hasTime ? \NyonCode\WireCore\Foundation\Support\DateBoundary::timePart($minBound) : null;
    $maxTime = $hasTime ? \NyonCode\WireCore\Foundation\Support\DateBoundary::timePart($maxBound) : null;
    $hoursStep = $field->getHoursStep() ?? 1;
    $minutesStep = $field->getMinutesStep() ?? 1;
    $hasSeconds = $field->hasSeconds();
    $secondsStep = $field->getSecondsStep() ?? 1;
    $fieldId = $field->getId();
    // Custom picker, native input, or both split at the mobile breakpoint
    // (->nativeOnMobile()), where CSS shows one and hides the other.
    $nativeMode = $field->getNativeControlMode();
    $responsive = $nativeMode->isResponsive();
    $sheetBp = $field->getMobileBreakpoint();
    // Below the configured breakpoint the calendar becomes a bottom sheet instead
    // of a floating panel — unless disabled via ->sheetOnMobile(false) or config,
    // or unless a native input takes that screen over, when it is never seen.
    // ->touchOnMobile(): below the breakpoint the touch wheel owns the screen,
    // so the desktop panel is hidden there and never becomes a sheet.
    $wheel = $field->usesTouchOnMobile();
    $sheetOnMobile = $field->usesSheetOnMobile() && ! $responsive && ! $wheel;
    $sheetBpPx = MobileSheet::px($sheetBp);
    $sheetPanel = \NyonCode\WireCore\Foundation\Support\MobileSheet::panelPadded($sheetBp);
    $sheetMotion = \NyonCode\WireCore\Foundation\Support\MobileSheet::motion($sheetBp);
    $sheetBackdrop = \NyonCode\WireCore\Foundation\Support\MobileSheet::backdropHide($sheetBp);
    // Whether the trigger takes a typed value as well as a picked one. readOnly()
    // and disabled() outrank typeable(), which is why this is one resolved answer
    // rather than three conditions repeated down the markup.
    $typeable = $field->acceptsTypedInput();
    $livewire = $field->getLivewire();
    $testId = 'form-datetime-'.$field->getStatePath();
    // Bounds can follow other state (a DateRangePicker end follows the other
    // end), and a changed x-data is reconciled by Alpine onto the old object —
    // writing the fresh `$wire.entangle` placeholder to the server as the value.
    // Keyed on the bounds, a changed picker is a new element instead.
    $boundsKey = md5((string) json_encode([$minBound, $maxBound, $disabledDates, $field->getMinTime(), $field->getMaxTime()]));
@endphp

@include('wire-forms::partials.field-assets')

@include('wire-forms::partials.field-wrapper-start')

@if($nativeMode->rendersCustom())
    {{-- Scaffolding is identical for every date picker; emit it once per request
         (matters when several date fields, or a repeater of them, render). --}}
    @once
        @include('wire-core::partials.floating-assets')
    @endonce
@endif

@if($wheel)
    @include('wire-forms::partials.wheel-picker')
@endif

@if($nativeMode->rendersNative())
    @include('wire-forms::partials.date-time-native-input')
@endif

@if($nativeMode->rendersCustom())
    {{-- The calendar/clock controller is registered once as `wireDateTimePicker`
         (packages/forms/resources/js/fields/date-time-picker.js); only the
         per-instance config is markup. `state` is built here rather than passed
         through config because `$wire.entangle` is an Alpine magic and magics are
         in scope only inside an x-data expression. --}}
    <div
            wire:key="picker-{{ $field->getStatePath() }}-{{ $boundsKey }}"
            x-data="wireDateTimePicker({
            {{-- Honor live(): mirror the other entangle-based fields. --}}
            state: $wire.entangle('{{ $field->getWireModelAttribute() }}'){{ $wireModifier ? '.' . $wireModifier : '' }},
            hasDate: @js($hasDate),
            hasTime: @js($hasTime),
            hasSeconds: @js($hasSeconds),
            firstDayOfWeek: @js($firstDayOfWeek),
            disabledDates: @js($disabledDates),
            minDay: @js($minDay),
            maxDay: @js($maxDay),
            minTime: @js($minTime),
            maxTime: @js($maxTime),
            windowMin: @js($field->getMinTime()),
            windowMax: @js($field->getMaxTime()),
            hoursStep: @js($hoursStep),
            minutesStep: @js($minutesStep),
            secondsStep: @js($secondsStep),
            displayFormat: @js($field->getDisplayFormat()),
            closeOnDateSelection: @js($field->shouldCloseOnDateSelection()),

            typedFormat: @js($field->getTypedFormat()),
            typeable: @js($typeable),
            sheetOnMobile: @js($sheetOnMobile),
            sheetBreakpoint: @js($sheetBpPx),
            mobileBreakpoint: @js($sheetBpPx),
        })"
            @class(['relative', MobileSheet::hideBelow($sheetBp) => $responsive || $wheel])
    >
        {{-- Input trigger --}}
        <div class="relative" x-ref="trigger">
            <input
                    type="text"
                    {!! $field->getExtraInputAttributesHtml() !!}
                    {{-- A box that opens a panel is never autofilled. Without this,
                         Safari reads a state path such as `tableState.…` as an
                         address field, draws its AutoFill button in the box and
                         takes the first click for it — the panel opens on the
                         second. After the extra attributes, so an app's own wins. --}}
                    autocomplete="off"
                    id="{{ $fieldId }}"
                    {{-- Mid-edit the box shows what is being typed; otherwise the
                         formatted value. Swapping on `typing` rather than on a
                         non-empty buffer lets the box be emptied to clear. --}}
                    :value="{{ $typeable ? 'typing ? typed : displayValue' : 'displayValue' }}"
                    data-testid="form-datetime-{{ $field->getStatePath() }}-trigger"
                    aria-haspopup="dialog"
                    :aria-expanded="open ? 'true' : 'false'"
                    @if($typeable)
                        @input="onTyped($event.target.value)"
                        @blur="commitTyped()"
                        @keydown.enter.prevent="commitTyped(); open = false"
                    @elseif(! $field->isReadOnly())
                        @keydown.enter.prevent="open = ! open"
                    @endif
                    @unless($field->isReadOnly())
                        {{-- Opens, never toggles: with a caret in the box, clicking
                             to place it would otherwise close the panel. The
                             chevron is the toggle. --}}
                        @click="open = true"
                        @keydown.down.prevent="open ? (wide && hasDate ? focusGrid() : null) : openWithKeyboard()"
                    @endunless
                    @keydown.escape="cancelTyped(); open = false"
                    @unless($typeable) readonly @endunless
                    @if($field->getPlaceholder()) placeholder="{{ $field->getPlaceholder() }}" @endif
                    @if($field->isDisabled()) disabled @endif
                    @if($field->hasAutofocus()) autofocus @endif
                    {{-- A hidden twin must not block the form: see the native partial. --}}
                    @if($field->isRequired()) @if($responsive || $field->isBelowBreakpointOnly()) aria-required="true" @else required @endif @endif
                    @class([
                        'block w-full rounded-md border-gray-300 shadow-sm',
                        // Only a box that cannot be typed into is a button.
                        'cursor-pointer' => ! $typeable,
                        'focus:border-primary-500 focus:ring-primary-500',
                        'hover:border-gray-400 dark:hover:border-gray-500 transition-colors duration-150',
                        'dark:bg-gray-800 dark:border-gray-600 dark:text-white text-base sm:text-sm',
                        'border-red-500 focus:border-red-500 focus:ring-red-500' => $errors->has($field->getStatePath()),
                    ])
            />
            {{-- A real button, not decoration: with the input now accepting text,
                 the icon is the only thing left that unambiguously means "open the
                 calendar". Out of the tab order on purpose — the input already
                 opens with ArrowDown, and a second stop on every date field buys
                 a keyboard user nothing. --}}
            <button
                    type="button"
                    tabindex="-1"
                    data-testid="form-datetime-{{ $field->getStatePath() }}-toggle"
                    aria-label="{{ $hasDate ? __('Open calendar') : __('Open clock') }}"
                    @click="open ? (open = false) : openWithKeyboard()"
                    @if($field->isDisabled() || $field->isReadOnly()) disabled @endif
                    class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-500 hover:text-gray-600 dark:hover:text-gray-200 disabled:pointer-events-none transition-colors duration-150"
            >
                {!! icon($hasDate ? 'outline:calendar' : 'outline:clock', 'h-4 w-4') !!}
            </button>
        </div>

        {{-- Calendar panel: floating from sm up, bottom sheet on a phone (max-sm:
             classes, $float skips Floating UI) with a dimming backdrop. --}}
        <template x-teleport="body">
        <div>
            @if($sheetOnMobile)
                {{-- Backdrop: mobile-only, taps to close. --}}
                <div
                        x-show="open"
                        x-cloak
                        x-transition:enter="transition ease-out duration-150"
                        x-transition:enter-start="opacity-0"
                        x-transition:enter-end="opacity-100"
                        x-transition:leave="transition ease-in duration-100"
                        x-transition:leave-start="opacity-100"
                        x-transition:leave-end="opacity-0"
                        @click="open = false"
                        class="fixed inset-0 z-40 bg-gray-500/60 dark:bg-gray-900/70 {{ $sheetBackdrop }}"
                ></div>
            @endif

            <div
                x-ref="panel"
                x-show="open"
                {{-- The trigger is excluded explicitly. Alpine runs .outside in
                     the capture phase, so without this it closes the panel a
                     beat before the chevron's own handler toggles it — and the
                     toggle can then only ever open. --}}
                @click.outside="$clickedInside($event) || $refs.trigger.contains($event.target) || (open = false)"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 -translate-y-1 {{ $sheetOnMobile ? $sheetMotion : '' }}"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-100"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 -translate-y-1 {{ $sheetOnMobile ? $sheetMotion : '' }}"
                @class([
                    'absolute top-0 left-0 z-50 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg shadow-lg p-4',
                    $sheetPanel => $sheetOnMobile,
                ])
                @if($sheetOnMobile) x-focus-trap="open" tabindex="-1" data-sheet-bp="{{ $sheetBpPx }}" @endif
                @keydown.escape="wide ? closeToTrigger() : (open = false)"
                x-cloak
                style="display: none;"
        >
            @if($sheetOnMobile)
                @include('wire-core::partials.sheet-grabber', ['dismiss' => 'open = false', 'breakpoint' => $sheetBp])
            @endif
            {{-- Below the breakpoint the panel is the phone's and stays exactly
                 that: the stepping header, the steppers, the two-button footer.
                 From it up (`wide`), the desktop panel: a header that opens month
                 and year grids, a keyboard-driven grid, and the clock as a dial
                 beside the calendar rather than steppers under it. --}}
            <div @if($hasDate && $hasTime) :class="wide ? 'flex items-start gap-4' : ''" @endif>
            @if($hasDate)
                <div>
                <template x-if="! wide">
                {{-- Month/year navigation --}}
                <div class="flex items-center justify-between mb-3">
                    <button type="button" @click="prevMonth()" :disabled="!canGoPrev"
                            :class="canGoPrev ? 'hover:bg-gray-100 dark:hover:bg-gray-700' : 'opacity-40 cursor-not-allowed'"
                            data-testid="form-datetime-{{ $field->getStatePath() }}-prev-month" aria-label="Previous month"
                            class="p-1 rounded-sm text-gray-600 dark:text-gray-300 transition-colors duration-150">
                        {!! icon('chevron-left', 'w-4 h-4', 'h-4 w-4') !!}
                    </button>
                    <span class="text-sm font-semibold text-gray-900 dark:text-white" x-text="monthYearLabel"></span>
                    <button type="button" @click="nextMonth()" :disabled="!canGoNext"
                            :class="canGoNext ? 'hover:bg-gray-100 dark:hover:bg-gray-700' : 'opacity-40 cursor-not-allowed'"
                            data-testid="form-datetime-{{ $field->getStatePath() }}-next-month" aria-label="Next month"
                            class="p-1 rounded-sm text-gray-600 dark:text-gray-300 transition-colors duration-150">
                        {!! icon('chevron-right', 'w-4 h-4', 'h-4 w-4') !!}
                    </button>
                </div>
                </template>
                <template x-if="wide">
                    @include('wire-forms::partials.calendar.header', ['testId' => $testId])
                </template>

                <div x-show="! wide || view === 'days'">
                {{-- Day names --}}
                <div class="grid grid-cols-7 gap-0 mb-1">
                    <template x-for="name in dayNames" :key="name">
                        <div class="text-center text-xs font-medium text-gray-500 dark:text-gray-400 py-1"
                             x-text="name"></div>
                    </template>
                </div>

                {{-- Calendar grid. From the breakpoint up the arrows, PageUp/PageDown
                     and Home/End walk it (calendar.js); a phone taps. --}}
                <div class="grid grid-cols-7 gap-0" role="grid" @keydown="wide && onGridKey($event)">
                    <template x-for="(cell, idx) in days" :key="idx">
                        <button
                                type="button"
                                @click="cell.current && selectDate(cell.date)"
                                :data-testid="cell.current ? 'form-datetime-{{ $field->getStatePath() }}-day-' + cell.day : null"
                                :disabled="!cell.current || isDisabled(cell.date)"
                                :class="{
                                'text-gray-900 dark:text-white': cell.current && !isDisabled(cell.date),
                                'text-gray-300 dark:text-gray-600': !cell.current,
                                'opacity-40 cursor-not-allowed': cell.current && isDisabled(cell.date),
                                'bg-primary-600 text-white hover:bg-primary-700': isSelected(cell.date),
                                'ring-1 ring-primary-500': isToday(cell.date) && !isSelected(cell.date),
                                'hover:bg-gray-100 dark:hover:bg-gray-700': cell.current && !isDisabled(cell.date) && !isSelected(cell.date),
                                'w-8 h-8 rounded-full': ! wide,
                                'w-9 h-9 rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-1 dark:focus-visible:ring-offset-gray-800': wide,
                                'font-semibold': wide && isToday(cell.date),
                            }"
                                :data-date="cell.date"
                                :tabindex="wide ? (cell.date && cell.date === focusDay ? 0 : -1) : null"
                                class="text-sm flex items-center justify-center transition-colors duration-150"
                                x-text="cell.day"
                        ></button>
                    </template>
                </div>
                </div>

                @include('wire-forms::partials.calendar.months', ['testId' => $testId])
                </div>
            @endif

            @if($hasTime)
                <template x-if="! wide">
                {{-- Time selector --}}
                <div @class(['flex items-center justify-center gap-2 pt-3 border-t border-gray-200 dark:border-gray-600 mt-3' => $hasDate, 'flex items-center justify-center gap-2' => !$hasDate])>
                    {{-- Hours --}}
                    <div class="flex flex-col items-center">
                        <button type="button" @click="adjustHours(1)" data-testid="form-datetime-{{ $field->getStatePath() }}-hours-up" aria-label="Hours up"
                                class="p-0.5 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition-colors duration-150">
                            {!! icon('chevron-up', 'w-4 h-4', 'h-4 w-4') !!}
                        </button>
                        <span class="w-8 text-center text-sm font-medium text-gray-900 dark:text-white tabular-nums"
                              x-text="String(hours).padStart(2, '0')"></span>
                        <button type="button" @click="adjustHours(-1)" data-testid="form-datetime-{{ $field->getStatePath() }}-hours-down" aria-label="Hours down"
                                class="p-0.5 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition-colors duration-150">
                            {!! icon('chevron-down', 'w-4 h-4', 'h-4 w-4') !!}
                        </button>
                    </div>

                    <span class="text-gray-500 dark:text-gray-400 text-sm font-medium">:</span>

                    {{-- Minutes --}}
                    <div class="flex flex-col items-center">
                        <button type="button" @click="adjustMinutes(1)" data-testid="form-datetime-{{ $field->getStatePath() }}-minutes-up" aria-label="Minutes up"
                                class="p-0.5 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition-colors duration-150">
                            {!! icon('chevron-up', 'w-4 h-4', 'h-4 w-4') !!}
                        </button>
                        <span class="w-8 text-center text-sm font-medium text-gray-900 dark:text-white tabular-nums"
                              x-text="String(minutes).padStart(2, '0')"></span>
                        <button type="button" @click="adjustMinutes(-1)" data-testid="form-datetime-{{ $field->getStatePath() }}-minutes-down" aria-label="Minutes down"
                                class="p-0.5 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition-colors duration-150">
                            {!! icon('chevron-down', 'w-4 h-4', 'h-4 w-4') !!}
                        </button>
                    </div>

                    @if($hasSeconds)
                        <span class="text-gray-500 dark:text-gray-400 text-sm font-medium">:</span>

                        {{-- Seconds --}}
                        <div class="flex flex-col items-center">
                            <button type="button" @click="adjustSeconds(1)" data-testid="form-datetime-{{ $field->getStatePath() }}-seconds-up" aria-label="Seconds up"
                                    class="p-0.5 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition-colors duration-150">
                                {!! icon('chevron-up', 'w-4 h-4', 'h-4 w-4') !!}
                            </button>
                            <span class="w-8 text-center text-sm font-medium text-gray-900 dark:text-white tabular-nums"
                                  x-text="String(seconds).padStart(2, '0')"></span>
                            <button type="button" @click="adjustSeconds(-1)" data-testid="form-datetime-{{ $field->getStatePath() }}-seconds-down" aria-label="Seconds down"
                                    class="p-0.5 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition-colors duration-150">
                                {!! icon('chevron-down', 'w-4 h-4', 'h-4 w-4') !!}
                            </button>
                        </div>
                    @endif
                </div>
                </template>
                <template x-if="wide">
                    <div @class(['pl-4 border-l border-gray-200 dark:border-gray-600' => $hasDate])>
                        @include('wire-forms::partials.clock-dial', ['testId' => $testId, 'footer' => false])
                    </div>
                </template>
            @endif
            </div>

            {{-- Footer --}}
            <template x-if="! wide">
            <div class="flex items-center justify-between mt-3 pt-3 border-t border-gray-200 dark:border-gray-600">
                <button type="button" @click="clear(); open = false" data-testid="form-datetime-{{ $field->getStatePath() }}-clear"
                        class="text-xs text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition-colors duration-150">
                    {{ __('Clear') }}
                </button>
                @if($hasDate && $hasTime)
                    <button type="button" @click="open = false" data-testid="form-datetime-{{ $field->getStatePath() }}-done"
                            class="text-xs font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300 transition-colors duration-150">
                        {{ __('Done') }}
                    </button>
                @endif
            </div>
            </template>
            <template x-if="wide">
                <div class="flex items-center justify-between gap-6 mt-3 pt-3 border-t border-gray-200 dark:border-gray-600">
                    <button type="button" @click="pickNow()" :disabled="! canPickNow" data-testid="{{ $testId }}-now"
                            class="text-xs font-medium text-primary-700 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-300 disabled:opacity-40 disabled:cursor-not-allowed transition-colors duration-150">
                        {{ $hasTime ? __('wire-forms::fields.picker.now') : __('wire-forms::fields.picker.today') }}
                    </button>
                    <div class="flex items-center gap-4">
                        <button type="button" @click="clear(); open = false" data-testid="{{ $testId }}-clear"
                                class="text-xs text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition-colors duration-150">
                            {{ __('wire-forms::fields.picker.clear') }}
                        </button>
                        @if($hasDate && $hasTime)
                            <button type="button" @click="closeToTrigger()" data-testid="{{ $testId }}-done"
                                    class="rounded-md bg-primary-600 px-3 py-1 text-xs font-medium text-white hover:bg-primary-700 transition-colors duration-150">
                                {{ __('wire-forms::fields.picker.done') }}
                            </button>
                        @endif
                    </div>
                </div>
            </template>
        </div>
        </div>
        </template>
    </div>
@endif

@include('wire-forms::partials.field-wrapper-end')
