@props(['heading', 'description' => null])
{{-- A table under its own heading: the table is the card, no second frame around it. --}}
<section {{ $attributes->class(['space-y-3']) }}>
    <div>
        <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ $heading }}</h3>
        @if ($description)
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>
        @endif
    </div>
    {{ $slot }}
</section>
