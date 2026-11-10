@php($origin = ['builtin' => [__('vestavěný'), 'gray'], 'catalog' => [__('z katalogu WSPanelu'), 'primary'], 'local' => [__('vlastní'), 'warning']])
<div class="space-y-6" wire:init="loadCatalog">
    @if ($error)
        <x-wire::callout color="danger" :heading="__('Agent neodpovídá')">{{ $error }}</x-wire::callout>
    @endif

    <x-wire::section :heading="__('Nainstalované')" :description="__('Hry a jejich varianty, které nabízí formulář nového herního serveru. Balíček je popis (odkud se server stáhne, jak se spouští), nic se z něj nespouští.')">
        <ul class="divide-y divide-gray-100 dark:divide-white/5">
            @foreach ($packages as $p)
                <li wire:key="pkg-{{ $p['id'] }}" class="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">{{ $p['name'] }}</span>
                            <span class="font-mono text-xs text-gray-500">{{ $p['version'] }}</span>
                            <x-wire::badge :color="$origin[$p['origin']][1] ?? 'gray'">{{ $origin[$p['origin']][0] ?? $p['origin'] }}</x-wire::badge>
                        </div>
                        @if ($p['description'] ?? '')
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $p['description'] }}</p>
                        @endif
                        <p class="mt-1 text-sm text-gray-500">
                            @if ($p['game'] ?? null)
                                {{ __('Přidává hru :game', ['game' => $p['game_label'] ?? $p['game']]) }}
                            @else
                                {{ __('Rozšiřuje :game', ['game' => \App\Enums\Game::tryFrom($p['extends'])?->label() ?? $p['extends']]) }}
                            @endif
                            · {{ __('varianty:') }} {{ collect($p['flavors'])->pluck('label')->implode(', ') }}
                            @if (($p['servers'] ?? []) !== [])
                                · {{ \App\Support\Lang::count(count($p['servers']), ':count server ho používá', ':count servery ho používají', ':count serverů ho používá') }}
                            @endif
                        </p>
                    </div>
                    @if ($p['origin'] !== 'builtin')
                        <div class="shrink-0">
                            <x-wire::button size="sm" color="gray" outlined icon="outline:trash" wire:click="remove('{{ $p['id'] }}')"
                                :disabled="($p['servers'] ?? []) !== []"
                                :title="($p['servers'] ?? []) !== [] ? __('Nejdřív smažte herní servery, které ho používají.') : null"
                                wire:confirm="{{ __('Odebrat balíček :name? Jeho hry a varianty zmizí z nabídky.', ['name' => $p['name']]) }}">{{ __('Odebrat') }}</x-wire::button>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
        @if ($problems !== [])
            <x-wire::callout class="mt-4" color="warning" :heading="__('Balíčky, které neplatí')">
                <ul class="list-disc space-y-1 ps-5 text-sm">
                    @foreach ($problems as $pr)
                        <li><span class="font-mono text-xs">{{ ($pr['file'] ?? '') ?: ($pr['id'] ?? '') }}</span> ({{ $origin[$pr['origin']][0] ?? $pr['origin'] }}): {{ $pr['error'] }}</li>
                    @endforeach
                </ul>
            </x-wire::callout>
        @endif
    </x-wire::section>

    <x-wire::section :heading="__('Katalog WSPanelu')" :description="__('Balíčky od WSPanelu, podepsané stejným klíčem jako jeho apt repozitář; agent bez platného podpisu nic nenainstaluje.')">
        @if ($catalog === null)
            <p class="inline-flex items-center gap-2 text-sm text-gray-500">{!! icon('outline:arrow-path', 'size-4 animate-spin') !!} {{ __('Načítá se katalog…') }}</p>
        @elseif ($catalogError)
            <x-wire::callout color="warning" :heading="__('Katalog není dostupný')">{{ $catalogError }}</x-wire::callout>
        @elseif ($catalog === [])
            <p class="text-sm text-gray-500">{{ __('Katalog zatím nic nenabízí.') }}</p>
        @else
            <ul class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($catalog as $c)
                    <li wire:key="cat-{{ $c['id'] }}" class="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium">{{ $c['name'] }}</span>
                                <span class="font-mono text-xs text-gray-500">{{ $c['version'] }}</span>
                            </div>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $c['description'] }}</p>
                            <p class="mt-1 text-sm text-gray-500">
                                {{ ($c['game'] ?? null) ? __('Přidává hru :game', ['game' => $c['game_label'] ?? $c['game']]) : __('Rozšiřuje :game', ['game' => \App\Enums\Game::tryFrom($c['extends'])?->label() ?? $c['extends']]) }}
                                · {{ __('varianty:') }} {{ collect($c['flavors'])->pluck('label')->implode(', ') }}
                            </p>
                        </div>
                        <div class="shrink-0">
                            @if (($c['installed'] ?? '') === '')
                                <x-wire::button size="sm" icon="outline:arrow-down-tray" wire:click="install('{{ $c['id'] }}')">{{ __('Nainstalovat') }}</x-wire::button>
                            @elseif (version_compare($c['version'], $c['installed'], '>'))
                                <x-wire::button size="sm" icon="outline:arrow-path" wire:click="install('{{ $c['id'] }}')">{{ __('Aktualizovat na :v', ['v' => $c['version']]) }}</x-wire::button>
                            @else
                                <x-wire::badge color="success">{{ __('nainstalováno') }}</x-wire::badge>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-wire::section>
</div>
