{{-- The desktop calendar header, Fiori-style: the arrows page a month, a year or
     a page of years by view, and the month name and the year are buttons that
     open their own grids — so a far date is three clicks, not hundreds.

     Expects: $testId (the field's test-id prefix). Driven by `calendar.js`. --}}
<div class="flex items-center justify-between gap-1 mb-2">
    <button type="button" @click="navPrev()" :disabled="! canNavPrev"
            data-testid="{{ $testId }}-prev-month" aria-label="{{ __('wire-forms::fields.picker.previous') }}"
            class="p-1.5 rounded-md text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-transparent transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
        {!! icon('chevron-left', 'w-4 h-4', 'h-4 w-4') !!}
    </button>

    <div class="flex items-center gap-0.5">
        <template x-if="view === 'days'">
            <button type="button" @click="showMonths()" x-text="monthName(currentMonth)"
                    data-testid="{{ $testId }}-month-toggle" aria-label="{{ __('wire-forms::fields.picker.choose_month') }}"
                    class="px-2 py-1 rounded-md text-sm font-semibold capitalize text-gray-900 dark:text-white hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"></button>
        </template>
        <template x-if="view !== 'years'">
            <button type="button" @click="showYears()" x-text="currentYear"
                    data-testid="{{ $testId }}-year-toggle" aria-label="{{ __('wire-forms::fields.picker.choose_year') }}"
                    class="px-2 py-1 rounded-md text-sm font-semibold tabular-nums text-gray-900 dark:text-white hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"></button>
        </template>
        <template x-if="view === 'years'">
            <span x-text="yearPageLabel" class="px-2 py-1 text-sm font-semibold tabular-nums text-gray-900 dark:text-white"></span>
        </template>
    </div>

    <button type="button" @click="navNext()" :disabled="! canNavNext"
            data-testid="{{ $testId }}-next-month" aria-label="{{ __('wire-forms::fields.picker.next') }}"
            class="p-1.5 rounded-md text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-transparent transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
        {!! icon('chevron-right', 'w-4 h-4', 'h-4 w-4') !!}
    </button>
</div>
