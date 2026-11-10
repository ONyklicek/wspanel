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
            <input type="search" wire:model="query" placeholder="{{ __('Název balíčku') }}" class="w-full rounded-lg sm:max-w-sm border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
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
                <table class="mt-3 w-full table-fixed text-left text-sm">
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($found as $p)
                            <tr wire:key="f-{{ $p['name'] }}">
                                <td class="py-1 pe-3 align-top font-mono text-xs break-all">{{ $p['name'] }}
                                    <div class="font-sans text-gray-500 break-normal md:hidden">{{ $p['summary'] }}</div>
                                </td>
                                <td class="hidden py-1 pe-3 align-top text-xs text-gray-500 md:table-cell md:w-1/2">{{ $p['summary'] }}</td>
                                <td class="w-28 py-1 text-right align-top">
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
            <div class="relative w-full sm:max-w-xs">
                <input type="search" wire:model.live.debounce.250ms="filter" x-on:keydown.escape="$wire.set('filter', '')"
                       placeholder="{{ __('Hledat název nebo popis') }}" autocomplete="off" spellcheck="false"
                       class="w-full rounded-lg border-gray-300 pe-8 text-sm dark:border-white/10 dark:bg-white/5">
                <span wire:loading wire:target="filter,manualOnly" class="pointer-events-none absolute inset-y-0 end-2 flex items-center">{!! icon('outline:arrow-path', 'h-4 w-4 animate-spin text-gray-400') !!}</span>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="manualOnly" class="rounded border-gray-300">{{ __('Jen nainstalované ručně a podržené') }}</label>
            @php($matching = $this->shownInstalled())
            <span class="text-xs text-gray-500">
                @if (trim($filter) !== '' || $manualOnly)
                    {{ __(':shown z :total', ['shown' => count($matching), 'total' => $installedTotal]) }}
                @else
                    {{ \App\Support\Lang::count($installedTotal, ':count balíček celkem', ':count balíčky celkem', ':count balíčků celkem') }}
                @endif
            </span>
        </div>
        @php($shown = array_slice($matching, 0, \App\Livewire\Pages\PackagesPage::SHOWN))
        @if ($shown === [])
            <p class="py-6 text-center text-sm text-gray-500">
                {{ trim($filter) !== '' ? __('Žádný nainstalovaný balíček neodpovídá „:term“.', ['term' => trim($filter)]) : __('Žádné balíčky nainstalované ručně ani podržené.') }}
                @if ($manualOnly)
                    <button type="button" wire:click="$set('manualOnly', false)" class="ms-1 text-primary-600 hover:underline dark:text-primary-400">{{ __('Hledat ve všech') }}</button>
                @endif
            </p>
        @endif
        <div class="-mx-4 sm:-mx-6">
            <div class="hidden grid-cols-[minmax(0,14rem)_minmax(0,10rem)_5rem_minmax(0,1fr)_auto] gap-x-3 border-b border-gray-100 px-4 pb-1 text-xs text-gray-500 md:grid sm:px-6 dark:border-white/5">
                <span>{{ __('Balíček') }}</span><span>{{ __('Verze') }}</span><span>{{ __('Velikost') }}</span><span>{{ __('Popis') }}</span><span></span>
            </div>
            <ul class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($shown as $p)
                    <li wire:key="i-{{ $p['name'] }}" class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 px-4 py-2 text-xs sm:px-6 md:grid-cols-[minmax(0,14rem)_minmax(0,10rem)_5rem_minmax(0,1fr)_auto] md:py-1">
                        <div class="flex min-w-0 items-center gap-2">
                            <span class="truncate font-mono text-sm md:text-xs" title="{{ $p['name'] }}">{{ $p['name'] }}</span>
                            @if ($p['held'])<x-wire::badge color="warning">{{ __('podržený') }}</x-wire::badge>@endif
                        </div>
                        <span class="col-start-1 truncate font-mono text-gray-500 md:col-start-auto" title="{{ $p['version'] }}">{{ $p['version'] }}<span class="md:hidden"> · {{ $size($p['size']) }}</span></span>
                        <span class="hidden tabular-nums md:block">{{ $size($p['size']) }}</span>
                        <span class="col-start-1 truncate text-gray-500 md:col-start-auto" title="{{ $p['summary'] }}">{{ $p['summary'] }}</span>
                        <div class="col-start-2 row-span-3 row-start-1 flex flex-col items-end gap-1 sm:flex-row md:col-start-auto md:row-span-1 md:row-start-auto">
                            <x-wire::button size="xs" color="gray" outlined wire:click="hold({{ \Illuminate\Support\Js::from($p['name']) }}, {{ $p['held'] ? 'false' : 'true' }})" :wire:confirm="$p['held'] ? null : __('Podržet :name ve verzi :version? Aktualizace ho budou přeskakovat, i bezpečnostní opravy, dokud ho neuvolníte.', ['name' => $p['name'], 'version' => $p['version'] ?? ''])" title="{{ $p['held'] ? __('Aktualizace systému ho zase budou aktualizovat.') : __('apt-mark hold: aktualizace systému balíček přeskočí a zůstane v současné verzi.') }}">{{ $p['held'] ? __('Uvolnit') : __('Podržet') }}</x-wire::button>
                            @unless ($p['protected'])
                                <x-wire::button size="xs" color="danger" outlined wire:click="ask('remove', {{ \Illuminate\Support\Js::from($p['name']) }})">{{ __('Odebrat') }}</x-wire::button>
                            @endunless
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
        @if (count($matching) > count($shown))
            <p class="mt-2 text-xs text-gray-500">{{ __('Zobrazeno prvních :shown z :count, upřesněte hledání.', ['shown' => count($shown), 'count' => count($matching)]) }}</p>
        @endif
    </x-wire::section>
</div>
