<button
    type="button"
    wire:click="resetTableFilters"
    data-testid="table-filter-reset" @wireEl('table-filter-reset')
    class="inline-flex items-center gap-2 text-sm font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300"
>
    {!! icon('outline:arrow-path', 'w-4 h-4') !!}
    {{ __('wire-table::messages.filter_reset') }}
</button>
