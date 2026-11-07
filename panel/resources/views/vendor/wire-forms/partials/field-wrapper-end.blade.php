    @if($field->getHelperText())
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            {{ $field->getHelperText() }}
        </p>
    @endif

    @error($field->getStatePath())
        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
    @enderror
</div>
