<div>
    <x-wire::section heading="Logy" description="Posledních několik řádků. Celé soubory najdete přes SFTP ve složce webu.">
        @if ($this->sources() === [])
            <p class="text-sm text-gray-500">Pro tento web nejsou logy k dispozici.</p>
        @else
            <div class="mb-3 flex flex-wrap items-center gap-3">
                <select wire:model.live="source" class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5">
                    @foreach ($this->sources() as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="lines" class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5">
                    @foreach ([100, 200, 500, 1000] as $n)
                        <option value="{{ $n }}">{{ $n }} řádků</option>
                    @endforeach
                </select>
                <x-wire::button wire:click="load" color="gray" outlined icon="outline:arrow-path">{{ $loaded ? 'Obnovit' : 'Načíst' }}</x-wire::button>
                @if ($file)
                    <span class="font-mono text-xs text-gray-500">{{ $file }}</span>
                @endif
            </div>
            @if ($error)
                <p class="text-sm text-red-600">{{ $error }}</p>
            @elseif ($loaded)
                @include('livewire.sites.log-text', ['text' => $text !== '' ? $text : 'Log je prázdný (zatím nic nezapsáno).'])
            @endif
        @endif
    </x-wire::section>
</div>
