@include('wire-core::partials.floating-assets')

<div
    @wireEl('tabs') x-data="wireTabs({{ (int) $active }})" {{ $attributes }}>
    <div class="flex flex-wrap gap-1 border-b border-gray-200 dark:border-gray-700" role="tablist">
        <template x-for="(label, i) in tabs" :key="i">
            <button
                type="button"
                role="tab"
                @click="active = i"
                x-text="label"
                :aria-selected="active === i"
                :class="active === i
                    ? 'border-primary-500 text-primary-600 dark:text-primary-400'
                    : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                class="-mb-px border-b-2 px-4 py-2 text-sm font-medium focus:outline-none"
            ></button>
        </template>
    </div>
    <div class="pt-4">
        {{ $slot }}
    </div>
</div>
