<x-filament-widgets::widget>
    <x-filament::section heading="Logy" description="Posledních několik řádků. Celé soubory najdete přes SFTP ve složce webu.">
        @if ($this->sources() === [])
            <p class="text-sm text-gray-500">Pro tento web nejsou logy k dispozici.</p>
        @else
            <div class="mb-3 flex flex-wrap items-center gap-3">
                <x-filament::input.wrapper class="w-72">
                    <x-filament::input.select wire:model.live="source">
                        @foreach ($this->sources() as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                <x-filament::input.wrapper class="w-40">
                    <x-filament::input.select wire:model.live="lines">
                        @foreach ([100, 200, 500, 1000] as $n)
                            <option value="{{ $n }}">{{ $n }} řádků</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                <x-filament::button wire:click="load" icon="heroicon-o-arrow-path" color="gray" size="sm">
                    {{ $loaded ? 'Obnovit' : 'Načíst' }}
                </x-filament::button>
                @if ($file)
                    <span class="font-mono text-xs text-gray-500">{{ $file }}</span>
                @endif
            </div>
            @if ($error)
                <p class="text-sm text-danger-600">{{ $error }}</p>
            @elseif ($loaded)
                <pre class="max-h-[32rem] overflow-auto whitespace-pre-wrap break-all rounded-lg bg-gray-950 p-4 font-mono text-xs leading-relaxed text-gray-100">{{ $text !== '' ? $text : 'Log je prázdný (zatím nic nezapsáno).' }}</pre>
            @endif
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
