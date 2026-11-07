{{-- The clock dial, drawn the way SAP's TimePickerClock is: two big buttons
     read the time and say which part the face sets; the rim carries a dot for
     every value that can be picked, the numbers sit just inside it, and the
     chosen value is a rim marker and a filled bubble rather than a hand. The
     hours face has 1–12 outside and 13–23 and 00 inside; lifting off an hour
     turns it to the minutes. With a footer, only OK writes the value.

     The labels are not buttons: the face as a whole takes the pointer (a drag
     sweeps the hand) and the keyboard (a slider: arrows step, Enter moves on).

     Expects: $testId, and optionally $footer (false where the host panel has
     its own footer — DateTimePicker's). Driven by `clock-dial.js`. --}}
@php
    $unitLabels = [
        'hours' => __('wire-forms::fields.picker.hours'),
        'minutes' => __('wire-forms::fields.picker.minutes'),
        'seconds' => __('wire-forms::fields.picker.seconds'),
    ];
@endphp
<div class="w-72 p-2">
    <div class="flex items-center justify-center gap-1.5">
        <template x-for="(unit, index) in dialUnits" :key="unit">
            <div class="flex items-center gap-1.5">
                <span x-show="index > 0" class="text-2xl text-gray-500 dark:text-gray-400" aria-hidden="true">:</span>
                <button type="button"
                        @click="showDialUnit(unit)"
                        :aria-pressed="dialUnit === unit ? 'true' : 'false'"
                        :aria-label="@js($unitLabels)[unit]"
                        :data-testid="'{{ $testId }}-dial-' + unit"
                        :class="dialUnit === unit
                            ? 'bg-primary-50 text-primary-700 dark:bg-primary-500/15 dark:text-primary-300'
                            : 'text-gray-900 hover:bg-gray-100 dark:text-white dark:hover:bg-gray-700'"
                        class="h-14 w-[4.5rem] rounded-lg text-3xl font-medium tabular-nums transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                        x-text="pad(draft[unit])"></button>
            </div>
        </template>
    </div>

    <div x-ref="dial"
         role="slider"
         tabindex="0"
         :aria-label="@js($unitLabels)[dialUnit]"
         :aria-valuenow="draft[dialUnit]"
         :aria-valuetext="dialValueText"
         aria-valuemin="0"
         :aria-valuemax="dialUnit === 'hours' ? 23 : 59"
         data-testid="{{ $testId }}-dial"
         @pointerdown.prevent="dialDown($event)"
         @pointermove="dialMove($event)"
         @pointerup="dialUp()"
         @pointercancel="dialUp()"
         @pointerleave="dialLeave()"
         @keydown="dialKey($event)"
         class="relative mx-auto mt-3 h-64 w-64 cursor-pointer touch-none select-none rounded-full bg-gray-100 dark:bg-gray-900/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
    >
        {{-- What the face sets, in its middle — SAP's dial label. --}}
        <span class="pointer-events-none absolute inset-0 flex items-center justify-center text-xs font-medium text-gray-500 dark:text-gray-400" x-text="@js($unitLabels)[dialUnit]"></span>

        {{-- The rim: a dot under every number, a smaller one at each value
             between them that can be picked. --}}
        <template x-for="tick in dialTicks" :key="tick.key">
            <span :style="'left: ' + tick.x + 'px; top: ' + tick.y + 'px'"
                  :class="[tick.big ? 'h-1.5 w-1.5' : 'h-1 w-1', tick.muted ? 'bg-gray-300 dark:bg-gray-600' : 'bg-gray-400 dark:bg-gray-500']"
                  class="pointer-events-none absolute -translate-x-1/2 -translate-y-1/2 rounded-full"></span>
        </template>

        <template x-for="item in dialLabels" :key="dialUnit + '-' + item.value">
            <span
                :style="'left: ' + item.x + 'px; top: ' + item.y + 'px'"
                :data-testid="'{{ $testId }}-dial-' + dialUnit + '-' + item.value"
                :data-disabled="item.disabled ? 'true' : 'false'"
                :class="{
                    'text-gray-400 dark:text-gray-500': item.disabled,
                    'text-gray-900 font-semibold dark:text-white': ! item.disabled && ! item.inner,
                    'text-gray-600 dark:text-gray-300': ! item.disabled && item.inner,
                    'h-8 w-8 text-sm': ! item.inner,
                    'h-7 w-7 text-xs': item.inner,
                }"
                class="pointer-events-none absolute flex -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full tabular-nums"
                x-text="item.label"
            ></span>
        </template>

        {{-- The value under the pointer, before the click. --}}
        <template x-if="dialHovered">
            <div>
                <span class="pointer-events-none absolute h-2.5 w-1 rounded-full border border-primary-300 bg-primary-100 dark:border-primary-400/60 dark:bg-primary-500/30"
                      :style="'left: ' + dialHovered.mx + 'px; top: ' + dialHovered.my + 'px; transform: translate(-50%, -50%) rotate(' + dialHovered.angle + 'deg)'"></span>
                <span :class="dialHovered.inner ? 'h-7 w-7 text-xs' : 'h-8 w-8 text-sm'"
                      class="pointer-events-none absolute flex -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-primary-300 bg-primary-50 font-semibold text-primary-800 tabular-nums dark:border-primary-400/60 dark:bg-primary-500/20 dark:text-primary-100"
                      :style="'left: ' + dialHovered.x + 'px; top: ' + dialHovered.y + 'px'"
                      x-text="dialHovered.label"></span>
            </div>
        </template>

        {{-- The chosen value: a marker on the rim and a bubble that reads it,
             even between two numbers. --}}
        <template x-if="dialSelected">
            <div>
                <span class="pointer-events-none absolute h-2.5 w-1 rounded-full bg-primary-600 dark:bg-primary-400"
                      :style="'left: ' + dialSelected.mx + 'px; top: ' + dialSelected.my + 'px; transform: translate(-50%, -50%) rotate(' + dialSelected.angle + 'deg)'"></span>
                <span :class="dialSelected.inner ? 'h-7 w-7 text-xs' : 'h-8 w-8 text-sm'"
                      data-testid="{{ $testId }}-dial-selected"
                      class="pointer-events-none absolute flex -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-primary-600 font-semibold text-white tabular-nums dark:bg-primary-500"
                      :style="'left: ' + dialSelected.x + 'px; top: ' + dialSelected.y + 'px'"
                      x-text="dialSelected.label"></span>
            </div>
        </template>
    </div>

    @if($footer ?? true)
    <div class="mt-3 flex items-center gap-2 border-t border-gray-200 pt-3 dark:border-gray-600">
        <button type="button" @click="pickNowOnDial()" data-testid="{{ $testId }}-now"
                class="rounded-md px-2 py-1 text-xs font-medium text-primary-700 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-300 transition-colors duration-150">
            {{ __('wire-forms::fields.picker.now') }}
        </button>
        <button type="button" @click="clear(); closeToTrigger()" data-testid="{{ $testId }}-clear"
                class="rounded-md px-2 py-1 text-xs text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition-colors duration-150">
            {{ __('wire-forms::fields.picker.clear') }}
        </button>
        <button type="button" @click="closeToTrigger()" data-testid="{{ $testId }}-cancel"
                class="ml-auto rounded-md border border-gray-300 px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700 transition-colors duration-150">
            {{ __('wire-forms::fields.picker.cancel') }}
        </button>
        <button type="button" @click="confirmDial()" data-testid="{{ $testId }}-ok"
                class="rounded-md bg-primary-600 px-4 py-1 text-xs font-medium text-white hover:bg-primary-700 transition-colors duration-150">
            OK
        </button>
    </div>
    @endif
</div>
