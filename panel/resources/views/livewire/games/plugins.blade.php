@php($size = fn ($b) => \Illuminate\Support\Number::fileSize((int) $b, 1))
<div class="wire-resource-page space-y-4 sm:space-y-6">
    @include('wire-panels::pages.partials.header')

    @if ($error)
        <x-wire::callout color="danger" :heading="__('Agent neodpovídá')">{{ $error }}</x-wire::callout>
    @endif

    @php($factorio = $this->factorio())
    @if (! $this->paper() && ! $factorio && ! $server->game->is('minecraft'))
        <x-wire::callout color="info" :heading="__('Tahle hra nemá v panelu pluginy ani módy')">{{ __('Soubory hry upravíte v záložce Soubory nebo přes SFTP.') }}</x-wire::callout>
    @elseif (! $this->paper() && ! $factorio)
        <x-wire::callout color="info" :heading="__('Pluginy jen pro Paper')">{{ __('Oficiální server (Vanilla) pluginy nenačítá. Pro pluginy založte server ve variantě Paper.') }}</x-wire::callout>
    @else
        @if ($factorio && ! $account)
            <x-wire::callout color="warning" :heading="__('Módy stahuje mod portál jen s účtem factorio.com')">
                {{ __('Zadejte jméno a token účtu v záložce Nastavení (Účet factorio.com). Hledat jde i bez nich.') }}
            </x-wire::callout>
        @endif
        @if ($changed)
            <x-wire::callout color="warning" :heading="__('Změny se projeví po restartu serveru')">
                <x-wire::button size="sm" wire:click="restart" wire:confirm="{{ __('Restartovat server? Hráči se na chvíli odpojí.') }}" wire:loading.attr="disabled" wire:target="restart" :disabled="(\App\Support\RunningTasks::has('game.restart', $this->server()))"><x-panel.spinner target="restart" icon="outline:arrow-path" :busy="\App\Support\RunningTasks::has('game.restart', $this->server())" />{{ __('Restartovat teď') }}</x-wire::button>
            </x-wire::callout>
        @endif

        <x-wire::section :heading="__('Nainstalované')" :description="$factorio ? __('Módy ve složce data/mods. Factorio nový mód zapne sám; vypnout ho jde ve hře nebo v data/mods/mod-list.json.') : ($this->mods() ? __('Soubory .jar ve složce data/mods. U módů z panelu je vidět verze; ostatní jste nahráli sami (Soubory). Hráči potřebují stejné módy u sebe.') : __('Soubory .jar ve složce data/plugins. U pluginů z panelu je vidět zdroj a verze; ostatní jste nahráli sami (Soubory).'))">
            <div class="mb-3"><x-wire::button size="sm" color="gray" outlined wire:click="checkUpdates" wire:loading.attr="disabled" wire:target="checkUpdates"><x-panel.spinner target="checkUpdates" icon="outline:arrow-path" />{{ __('Zkontrolovat aktualizace') }}</x-wire::button>
                <span wire:loading wire:target="checkUpdates" class="text-sm text-gray-500">{{ __('Ptám se katalogů…') }}</span></div>
            @if ($installed === [])
                <p class="text-sm text-gray-500">{{ $this->mods() ? __('Zatím žádné módy.') : __('Zatím žádné pluginy.') }}</p>
            @else
                <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($installed as $p)
                            <tr wire:key="pl-{{ $p['file'] }}">
                                <td class="py-1.5 pe-3"><span class="font-medium">{{ $p['name'] ?? $p['file'] }}</span>
                                    @isset($p['name'])<br><span class="font-mono text-xs text-gray-500">{{ $p['file'] }}</span>@endisset</td>
                                <td class="py-1.5 pe-3 text-xs">
                                    @if ($factorio){{ $p['version'] }}@elseif (isset($p['version'])){{ $p['version'] }} · {{ $p['source'] === 'hangar' ? 'Hangar' : 'Modrinth' }}@else<span class="text-gray-500">{{ __('nahráno ručně') }}</span>@endif
                                    @isset($p['update'])<br><x-wire::badge color="info">{{ __('nová verze :v', ['v' => $p['update']]) }}</x-wire::badge>@endisset
                                </td>
                                <td class="py-1.5 pe-3 text-xs text-gray-500">{{ isset($p['size']) ? $size($p['size']) : '' }}</td>
                                <td class="whitespace-nowrap py-1.5 text-right">
                                    @isset($p['update'])
                                        <x-wire::button size="xs" wire:click="install({{ \Illuminate\Support\Js::from($factorio ? 'factorio' : $p['source']) }}, {{ \Illuminate\Support\Js::from($factorio ? $p['name'] : $p['project']) }})" :disabled="($factorio && ! $account) || (\App\Support\RunningTasks::has(['game.plugin_install', 'game.mod_install', 'game.modpack_install'], $this->server()))" wire:loading.attr="disabled" wire:target="install({{ \Illuminate\Support\Js::from($factorio ? 'factorio' : $p['source']) }}, {{ \Illuminate\Support\Js::from($factorio ? $p['name'] : $p['project']) }})"><x-panel.spinner :target="'install('.(\Illuminate\Support\Js::from($factorio ? 'factorio' : $p['source'])).', '.(\Illuminate\Support\Js::from($factorio ? $p['name'] : $p['project'])).')'" />{{ __('Aktualizovat') }}</x-wire::button>
                                    @endisset
                                    <x-wire::button size="xs" color="danger" outlined wire:click="remove({{ \Illuminate\Support\Js::from($p['file']) }})" wire:confirm="{{ __('Odebrat :file?', ['file' => $p['file']]) }}" wire:loading.attr="disabled" wire:target="remove({{ \Illuminate\Support\Js::from($p['file']) }})"><x-panel.spinner :target="'remove('.(\Illuminate\Support\Js::from($p['file'])).')'" />{{ __('Odebrat') }}</x-wire::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            @endif
        </x-wire::section>

        <x-wire::section :heading="$this->mods() ? __('Přidat mód') : __('Přidat plugin')" :description="$factorio
            ? __('Z mod portálu Factoria, jen verze pro Factorio :v. Soubor se ověří otiskem (SHA-1) z portálu; povinné závislosti se nainstalují s ním.', ['v' => implode('.', array_slice(explode('.', $server->version), 0, 2))])
            : ($this->mods() ? __('Z katalogu Modrinth, jen módy pro :loader a Minecraft :v. Soubor se stáhne z katalogu a ověří otiskem (SHA-512); povinné závislosti (třeba Fabric API) se nainstalují s ním.', ['loader' => ucfirst($server->flavor), 'v' => $server->version]) : ($this->hangar() ? __('Z katalogů Modrinth a Hangar (PaperMC), jen verze pro Minecraft :v. Soubor se stáhne z katalogu a ověří otiskem; plugin, který katalog jen odkazuje jinam, panel neinstaluje. Povinné závislosti z Modrinthu se nainstalují s ním.', ['v' => $server->version])
                : __('Z katalogu Modrinth, jen pluginy pro :loader a Minecraft :v. Soubor se stáhne z katalogu a ověří otiskem; povinné závislosti se nainstalují s ním.', ['loader' => $server->game->flavors()[$server->flavor] ?? $server->flavor, 'v' => $server->version])))">
            <form wire:submit="findPlugins" class="flex flex-wrap items-center gap-2">
                @if ($this->hangar())
                    <select wire:model="source" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                        <option value="modrinth">Modrinth</option>
                        <option value="hangar">Hangar (PaperMC)</option>
                    </select>
                @endif
                <input type="search" wire:model="query" maxlength="100" placeholder="{{ $factorio ? __('Hledat mód (prázdné = nejstahovanější)') : ($this->mods() ? __('Hledat mód (prázdné = nejoblíbenější)') : __('Hledat plugin (prázdné = nejoblíbenější)')) }}" class="w-full max-w-sm rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                <x-wire::button type="submit" size="sm" icon="outline:magnifying-glass">{{ __('Hledat') }}</x-wire::button>
                <span wire:loading wire:target="findPlugins,install" class="text-sm text-gray-500">{{ __('Probíhá…') }}</span>
            </form>
            @if ($results !== null)
                @if ($results === [])
                    <p class="mt-3 text-sm text-gray-500">{{ __('Nic pro tuto verzi nenalezeno.') }}</p>
                @else
                    @php($have = collect($installed)->pluck($factorio ? 'name' : 'project')->filter()->all())
                    <ul class="mt-3 divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($results as $r)
                            <li wire:key="res-{{ $r['source'] }}-{{ $r['project'] }}" class="flex items-start gap-3 py-2">
                                @if ($r['icon'] ?? null)<img src="{{ $r['icon'] }}" alt="" class="h-9 w-9 shrink-0 rounded" loading="lazy" referrerpolicy="no-referrer">@else<div class="h-9 w-9 shrink-0 rounded bg-gray-100 dark:bg-white/5"></div>@endif
                                <div class="min-w-0 flex-1">
                                    <a href="{{ $r['url'] }}" target="_blank" rel="noopener" class="font-medium text-primary-600 hover:underline dark:text-primary-400">{{ $r['name'] }}</a>
                                    <span class="text-xs text-gray-500">{{ $r['author'] }} · {{ \Illuminate\Support\Number::abbreviate($r['downloads']) }} {{ __('stažení') }}</span>
                                    <p class="truncate text-xs text-gray-600 dark:text-gray-400">{{ $r['description'] }}</p>
                                </div>
                                @if (in_array($r['project'], $have, true))
                                    <x-wire::badge color="success">{{ __('nainstalováno') }}</x-wire::badge>
                                @else
                                    <x-wire::button size="xs" wire:click="install({{ \Illuminate\Support\Js::from($r['source']) }}, {{ \Illuminate\Support\Js::from($r['project']) }})" :disabled="($factorio && ! $account) || (\App\Support\RunningTasks::has(['game.plugin_install', 'game.mod_install', 'game.modpack_install'], $this->server()))" wire:loading.attr="disabled" wire:target="install({{ \Illuminate\Support\Js::from($r['source']) }}, {{ \Illuminate\Support\Js::from($r['project']) }})"><x-panel.spinner :target="'install('.(\Illuminate\Support\Js::from($r['source'])).', '.(\Illuminate\Support\Js::from($r['project'])).')'" />{{ __('Nainstalovat') }}</x-wire::button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </x-wire::section>

        @if ($this->modpacks())
            <x-wire::section :heading="__('Modpack z Modrinthu')" :description="__('Celý balík módů a jejich nastavení (třeba Create s doplňky), jen packy pro :loader a Minecraft :v. Každý soubor se ověří otiskem (SHA-512) z packu; server se na dobu instalace zastaví. Nová verze nebo jiný pack odebere, co předchozí pack přinesl; svět a ručně přidané módy zůstanou.', ['loader' => $server->game->flavors()[$server->flavor] ?? $server->flavor, 'v' => $server->version])">
                @if ($pack)
                    <p class="mb-3 text-sm">{{ __('Nainstalovaný pack:') }} <span class="font-medium">{{ $pack['name'] }} {{ $pack['version'] }}</span> <span class="text-gray-500">({{ __(':n souborů', ['n' => $pack['files']]) }})</span></p>
                @endif
                <form wire:submit="findModpacks" class="flex flex-wrap items-center gap-2">
                    <input type="search" wire:model="packQuery" maxlength="100" placeholder="{{ __('Hledat modpack (prázdné = nejoblíbenější)') }}" class="w-full max-w-sm rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                    <x-wire::button type="submit" size="sm" icon="outline:magnifying-glass">{{ __('Hledat') }}</x-wire::button>
                    <span wire:loading wire:target="findModpacks,installModpack" class="text-sm text-gray-500">{{ __('Probíhá…') }}</span>
                </form>
                @if ($packResults !== null)
                    @if ($packResults === [])
                        <p class="mt-3 text-sm text-gray-500">{{ __('Nic pro tuto verzi nenalezeno.') }}</p>
                    @else
                        <ul class="mt-3 divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($packResults as $r)
                                <li wire:key="pack-{{ $r['project'] }}" class="flex items-start gap-3 py-2">
                                    @if ($r['icon'] ?? null)<img src="{{ $r['icon'] }}" alt="" class="h-9 w-9 shrink-0 rounded" loading="lazy" referrerpolicy="no-referrer">@else<div class="h-9 w-9 shrink-0 rounded bg-gray-100 dark:bg-white/5"></div>@endif
                                    <div class="min-w-0 flex-1">
                                        <a href="{{ $r['url'] }}" target="_blank" rel="noopener" class="font-medium text-primary-600 hover:underline dark:text-primary-400">{{ $r['name'] }}</a>
                                        <span class="text-xs text-gray-500">{{ $r['author'] }} · {{ \Illuminate\Support\Number::abbreviate($r['downloads']) }} {{ __('stažení') }}</span>
                                        <p class="truncate text-xs text-gray-600 dark:text-gray-400">{{ $r['description'] }}</p>
                                    </div>
                                    <x-wire::button size="xs" wire:click="installModpack({{ \Illuminate\Support\Js::from($r['project']) }})"
                                        wire:confirm="{{ ($pack ? __('Nahradit pack :old packem :new?', ['old' => $pack['name'], 'new' => $r['name']]).' ' : '').__('Server se na dobu instalace zastaví.') }}" wire:loading.attr="disabled" wire:target="installModpack({{ \Illuminate\Support\Js::from($r['project']) }})" :disabled="(\App\Support\RunningTasks::has(['game.plugin_install', 'game.mod_install', 'game.modpack_install'], $this->server()))"><x-panel.spinner :target="'installModpack('.(\Illuminate\Support\Js::from($r['project'])).')'" />
                                        {{ ($pack['project'] ?? null) === $r['project'] ? __('Aktualizovat') : __('Nainstalovat') }}</x-wire::button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endif
            </x-wire::section>
        @endif
    @endif

    @include('wire-panels::pages.partials.action-modals')
</div>
