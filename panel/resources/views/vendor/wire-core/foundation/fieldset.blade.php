<fieldset
    @wireEl('fieldset') {{ $attributes->class(['rounded-xl border border-gray-200 dark:border-gray-700 px-4 py-4 sm:px-6']) }}>
    @if($legend)
        <legend class="px-1 text-sm font-medium text-gray-700 dark:text-gray-300">{{ $legend }}</legend>
    @endif
    {{ $slot }}
</fieldset>
