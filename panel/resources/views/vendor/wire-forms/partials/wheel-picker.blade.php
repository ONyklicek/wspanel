{{-- The mobile wheel picker (->touchOnMobile()), shown below the mobile
     breakpoint in place of the desktop panel: hours and minutes for a time,
     day / month / year for a date, a day column beside the clock for a
     datetime. Controller: `wireWheelPicker`
     (packages/forms/resources/js/fields/wheel-picker.js), which builds the
     columns; only per-instance config is markup. `state` is built here because
     `$wire.entangle` is an Alpine magic, in scope only inside x-data.

     The columns are plain scroll-snap scrollers, so the coasting and the snap
     are the platform's own physics. The sheet reuses the canonical sheet
     chrome: the grabber (drag down to dismiss) and a focus trap.

     Expects: $field, $wireModifier and $sheetBp. --}}
@php
    use NyonCode\WireCore\Foundation\Support\MobileSheet;

    $mode = $field->getMode();
    $wheelId = $field->mobileControlIdFor($field->getId());
    $testId = 'form-'.$field->getStatePath().'-wheel';
    $wheelSlots = $mode === 'time' ? array_keys($field->getSlotOptions()) : null;
    // The wheel's x-data carries its bounds, and a bound can follow other state
    // (a DateRangePicker end is bounded by the other end). Alpine meets a
    // changed x-data by reconciling the old object onto the new one, which
    // writes the fresh `$wire.entangle` placeholder into the bound property —
    // and from there to the server as the field's value. A key that changes
    // with the bounds makes a changed wheel a new element instead.
    $wheelKey = md5((string) json_encode([$field->getMinDate(), $field->getMaxDate(), $field->getDisabledDates(), $wheelSlots]));
@endphp
<div
    wire:key="wheel-{{ $field->getStatePath() }}-{{ $wheelKey }}"
    x-data="wireWheelPicker({
        state: $wire.entangle('{{ $field->getWireModelAttribute() }}'){{ $wireModifier ? '.' . $wireModifier : '' }},
        kind: @js($mode),
        {{-- A time's slots carry its bounds; a datetime's clock is the whole day
             at the interval, its bounds checked per day in the browser. --}}
        slots: @js($wheelSlots),
        interval: @js($field->getSlotInterval() ?? 1),
        min: @js($mode === 'time' ? null : $field->getMinDate()),
        max: @js($mode === 'time' ? null : $field->getMaxDate()),
        disabledDates: @js($mode === 'time' ? [] : $field->getDisabledDates()),
        hasSeconds: @js($field->hasSeconds()),
        labels: @js([
            'hours' => __('wire-forms::fields.wheel.hours'),
            'minutes' => __('wire-forms::fields.wheel.minutes'),
            'day' => __('wire-forms::fields.wheel.day'),
            'month' => __('wire-forms::fields.wheel.month'),
            'year' => __('wire-forms::fields.wheel.year'),
            'today' => __('wire-forms::fields.wheel.today'),
        ]),
    })"
    class="{{ MobileSheet::showBelow($sheetBp) }}"
    data-testid="{{ $testId }}"
