<x-filament-panels::page>
    <div class="grid gap-6 lg:grid-cols-[14rem_1fr]">
        <nav class="flex flex-col gap-1 text-sm">
            @foreach (\App\Filament\Pages\Help::PAGES as $slug => $title)
                <a href="{{ \App\Filament\Pages\Help::getUrl(['doc' => $slug]) }}"
                   @class(['rounded-lg px-3 py-2', 'bg-primary-50 font-medium text-primary-700 dark:bg-primary-400/10 dark:text-primary-400' => $doc === $slug, 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5' => $doc !== $slug])>{{ $title }}</a>
            @endforeach
        </nav>
        <x-filament::section>
            <article class="wsp-doc">{{ $this->renderedDoc() }}</article>
        </x-filament::section>
    </div>
</x-filament-panels::page>
