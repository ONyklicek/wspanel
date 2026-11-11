<div>
    <div class="grid gap-6 lg:grid-cols-[14rem_1fr]">
        <nav class="flex flex-col gap-1 text-sm">
            @foreach (\App\Support\Lang::values(\App\Livewire\Pages\HelpPage::PAGES) as $slug => $title)
                <a href="{{ \App\Livewire\Pages\HelpPage::url(parameters: ['doc' => $slug]) }}"
                   @class(['rounded-lg px-3 py-2', 'bg-primary-50 font-medium text-primary-700 dark:bg-primary-400/10 dark:text-primary-300' => $doc === $slug, 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5' => $doc !== $slug])>{{ $title }}</a>
            @endforeach
        </nav>
        <x-wire::section>
            @if (app()->getLocale() !== 'cs')
                <x-wire::callout color="info" class="mb-4" :heading="__('Dokumentace je zatím jen česky')">{{ __('Celé rozhraní panelu je přeložené; tyto stránky ne. Překlad stránky v prohlížeči (např. Chrome → Přeložit) je čte dobře.') }}</x-wire::callout>
            @endif
            <article class="wsp-doc">{{ $this->renderedDoc() }}</article>
        </x-wire::section>
    </div>
</div>
