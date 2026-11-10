<div class="wire-resource-page space-y-4 sm:space-y-6">
    @include('wire-panels::pages.partials.header')
    @include('wire-panels::pages.partials.action-modals')
    @include('wire-core::partials.copy-assets')

    @php
        $missingInZone = collect($records)->where('in_zone', 'missing')->count();
        $pending = collect($records)->whereIn('public', ['missing', 'differs'])->count();
        $checked = collect($records)->whereNotNull('public')->count();
        $copy = fn (string $v) => app(\NyonCode\WireCore\Foundation\View\CopyButton::class)->render(value: $v, message: __('Zkopírováno'), class: 'p-0.5 rounded-sm text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700', testId: 'dns-copy');
        $publicLabel = fn (array $r) => match ($r['public']) {
            'ok' => in_array($r['type'], ['A', 'AAAA'], true) ? [__('míří sem'), 'success'] : [__('je'), 'success'],
            'differs' => in_array($r['type'], ['A', 'AAAA'], true) ? [__('míří jinam'), 'danger'] : [__('jiná hodnota'), 'warning'],
            'missing' => [__('chybí'), 'danger'],
            default => null,
        };
    @endphp

    @if ($zoneError)
        <x-wire::callout color="danger" :heading="__('Zónu se nepodařilo načíst')">{{ $zoneError }}</x-wire::callout>
    @endif

    @unless ($zone)
        <x-wire::section :heading="__('Jak nastavit DNS u registrátora nebo DNS poskytovatele')">
            <ol class="list-decimal space-y-1.5 ps-5 text-sm text-gray-700 dark:text-gray-300">
                <li>{!! __('V administraci domény <strong>:domain</strong> otevřete správu DNS záznamů. Doména musí používat DNS servery toho, u koho záznamy nastavujete (jinak se změny neprojeví).', ['domain' => e($domain)]) !!}</li>
                <li>{{ __('Každý řádek tabulky přidejte jako nový záznam: Název, Typ, Hodnota, u MX a SRV i Priorita (u SRV i Váha a Port) do jejich vlastních polí. TTL ponechte výchozí.') }}</li>
                <li>{{ __('Název je vůči doméně: „@“ znamená doménu samotnou; když formulář chce prázdné pole nebo celý název, zadejte to tak (celý název je ve druhém řádku buňky).') }}</li>
                <li>{{ __('Hodnotu TXT vložte bez uvozovek. Cíl u MX, CNAME a SRV je celý název; některé formuláře chtějí tečku na konci, jiné ne.') }}</li>
                <li>{{ __('Když už stejný název a typ existuje (třeba A záznam parkovací stránky nebo jiné SPF), upravte ho, nepřidávejte druhý. SPF smí být jen jedno: má-li doména jiné odesílatele, sloučte je do jednoho záznamu.') }}</li>
                <li>{{ __('Pak klikněte na Zkontrolovat znovu: panel se ptá přímo DNS serverů domény, takže nový záznam uvidí hned. Návštěvníkům se projeví podle TTL, obvykle do hodiny.') }}</li>
            </ol>
        </x-wire::section>
    @endunless

    <div class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between border-b border-gray-200 px-4 py-4 sm:px-6 dark:border-gray-700">
            <div class="min-w-0 flex-1">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ $zone ? __('Záznamy, které web potřebuje') : __('Co nastavit v DNS domény :domain', ['domain' => $domain]) }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    @if ($zone)
                        {{ __('Zóna :zone · :where je v panelu: chybějící záznamy doplní tlačítko. Existující, které míří jinam, panel nemění.', ['zone' => $zone->domain, 'where' => $zone->provider->getLabel()]) }}
                    @else
                        @if ($checked === 0)
                            {{ __('Doména vnitřní sítě (nebo server bez veřejné adresy): veřejné DNS se neověřuje.') }}
                        @else
                            {{ __('Sloupec Stav ukazuje, co už veřejné DNS vrací.') }}
                            @if ($pending === 0) <strong class="text-green-700 dark:text-green-400">{{ __('Všechno je nastavené.') }}</strong> @endif
                        @endif
                    @endif
                </p>
            </div>
            <div class="flex shrink-0 flex-wrap gap-2">
                @if ($checkedAt)
                    <span class="self-center text-xs text-gray-500">{{ __('zkontrolováno v :time', ['time' => $checkedAt]) }}</span>
                @endif
                <x-wire::button size="sm" color="gray" outlined icon="outline:arrow-path" wire:click="recheck" wire:loading.attr="disabled" wire:target="recheck">
                    <span wire:loading.remove wire:target="recheck">{{ __('Zkontrolovat znovu') }}</span>
                    <span wire:loading wire:target="recheck">{{ __('Ptám se DNS…') }}</span>
                </x-wire::button>
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
                        <th class="py-2 pe-3 font-medium">{{ __('Priorita') }}</th>
                        <th class="py-2 pe-3 font-medium">{{ __('Hodnota') }}</th>
                        @if ($zone)<th class="py-2 pe-3 font-medium">{{ __('V zóně') }}</th>@endif
                        <th class="py-2 pe-4 font-medium sm:pe-6">{{ __('Stav') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($records as $r)
                        <tr wire:key="r-{{ $r['name'] }}-{{ $r['type'] }}">
                            <td class="py-2 ps-4 pe-3 align-top sm:ps-6">
                                <span class="inline-flex items-center gap-1 font-mono text-xs font-medium">{{ $r['relative'] }}{!! $copy($r['relative']) !!}</span>
                                @if ($r['relative'] !== $r['name'])<div class="font-mono text-[11px] text-gray-400">{{ $r['name'] }}</div>@endif
                                <div class="text-xs text-gray-500">{{ $r['note'] }}</div>
                            </td>
                            <td class="py-2 pe-3 align-top font-mono text-xs">{{ $r['type'] }}</td>
                            <td class="py-2 pe-3 align-top font-mono text-xs">
                                @foreach ($r['fields'] as $f)
                                    <div>{{ $f['priority'] ?? '' }}@isset($f['port']) <span class="text-gray-500">{{ __('váha :w, port :p', ['w' => $f['weight'], 'p' => $f['port']]) }}</span>@endisset</div>
                                @endforeach
                            </td>
                            <td class="max-w-xl py-2 pe-3 align-top font-mono text-xs">
                                @foreach ($r['fields'] as $f)
                                    <div class="flex items-start gap-1"><span class="break-all">{{ $f['value'] }}</span>{!! $copy($f['value']) !!}</div>
                                @endforeach
                            </td>
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
                                @if ($l = $publicLabel($r))<x-wire::badge :color="$l[1]">{{ $l[0] }}</x-wire::badge>@else<span class="text-xs text-gray-400">—</span>@endif
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
    <p class="text-sm text-gray-500">{{ __('Stav podle veřejného DNS: „jiná hodnota“ u TXT často znamená, že záznam existuje, ale s jiným obsahem (např. SPF s dalšími odesílateli). Změny se šíří podle TTL, obvykle minuty až hodiny.') }}</p>
</div>
