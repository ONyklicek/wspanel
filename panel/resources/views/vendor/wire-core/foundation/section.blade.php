<div
    @wireEl('section') {{ $attributes->class(['rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800']) }}>
    @if($heading || $description)
        <div class="border-b border-gray-200 dark:border-gray-700 px-4 py-4 sm:px-6">
            @if($heading)
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ $heading }}</h3>
            @endif
            @if($description)
                <p @class(['text-sm text-gray-500 dark:text-gray-400', 'mt-1' => $heading])>{{ $description }}</p>
            @endif
        </div>
    @endif
    <div class="px-4 py-4 sm:px-6">
        {{ $slot }}
    </div>
</div>