>
    {{-- Trigger: reads as the field, opens the sheet. A button, so the phone
         raises no keyboard. 16px, so iOS does not zoom on tap. --}}
    <button
        type="button"
        id="{{ $wheelId }}"
        @click="openSheet()"
        aria-haspopup="dialog"
        :aria-expanded="open ? 'true' : 'false'"
        @if($field->getLabel()) aria-label="{{ $field->getLabel() }}" @endif
        @if($field->isDisabled() || $field->isReadOnly()) disabled @endif
        data-testid="{{ $testId }}-trigger"
        @class([
            'flex w-full items-center justify-between rounded-md border border-gray-300 bg-white px-3 py-2 text-left text-base shadow-sm',
            'dark:bg-gray-800 dark:border-gray-600 dark:text-white',
            'focus:border-primary-500 focus:ring-1 focus:ring-primary-500 disabled:opacity-50',
            'border-red-500' => $errors->has($field->getStatePath()),
        ])
    >
        <span x-text="display || @js($field->getPlaceholder() ?? '')" :class="{ 'text-gray-500 dark:text-gray-400': ! display }" class="tabular-nums"></span>
        {!! icon($mode === 'time' ? 'outline:clock' : 'outline:calendar', 'h-5 w-5', 'text-gray-400') !!}
    </button>

    <template x-teleport="body">
        <div>
            <div
                x-show="open"
                x-cloak
                x-transition.opacity.duration.150ms
                @click="cancel()"
                class="fixed inset-0 z-50 bg-gray-500/60 dark:bg-gray-900/70"
            ></div>

            <div
                x-ref="sheet"
                x-show="open"
                x-cloak
                x-trap.noscroll.noautofocus="open"
                role="dialog"
                aria-modal="true"
                aria-labelledby="{{ $wheelId }}-title"
                @keydown.escape="cancel()"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="translate-y-full"
                x-transition:enter-end="translate-y-0"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="translate-y-0"
                x-transition:leave-end="translate-y-full"
                class="fixed border inset-x-0 bottom-0 z-50 rounded-t-2xl bg-white pb-[env(safe-area-inset-bottom)] shadow-2xl dark:bg-gray-800"
                data-testid="{{ $testId }}-sheet"
                style="display: none;"
            >
                @include('wire-core::partials.sheet-grabber', ['dismiss' => 'cancel()', 'breakpoint' => $sheetBp])

                {{-- Header: the iOS sheet pattern — dismiss left, title, commit right. --}}
                <div class="flex items-center justify-between border-b border-gray-200 px-2 dark:border-gray-700">
                    <button type="button" @click="cancel()" class="min-h-11 px-3 text-base text-gray-600 dark:text-gray-300" data-testid="{{ $testId }}-cancel">
                        {{ __('wire-forms::fields.wheel.cancel') }}
                    </button>
                    <span id="{{ $wheelId }}-title" class="text-base font-semibold text-gray-900 dark:text-white">{{ $field->getLabel() }}</span>
                    <button type="button" @click="done()" class="min-h-11 px-3 text-base font-semibold text-primary-600 dark:text-primary-400" data-testid="{{ $testId }}-done">
                        {{ __('wire-forms::fields.wheel.done') }}
                    </button>
                </div>

                {{-- The drum. The band marks the row a column rests on; the mask
                     fades the rows above and below it into the sheet. --}}
                <div class="relative mx-auto flex max-w-sm justify-center gap-1 px-4 py-3">
                    <div class="pointer-events-none absolute inset-x-6 top-[calc(0.75rem+88px)] h-11 rounded-lg bg-gray-100 dark:bg-gray-700/60" aria-hidden="true"></div>

                    <template x-for="(column, position) in columns" :key="column.key">
                        <div class="relative z-10 flex items-center">
                            {{-- The clock's separator, between the hour and the minute. --}}
                            <div x-show="column.key === 'minute'" class="flex h-[220px] items-center pr-2 text-xl font-semibold text-gray-400" aria-hidden="true">:</div>
                            <div
                                :data-wheel="column.key"
                                @scroll.passive="onScroll(column.key)"
                                @scrollend="settle(column.key)"
                                @keydown="onKey(column.key, $event)"
                                role="spinbutton"
                                tabindex="0"
                                :aria-label="column.label"
                                :aria-valuetext="labelOf(column.key)"
                                :data-testid="'{{ $testId }}-' + column.key"
                                :style="`width: ${column.width}rem`"
                                class="h-[220px] snap-y snap-mandatory overflow-y-scroll overscroll-contain py-[88px] [perspective:600px] [scrollbar-width:none] [-webkit-overflow-scrolling:touch] [mask-image:linear-gradient(to_bottom,transparent,black_35%,black_65%,transparent)] focus:outline-none [&::-webkit-scrollbar]:hidden"
                            >
                                <template x-for="(item, index) in column.items" :key="item.value">
                                    <div
                                        data-wheel-row
                                        @click="scrollTo(column.key, index)"
                                        x-text="item.label"
                                        :class="rowValid(column.key, item.value) ? 'text-gray-900 dark:text-white' : 'text-gray-300 dark:text-gray-600'"
                                        class="flex h-11 snap-center items-center justify-center truncate px-1 text-xl tabular-nums will-change-transform"
                                    ></div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="flex justify-center border-t border-gray-200 px-4 py-2 dark:border-gray-700">
                    <button type="button" @click="clear()" class="min-h-11 px-3 text-base text-gray-500 dark:text-gray-400" data-testid="{{ $testId }}-clear">
                        {{ __('wire-forms::fields.wheel.clear') }}
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>
