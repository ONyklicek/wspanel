@php
    use Illuminate\Support\Number;
    $max = max(1, collect($stats['series'])->max('requests'));
    $errorRate = $stats['requests'] > 0 ? 100 * $stats['errors'] / $stats['requests'] : 0;
@endphp
<div class="wire-resource-page space-y-4 sm:space-y-6">
    @include('wire-panels::pages.partials.header')

    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-gray-500 dark:text-gray-400">Z access logu edge serveru (obnovuje se po 5 minutách) a z kontroly dostupnosti každou minutu.</p>
        <div class="inline-flex rounded-lg border border-gray-200 p-0.5 dark:border-white/10" role="group" aria-label="Období">
            @foreach (['24h' => '24 hodin', '7d' => '7 dní', '30d' => '30 dní'] as $key => $label)
                <button type="button" wire:click="setRange('{{ $key }}')" @class(['rounded-md px-3 py-1 text-sm', 'bg-primary-600 text-white' => $range === $key, 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5' => $range !== $key]) aria-pressed="{{ $range === $key ? 'true' : 'false' }}">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        @foreach ([
            ['Požadavky', Number::format($stats['requests']), $stats['bots'] > 0 ? 'z toho roboti '.Number::format($stats['bots']) : null],
            ['Přeneseno', Number::fileSize($stats['bytes'], 1), null],
            ['Chyby 5xx', Number::format($stats['errors']), $stats['requests'] > 0 ? Number::format($errorRate, 2).' % požadavků' : null],
            ['Odezva', $stats['avg_ms'] !== null ? $stats['avg_ms'].' ms' : '—', 'průměr všech požadavků'],
            ['Dostupnost', $stats['uptime'] !== null ? Number::format($stats['uptime'], 2).' %' : '—', $stats['checks_avg_ms'] !== null ? 'kontrola odpovídá za '.$stats['checks_avg_ms'].' ms' : 'zatím bez kontrol'],
        ] as [$label, $value, $hint])
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</div>
                <div class="mt-1 text-xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $value }}</div>
                @if ($hint)<div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</div>@endif
            </div>
        @endforeach
    </div>

    <x-wire::section heading="Požadavky v čase" description="Sloupec = {{ $range === '24h' ? 'hodina' : 'den' }}; červeně chyby 5xx.">
        <div class="flex h-40 items-end gap-px" role="img" aria-label="Požadavky v čase">
            @foreach ($stats['series'] as $label => $point)
                <div class="group relative flex h-full flex-1 flex-col justify-end" title="{{ $label }}: {{ Number::format($point['requests']) }} požadavků{{ $point['errors'] ? ', '.$point['errors'].' chyb' : '' }}">
                    <div class="w-full rounded-t-sm bg-primary-500/80 group-hover:bg-primary-600" style="height: {{ round(100 * $point['requests'] / $max, 1) }}%">
                        @if ($point['errors'] > 0)
                            <div class="w-full rounded-t-sm bg-red-500" style="height: {{ max(2, round(100 * $point['errors'] / max(1, $point['requests']), 1)) }}%"></div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
        <div class="mt-1 flex justify-between text-xs text-gray-500 dark:text-gray-400">
            <span>{{ array_key_first($stats['series']) }}</span><span>{{ array_key_last($stats['series']) }}</span>
        </div>
    </x-wire::section>

    <div class="grid gap-4 lg:grid-cols-3">
        @foreach (['paths' => ['Nejčastější stránky', 'Cesta'], 'referers' => ['Odkud přišli', 'Web'], 'ips' => ['Nejaktivnější adresy', 'IP adresa']] as $key => [$heading, $col])
            <x-wire::section :heading="$heading">
                @if (empty($stats[$key]))
                    <p class="text-sm text-gray-500">Zatím nic.</p>
                @else
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs text-gray-500"><tr><th class="pb-1 font-normal">{{ $col }}</th><th class="pb-1 text-right font-normal">Požadavků</th></tr></thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($stats[$key] as $value => $count)
                                <tr><td class="max-w-0 truncate py-1 pe-2 font-mono text-xs" title="{{ $value }}">{{ $value }}</td><td class="py-1 text-right tabular-nums">{{ Number::format($count) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-wire::section>
        @endforeach
    </div>

    @include('wire-panels::pages.partials.action-modals')
</div>
