@php($size = fn ($b) => \Illuminate\Support\Number::fileSize((int) $b, 1))
<div class="space-y-6">
    @include('partials.background-jobs')
    @if ($error)
        <x-wire::callout color="danger" :heading="__('Agent neodpovídá')">{{ $error }}</x-wire::callout>
    @endif

    @if ($pending)
        <x-wire::callout :color="$pending['allowed'] ? 'warning' : 'danger'" :heading="$pending['action'] === 'install' ? __('Nainstalovat :name?', ['name' => $pending['name']]) : __('Odebrat :name?', ['name' => $pending['name']])">
            @if (! $pending['allowed'])
                <p>{{ $pending['reason'] ?? '' }}</p>
            @else
                @if ($pending['install'] !== [])
                    <p>{{ __('Nainstaluje se:') }} <span class="font-mono text-xs">{{ implode(', ', $pending['install']) }}</span></p>
                @endif
                @if ($pending['remove'] !== [])
                    <p>{{ __('Odebere se:') }} <span class="font-mono text-xs">{{ implode(', ', $pending['remove']) }}</span></p>
                @endif
                @if ($pending['install'] === [] && $pending['remove'] === [])
                    <p>{{ __('Nic se nezmění (už je to tak).') }}</p>
                @endif
            @endif
            <div class="mt-3 flex gap-2">
                @if ($pending['allowed'] && ($pending['install'] !== [] || $pending['remove'] !== []))
                    <x-wire::button size="sm" :color="$pending['action'] === 'remove' ? 'danger' : 'primary'" wire:click="confirmPending">{{ $pending['action'] === 'install' ? __('Nainstalovat') : __('Odebrat') }}</x-wire::button>
                @endif
                <x-wire::button size="sm" color="gray" outlined wire:click="cancelPending">{{ __('Zrušit') }}</x-wire::button>
                <span wire:loading wire:target="confirmPending" class="text-sm text-gray-500">{{ __('Probíhá… může to trvat i několik minut.') }}</span>
            </div>
        </x-wire::callout>
    @endif

    <x-wire::section :heading="__('Najít a nainstalovat')" :description="__('Balíčky z repozitářů Debianu nastavených na serveru (htop, ncdu, imagemagick…). Instalují se bez doporučených balíčků.')">
        <form wire:submit="findPackages" class="flex flex-wrap items-center gap-3">
            <input type="search" wire:model="query" placeholder="{{ __('Název balíčku') }}" class="w-full max-w-sm rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
            <x-wire::button type="submit" size="sm" icon="outline:magnifying-glass">{{ __('Hledat') }}</x-wire::button>
        </form>
        @error('query')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        @if ($found !== null)
            @if ($found === [])
                <p class="mt-3 text-sm text-gray-500">{{ __('Nic nenalezeno.') }}</p>
            @else
                @if ($foundTotal > count($found))
                    <p class="mt-3 text-xs text-gray-500">{{ __('Zobrazeno :n z :total, upřesněte hledání.', ['n' => count($found), 'total' => $foundTotal]) }}</p>
                @endif
                <div class="overflow-x-auto">
                <table class="mt-3 w-full text-left text-sm">
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($found as $p)
                            <tr wire:key="f-{{ $p['name'] }}">
                                <td class="py-1 pe-3 font-mono text-xs">{{ $p['name'] }}</td>
                                <td class="py-1 pe-3 text-xs text-gray-500">{{ $p['summary'] }}</td>
                                <td class="py-1 text-right">
                                    @if ($p['version'])
                                        <x-wire::badge color="success">{{ __('nainstalováno') }}</x-wire::badge>
                                    @else
                                        <x-wire::button size="xs" wire:click="ask('install', {{ \Illuminate\Support\Js::from($p['name']) }})">{{ __('Nainstalovat') }}</x-wire::button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            @endif
        @endif
    </x-wire::section>

    <x-wire::section :heading="__('Nainstalované')" :description="__('Podržet = zmrazit verzi: aktualizace systému (ruční i automatické bezpečnostní) balíček přeskočí, dokud ho neuvolníte. Hodí se, když nová verze něco rozbije. Odebrat jde jen to, co server ani panel nepotřebují; konfigurace balíčku zůstane.')">
        <div class="mb-3 flex flex-wrap items-center gap-4">
            <input type="search" wire:model.live.debounce.300ms="filter" placeholder="{{ __('Filtrovat') }}" class="w-full max-w-xs rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="manualOnly" class="rounded border-gray-300">{{ __('Jen nainstalované ručně a podržené') }}</label>
            <span class="text-xs text-gray-500">{{ \App\Support\Lang::count(count($installed), ':count balíček celkem', ':count balíčky celkem', ':count balíčků celkem') }}</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs text-gray-500"><tr><th class="py-1 pe-3 font-normal">{{ __('Balíček') }}</th><th class="py-1 pe-3 font-normal">{{ __('Verze') }}</th><th class="py-1 pe-3 font-normal">{{ __('Velikost') }}</th><th class="py-1 pe-3 font-normal">{{ __('Popis') }}</th><th></th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach (array_slice($this->shownInstalled(), 0, 500) as $p)
                        <tr wire:key="i-{{ $p['name'] }}">
                            <td class="py-1 pe-3 font-mono text-xs">{{ $p['name'] }}
                                @if ($p['held'])<x-wire::badge color="warning">{{ __('podržený') }}</x-wire::badge>@endif
                            </td>
                            <td class="py-1 pe-3 font-mono text-xs text-gray-500">{{ $p['version'] }}</td>
                            <td class="py-1 pe-3 text-xs tabular-nums">{{ $size($p['size']) }}</td>
                            <td class="max-w-md truncate py-1 pe-3 text-xs text-gray-500" title="{{ $p['summary'] }}">{{ $p['summary'] }}</td>
                            <td class="whitespace-nowrap py-1 text-right">
                                <x-wire::button size="xs" color="gray" outlined wire:click="hold({{ \Illuminate\Support\Js::from($p['name']) }}, {{ $p['held'] ? 'false' : 'true' }})" :wire:confirm="$p['held'] ? null : __('Podržet :name ve verzi :version? Aktualizace ho budou přeskakovat, i bezpečnostní opravy, dokud ho neuvolníte.', ['name' => $p['name'], 'version' => $p['version'] ?? ''])" title="{{ $p['held'] ? __('Aktualizace systému ho zase budou aktualizovat.') : __('apt-mark hold: aktualizace systému balíček přeskočí a zůstane v současné verzi.') }}">{{ $p['held'] ? __('Uvolnit') : __('Podržet') }}</x-wire::button>
                                @unless ($p['protected'])
                                    <x-wire::button size="xs" color="danger" outlined wire:click="ask('remove', {{ \Illuminate\Support\Js::from($p['name']) }})">{{ __('Odebrat') }}</x-wire::button>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-wire::section>
</div>
