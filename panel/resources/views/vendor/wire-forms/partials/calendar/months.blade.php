{{-- The month grid and the year grid that replace the days while `view` says
     so — one template for both, the items coming from `calendar.js`
     (`pickerItems`). Sized like the day grid it stands in for, so the panel
     does not jump.

     Expects: $testId, and optionally $width (a Tailwind width class). --}}
<template x-if="view !== 'days'">
    <div :class="view === 'months' ? 'grid-rows-3' : 'grid-rows-5'" class="grid grid-cols-4 gap-1 h-[15.25rem] {{ $width ?? 'w-[15.75rem]' }}" role="grid" @keydown="onListKey($event, view)">
        <template x-for="item in pickerItems" :key="view + item.value">
            <button type="button"
                    @click="pickItem(item.value)"
                    :disabled="item.disabled"
                    :data-month="view === 'months' ? item.value : null"
                    :data-year="view === 'years' ? item.value : null"
                    :tabindex="item.focused ? 0 : -1"
                    :data-testid="'{{ $testId }}-' + item.testId"
                    :class="item.anchor ? 'bg-primary-600 text-white hover:bg-primary-700' : 'text-gray-900 dark:text-white hover:bg-gray-100 dark:hover:bg-gray-700'"
                    class="rounded-md text-sm capitalize tabular-nums disabled:opacity-40 disabled:cursor-not-allowed transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                    x-text="item.label"></button>
        </template>
    </div>
</template>
