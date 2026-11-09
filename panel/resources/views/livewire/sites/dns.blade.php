<div class="wire-resource-page space-y-4 sm:space-y-6">
    @include('wire-panels::pages.partials.header')
    @include('wire-panels::pages.partials.action-modals')

    @php
        $missingInZone = collect($records)->where('in_zone', 'missing')->count();
        $notHere = collect($records)->where('public', false)->count();
    @endphp

    @if ($zoneError)
        <x-wire::callout color="danger" :heading="__('Zónu se nepodařilo načíst')">{{ $zoneError }}</x-wire::callout>
    @endif

    <div class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-200 px-4 py-4 sm:px-6 dark:border-gray-700">
            <div class="min-w-0">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Záznamy, které web potřebuje') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    @if ($zone)
                        {{ __('Zóna :zone (:where) je v panelu: chybějící záznamy doplní tlačítko. Existující, které míří jinam, panel nemění.', ['zone' => $zone->domain, 'where' => $zone->provider->getLabel()]) }}
                    @else
                        {{ __('Zónu domény panel nespravuje: nastavte tyto záznamy u registrátora (nebo jiného DNS), nebo převeďte DNS do panelu tlačítkem Spravovat DNS v panelu.') }}
                    @endif
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-wire::button size="sm" color="gray" outlined icon="outline:arrow-path" wire:click="$refresh">{{ __('Zkontrolovat znovu') }}</x-wire::button>
                @if ($zone && $missingInZone > 0)
                    <x-wire::button size="sm" icon="outline:sparkles" wire:click="fillDns">{{ __('Doplnit chybějící (:n)', ['n' => $missingInZone]) }}</x-wire::button>
                @endif
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/5">
                    <tr>
                        <th class="py-2 ps-4 pe-3 font-medium sm:ps-6">{{ __('Název') }}</th>
                        <th class="py-2 pe-3 font-medium">{{ __('Typ') }}</th>
                        <th class="py-2 pe-3 font-medium">{{ __('Hodnota') }}</th>
                        @if ($zone)<th class="py-2 pe-3 font-medium">{{ __('V zóně') }}</th>@endif
                        <th class="py-2 pe-4 font-medium sm:pe-6">{{ __('Vidí svět') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($records as $r)
                        <tr wire:key="r-{{ $r['name'] }}-{{ $r['type'] }}">
                            <td class="py-2 ps-4 pe-3 align-top sm:ps-6">
                                <span class="font-mono text-xs">{{ $r['name'] }}</span>
                                <div class="text-xs text-gray-500">{{ $r['note'] }}</div>
                            </td>
                            <td class="py-2 pe-3 align-top font-mono text-xs">{{ $r['type'] }}</td>
                            <td class="max-w-md py-2 pe-3 align-top font-mono text-xs break-all">{!! implode('<br>', array_map('e', $r['records'])) !!}</td>
                            @if ($zone)
                                <td class="py-2 pe-3 align-top">
                                    @switch($r['in_zone'])
                                        @case('ok')<x-wire::badge color="success">{{ __('je') }}</x-wire::badge>@break
                                        @case('missing')<x-wire::badge color="warning">{{ __('chybí') }}</x-wire::badge>@break
                                        @case('differs')<x-wire::badge color="danger">{{ __('jiná hodnota') }}</x-wire::badge>@break
                                        @default<span class="text-xs text-gray-400">{{ __('mimo zónu') }}</span>
                                    @endswitch
                                </td>
                            @endif
                            <td class="py-2 pe-4 align-top sm:pe-6">
                                @if ($r['public'] === true)<x-wire::badge color="success">{{ __('míří sem') }}</x-wire::badge>
                                @elseif ($r['public'] === false)<x-wire::badge color="danger">{{ __('nemíří sem') }}</x-wire::badge>
                                @else<span class="text-xs text-gray-400">—</span>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if ($zone && $zone->provider === \App\Enums\DnsProvider::Local)
        <p class="text-sm text-gray-500">{{ __('Zóna je na tomto serveru: u registrátora domény :zone musí být nameservery :ns.', ['zone' => $zone->domain, 'ns' => implode(', ', $nameservers)]) }}</p>
    @endif
    @if ($notHere > 0)
        <p class="text-sm text-gray-500">{{ __('„Nemíří sem“: veřejné DNS zatím vrací jinou adresu. Změny se šíří podle TTL, obvykle minuty až hodiny.') }}</p>
    @endif
</div>
