<div class="space-y-4" @if ($follow) wire:poll.5s="load" @endif>
    <x-wire::section :heading="__('Logy serveru')" :description="__('Záznamy služeb serveru z journalu, nejnovější dole. Logy jednotlivých webů jsou na jejich detailu.')">
        <div class="mb-3 flex flex-wrap items-center gap-3">
            <select wire:model.live="source" aria-label="{{ __('Zdroj') }}" class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5">
                @foreach (\App\Livewire\Pages\ServerLogsPage::SOURCES as $key => $label)
                    <option value="{{ $key }}">{{ __($label) }}</option>
                @endforeach
            </select>
            <select wire:model.live="since" aria-label="{{ __('Období') }}" class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5">
                @foreach (['15m' => __('15 minut'), '1h' => __('hodina'), '24h' => __('24 hodin'), '7d' => __('7 dní')] as $key => $label)
                    <option value="{{ $key }}">{{ __('posledních') }} {{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="priority" aria-label="{{ __('Závažnost') }}" class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5">
                <option value="all">{{ __('vše') }}</option>
                <option value="warning">{{ __('varování a chyby') }}</option>
                <option value="err">{{ __('jen chyby') }}</option>
            </select>
            <input type="search" wire:model.live.debounce.500ms="filter" maxlength="200" placeholder="{{ __('Hledat (adresa, ID zprávy, IP…)') }}"
                   class="min-w-56 flex-1 rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5" />
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model.live="follow" class="rounded border-gray-300 dark:border-white/10 dark:bg-white/5" />
                {{ __('Sledovat živě') }}
            </label>
            <x-wire::button wire:click="load" color="gray" outlined icon="outline:arrow-path">{{ __('Obnovit') }}</x-wire::button>
        </div>
        @if ($error)
            <p class="text-sm text-red-600">{{ $error }}</p>
        @else
            <p class="mb-2 text-xs text-gray-500">{{ trans_choice('{0} žádný záznam|{1} :count záznam|[2,4] :count záznamy|[5,*] :count záznamů', count($lines)) }}{{ count($lines) >= 1000 ? __(' (zobrazeno posledních 1000)') : '' }}</p>
            @include('livewire.sites.log-text', ['text' => $lines !== [] ? implode("\n", $lines) : __('V tomto období nic.')])
        @endif
    </x-wire::section>
</div>
