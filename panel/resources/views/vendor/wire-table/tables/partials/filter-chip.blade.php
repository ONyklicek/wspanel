{{-- One active-filter indicator chip with a remove button.
     Variables: $name, $label, $removeMethod, $keyPrefix, $testidPrefix --}}
<span
        wire:key="{{ $keyPrefix }}-{{ $name }}"
        data-testid="{{ $testidPrefix }}-{{ $name }}"
        class="inline-flex items-center gap-1.5 rounded-full bg-primary-50 dark:bg-primary-500/10 py-1 pl-3 pr-1.5 text-xs font-medium text-primary-700 dark:text-primary-300 ring-1 ring-inset ring-primary-600/20 dark:ring-primary-400/20"
>
    {{ $label }}
    <button
            type="button"
            wire:click="{{ $removeMethod }}('{{ $name }}')"
            data-testid="{{ $testidPrefix }}-remove-{{ $name }}"
            title="{{ __('wire-table::messages.filter_remove') }}"
            class="inline-flex items-center justify-center rounded-full p-0.5 text-primary-600 dark:text-primary-400 hover:bg-primary-100 dark:hover:bg-primary-500/20 hover:text-primary-800 dark:hover:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500"
    >
        <span class="sr-only">{{ __('wire-table::messages.filter_remove') }}: {{ $label }}</span>
        {!! icon('outline:x-mark', 'h-3.5 w-3.5') !!}
    </button>
</span>
