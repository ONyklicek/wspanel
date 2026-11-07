@include('wire-core::partials.floating-assets')

<div
    @wireEl('wizard') x-data="wireWizard({{ (int) $current }})" {{ $attributes }}>
    <ol class="mb-6 flex flex-wrap items-center gap-x-4 gap-y-2">
        <template x-for="(label, i) in steps" :key="i">
            <li class="flex items-center gap-2">
                <span
                    x-text="i + 1"
                    :class="current >= i ? 'bg-primary-600 text-white' : 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300'"
                    class="flex h-7 w-7 items-center justify-center rounded-full text-xs font-semibold"
                ></span>
                <span
                    x-text="label"
                    :class="current === i ? 'font-medium text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400'"
                    class="text-sm"
                ></span>
            </li>
        </template>
    </ol>

    <div>{{ $slot }}</div>

    <div class="mt-6 flex items-center justify-between">
        <button
            type="button"
            @click="prev()"
            :disabled="isFirst"
            :class="isFirst ? 'opacity-50 cursor-not-allowed' : ''"
            class="rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
        >{{ __('Back') }}</button>
        <button
            type="button"
            @click="next()"
            x-show="!isLast"
            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white"
        >{{ __('Next') }}</button>
    </div>
</div>
