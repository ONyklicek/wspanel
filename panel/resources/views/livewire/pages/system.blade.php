@php($size = fn ($b) => \Illuminate\Support\Number::fileSize((int) $b, 1))
<div class="space-y-6">
    @include('partials.background-jobs')
    @if ($error)
        <x-wire::callout color="danger" :heading="__('Agent neodpovídá')">{{ $error }}</x-wire::callout>
    @endif

    @if ($settings)
        <x-wire::section :heading="__('Název a čas')">
            <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div><dt class="text-xs text-gray-500">{{ __('Název serveru') }}</dt><dd class="font-mono">{{ $settings['hostname'] }}</dd></div>
                <div><dt class="text-xs text-gray-500">{{ __('Časové pásmo') }}</dt><dd>{{ $settings['timezone'] ?: '—' }}
                    <span class="text-gray-500">({{ \Illuminate\Support\Carbon::parse($settings['time'])->format('H:i') }})</span></dd></div>
                <div class="sm:col-span-2">
                    <dt class="text-xs text-gray-500">{{ __('Synchronizace času (NTP)') }}</dt>
                    <dd class="flex flex-wrap items-center gap-2">
                        @if ($settings['ntp'])
                            <x-wire::badge :color="$settings['ntp_synchronized'] ? 'success' : 'warning'">{{ $settings['ntp_synchronized'] ? __('zapnuto, čas sedí') : __('zapnuto, zatím nesesynchronizováno') }}</x-wire::badge>
                        @else
                            <x-wire::badge color="danger">{{ __('vypnuto') }}</x-wire::badge>
                        @endif
                        <x-wire::button size="xs" color="gray" outlined wire:click="toggleNtp">{{ $settings['ntp'] ? __('Vypnout') : __('Zapnout') }}</x-wire::button>
                        @unless ($settings['ntp'])
                            <span class="text-xs text-gray-500">{{ __('Bez synchronizace se hodiny rozejdou a přestanou sedět certifikáty, 2FA kódy i podpisy pošty.') }}</span>
                        @endunless
                    </dd>
                </div>
            </dl>
        </x-wire::section>

        <x-wire::section :heading="__('Swap')" :description="__('Odkládací prostor na disku, když dojde paměť. Panel spravuje jen svůj swapový soubor, jiný swap (oddíl) jen ukáže.')">
            @if ($settings['swaps'] === [])
                <p class="text-sm text-gray-500">{{ __('Server nemá žádný swap. Při nedostatku paměti jádro rovnou ukončuje procesy (třeba databázi); 1–2 GB swapu je rozumná pojistka.') }}</p>
            @else
                <table class="w-full text-left text-sm">
                    <thead class="text-xs text-gray-500"><tr><th class="py-1 pe-3 font-normal">{{ __('Kde') }}</th><th class="py-1 pe-3 font-normal">{{ __('Velikost') }}</th><th class="py-1 pe-3 font-normal">{{ __('Využito') }}</th><th></th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($settings['swaps'] as $s)
                            <tr>
                                <td class="py-1 pe-3 font-mono text-xs">{{ $s['path'] }}</td>
                                <td class="py-1 pe-3">{{ $size($s['size']) }}</td>
                                <td class="py-1 pe-3">{{ $size($s['used']) }}</td>
                                <td class="py-1">@if ($s['managed'])<x-wire::badge color="info">{{ __('panel') }}</x-wire::badge>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            <p class="mt-2 text-xs text-gray-500">{{ __('Swappiness: :n', ['n' => $settings['swappiness'] >= 0 ? $settings['swappiness'] : '—']) }}</p>
        </x-wire::section>
    @endif

    @if ($network)
        <x-wire::section :heading="__('Síť')" :description="__('Jen pro čtení: chybná změna sítě z webové stránky by odřízla cestu zpět. Síť se mění u poskytovatele nebo na serveru.')">
            <table class="w-full text-left text-sm">
                <thead class="text-xs text-gray-500"><tr><th class="py-1 pe-3 font-normal">{{ __('Rozhraní') }}</th><th class="py-1 pe-3 font-normal">{{ __('Stav') }}</th><th class="py-1 pe-3 font-normal">{{ __('Adresy') }}</th><th class="py-1 font-normal">MAC</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($network['interfaces'] as $i)
                        <tr>
                            <td class="py-1 pe-3 font-mono text-xs">{{ $i['name'] }}</td>
                            <td class="py-1 pe-3"><x-wire::badge :color="$i['state'] === 'up' ? 'success' : 'gray'">{{ $i['state'] }}</x-wire::badge></td>
                            <td class="py-1 pe-3 font-mono text-xs">{!! implode('<br>', array_map('e', $i['addresses'])) ?: '—' !!}</td>
                            <td class="py-1 font-mono text-xs text-gray-500">{{ $i['mac'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="mt-3 text-sm">
                {{ __('Výchozí brána:') }} <span class="font-mono text-xs">{{ collect($network['gateways'])->map(fn ($g) => $g['gateway'].' ('.$g['dev'].')')->implode(', ') ?: '—' }}</span>
                · {{ __('DNS servery:') }} <span class="font-mono text-xs">{{ implode(', ', $network['resolvers']) ?: '—' }}</span>
            </p>
        </x-wire::section>
    @endif

    @if ($disks)
        <x-wire::section :heading="__('Zdraví disků (SMART)')" :description="__('Disky samy hlásí, jestli neselhávají: přemapované a čekající sektory, opotřebení SSD, teplota.')">
            @if (! $disks['installed'])
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <span class="text-gray-500">{{ __('Sledování disků není nainstalované (balíček smartmontools).') }}</span>
                    <x-wire::button size="sm" icon="outline:arrow-down-tray" wire:click="installSmart">{{ __('Nainstalovat') }}</x-wire::button>
                </div>
            @elseif ($disks['disks'] === [] || collect($disks['disks'])->every(fn ($d) => ! $d['available']))
                <p class="text-sm text-gray-500">{{ __('Disky stav SMART nehlásí. U virtuálního serveru je to běžné: fyzické disky hlídá poskytovatel.') }}</p>
            @else
                <table class="w-full text-left text-sm">
                    <thead class="text-xs text-gray-500"><tr><th class="py-1 pe-3 font-normal">{{ __('Disk') }}</th><th class="py-1 pe-3 font-normal">{{ __('Stav') }}</th><th class="py-1 pe-3 font-normal">{{ __('Teplota') }}</th><th class="py-1 pe-3 font-normal">{{ __('V provozu') }}</th><th class="py-1 font-normal">{{ __('Podrobnosti') }}</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($disks['disks'] as $d)
                            @php($bad = ($d['passed'] ?? true) === false || ($d['reallocated'] ?? 0) > 0 || ($d['pending'] ?? 0) > 0 || ($d['wear_percent'] ?? 0) >= 90)
                            <tr>
                                <td class="py-1 pe-3"><span class="font-mono text-xs">{{ $d['name'] }}</span><br><span class="text-xs text-gray-500">{{ $d['model'] }}{{ $d['capacity'] ? ' · '.$size($d['capacity']) : '' }}</span></td>
                                <td class="py-1 pe-3">
                                    @if ($d['error'] ?? null)
                                        <x-wire::badge color="gray">{{ __('nečitelný') }}</x-wire::badge>
                                    @elseif (! $d['available'])
                                        <x-wire::badge color="gray">{{ __('nehlásí SMART') }}</x-wire::badge>
                                    @else
                                        <x-wire::badge :color="$bad ? 'danger' : 'success'">{{ $bad ? __('pozor') : __('v pořádku') }}</x-wire::badge>
                                    @endif
                                </td>
                                <td class="py-1 pe-3">{{ isset($d['temperature']) ? $d['temperature'].' °C' : '—' }}</td>
                                <td class="py-1 pe-3">{{ isset($d['power_on_hours']) ? \App\Support\Lang::count(intdiv($d['power_on_hours'], 24), ':count den', ':count dny', ':count dní') : '—' }}</td>
                                <td class="py-1 text-xs text-gray-500">
                                    @isset($d['wear_percent']){{ __('opotřebení :n %', ['n' => $d['wear_percent']]) }}@endisset
                                    @isset($d['reallocated']){{ __('přemapované sektory: :n', ['n' => $d['reallocated']]) }}@endisset
                                    @if (($d['pending'] ?? 0) > 0) · {{ __('čekající: :n', ['n' => $d['pending']]) }}@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="mt-2 text-xs text-gray-500">{{ __('Disk se stavem „pozor“ zálohujte a nechte vyměnit, dokud ještě čte.') }}</p>
            @endif
        </x-wire::section>
    @endif
</div>
