<div class="space-y-6" wire:poll.5s="refreshState">
    @include('partials.background-jobs')
    @if ($error)
        <x-wire::section>
            <p class="text-sm text-red-600">{{ __('Stav zrcadla se nepodařilo načíst:') }} {{ $error }}</p>
        </x-wire::section>
    @else
        @php($rt = $this->runtime())
        @php($peer = $state['peer'] ?? null)

        @if ($this->role() === 'fenced')
            <x-wire::callout color="danger" icon="outline:shield-exclamation" :heading="__('Tento server je odstavený')">
                {{ __('Neobsluhuje weby ani poštu a databáze jsou jen pro čtení. Důvod:') }}
                <strong>{{ match ($state['fenced'] ?? '') { 'isolated' => __('ztratil spojení se zrcadlem i se svědky (sám se vrátí, když zrcadlo nepřevzalo provoz)'), 'replaced' => __('druhý server převzal provoz'), 'switchover' => __('plánované přepnutí'), 'starting' => __('po startu čeká na potvrzení zrcadla (sám začne obsluhovat, jakmile zrcadlo odpoví, nebo po době přepnutí, když zrcadlo mlčí, svědek odpovídá a plovoucí IP nikdo nedrží)'), default => $state['fenced'] ?? '' } }}</strong>.
                {{ __('Když je druhý server primár, připojte tento zpět jako jeho zrcadlo.') }}
            </x-wire::callout>
        @elseif ($this->role() === 'standby' && ! empty($rt['primary_down_since']) && ! str_starts_with($rt['primary_down_since'], '0001'))
            <x-wire::callout color="danger" icon="outline:exclamation-triangle" :heading="__('Primár neodpovídá')">
                {{ __('Od :ago neodpovídá spojení zrcadla, HTTPS ani HTTP.', ['ago' => $this->ago($rt['primary_down_since'])]) }}
                @if ($state['auto_failover'] ?? false)
                    {{ __('Automatické přepnutí proběhne po :s s, pokud odpovídá svědek.', ['s' => $state['failover_after'] ?? 60]) }}
                @else
                    {{ __('Automatické přepnutí je vypnuté: povyšte zrcadlo tlačítkem Povýšit na primár, nebo na serveru příkazem') }} <code>sudo panel-agent -mirror-promote</code>.
                @endif
            </x-wire::callout>
        @endif

        <div class="grid gap-6 lg:grid-cols-3">
            <x-wire::section :heading="__('Tento server')">
                <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                    <dt class="text-gray-500">{{ __('Role') }}</dt>
                    <dd><x-wire::badge :color="$this->roleColor()">{{ $this->roleLabel() }}</x-wire::badge></dd>
                    @if ($this->role() !== '')
                        <dt class="text-gray-500">{{ __('ID') }}</dt><dd class="font-mono text-xs">{{ $state['node_id'] }}</dd>
                        <dt class="text-gray-500">{{ __('Adresa') }}</dt><dd class="font-mono text-xs">{{ $state['address'] }}:{{ $state['port'] }}</dd>
                        <dt class="text-gray-500">{{ __('IP pro DNS') }}</dt><dd class="font-mono text-xs">{{ $state['lan_address'] ?: '—' }}</dd>
                        <dt class="text-gray-500">{{ __('Epocha') }}</dt><dd>{{ $state['epoch'] }}</dd>
                        <dt class="text-gray-500">{{ __('Od') }}</dt><dd>{{ $this->ago($state['since'] ?? null) }}</dd>
                    @endif
                    <dt class="text-gray-500">{{ __('Verze') }}</dt><dd>{{ $state['version'] ?? '?' }}</dd>
                </dl>
            </x-wire::section>

            <x-wire::section :heading="__('Druhý server')">
                @if ($peer)
                    @php($ph = $rt['peer'] ?? null)
                    <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                        <dt class="text-gray-500">{{ __('Spojení') }}</dt>
                        <dd><x-wire::badge :color="($rt['peer_up'] ?? false) ? 'success' : 'danger'">{{ ($rt['peer_up'] ?? false) ? __('odpovídá') : __('neodpovídá') }}</x-wire::badge></dd>
                        <dt class="text-gray-500">{{ __('Server') }}</dt><dd>{{ $peer['name'] ?? '' }}</dd>
                        <dt class="text-gray-500">{{ __('Adresa') }}</dt><dd class="font-mono text-xs">{{ $peer['address'] }}:{{ $peer['port'] }}</dd>
                        @if ($ph)
                            <dt class="text-gray-500">{{ __('Role') }}</dt><dd><x-wire::badge :color="$this->roleColor($ph['role'] ?? '')">{{ $this->roleLabel($ph['role'] ?? '') }}</x-wire::badge></dd>
                            <dt class="text-gray-500">{{ __('Verze') }}</dt><dd>{{ $ph['version'] ?? '?' }}</dd>
                            <dt class="text-gray-500">{{ __('Webů') }}</dt><dd>{{ $ph['sites'] ?? 0 }}</dd>
                            @if (str_starts_with((string) ($ph['panel_url'] ?? ''), 'https://'))
                                <dt class="text-gray-500">{{ __('Panel') }}</dt>
                                <dd class="break-all"><a href="{{ $ph['panel_url'] }}" target="_blank" rel="noopener" class="text-primary-600 hover:underline">{{ $ph['panel_url'] }}</a></dd>
                            @endif
                        @endif
                        <dt class="text-gray-500">{{ __('Naposledy') }}</dt><dd>{{ $this->ago($rt['peer_seen'] ?? null) }}</dd>
                    </dl>
                    @if (! ($rt['peer_up'] ?? true) && ! empty($rt['peer_error']))
                        <p class="mt-2 break-words text-xs text-red-600">{{ $rt['peer_error'] }}</p>
                    @endif
                @elseif ($this->role() === 'primary')
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('Čeká na zrcadlo: na druhém serveru zvolte Připojit jako zrcadlo a zadejte párovací kód.') }}
                        @if (! empty($state['pairing']['expires'])) {{ __('Kód platí do :t.', ['t' => \Illuminate\Support\Carbon::parse($state['pairing']['expires'])->format('H:i')]) }} @endif
                    </p>
                @else
                    <p class="text-sm text-gray-500">{{ __('Žádný.') }}</p>
                @endif
            </x-wire::section>

            <x-wire::section :heading="__('Přepnutí')">
                <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                    <dt class="text-gray-500">{{ __('Automaticky') }}</dt>
                    <dd><x-wire::badge :color="($state['auto_failover'] ?? false) ? 'success' : 'gray'">{{ ($state['auto_failover'] ?? false) ? __('ano, po :s s', ['s' => $state['failover_after'] ?? 60]) : __('ne (ručně)') }}</x-wire::badge></dd>
                    <dt class="text-gray-500">{{ __('Svědci') }}</dt>
                    <dd class="space-y-1">
                        @forelse ((array) ($state['witness'] ?? []) as $w)
                            <div class="flex items-center gap-1 font-mono text-xs">
                                @if (array_key_exists($w, (array) ($rt['witness'] ?? [])))
                                    <x-wire::badge size="xs" :color="$rt['witness'][$w] ? 'success' : 'danger'">{{ $rt['witness'][$w] ? '✓' : '✗' }}</x-wire::badge>
                                @endif
                                {{ $w }}
                            </div>
                        @empty
                            <span class="text-gray-500">{{ __('žádný') }}</span>
                        @endforelse
                    </dd>
                    <dt class="text-gray-500">{{ __('Plovoucí IP') }}</dt>
                    <dd>
                        @if (! empty($state['vip']))
                            <span class="font-mono text-xs">{{ $state['vip']['address'] }}/{{ $state['vip']['prefix'] }}</span>
                            <x-wire::badge size="xs" :color="$state['vip']['held'] ? 'success' : 'gray'">{{ $state['vip']['held'] ? __('drží tento server') : (($rt['peer']['vip'] ?? '') === $state['vip']['address'] ? __('drží druhý server') : __('nedrží ji nikdo z páru')) }}</x-wire::badge>
                        @else
                            <span class="text-gray-500">{{ __('nenastavena') }}</span>
                        @endif
                    </dd>
                    <dt class="text-gray-500">{{ __('Windows DNS') }}</dt>
                    <dd>
                        @if (! empty($state['windns']))
                            <span class="font-mono text-xs">{{ $state['windns']['host'] }}</span>
                            <x-wire::badge size="xs" :color="$state['windns']['trusted'] ? 'success' : 'warning'">{{ $state['windns']['trusted'] ? __('ověřený') : __('neověřený hostitel') }}</x-wire::badge>
                        @else
                            <span class="text-gray-500">{{ __('nenastaveno') }}</span>
                        @endif
                    </dd>
                </dl>
                <p class="mt-3 text-xs text-gray-500">{{ __('Bez panelu: sudo panel-agent -mirror-status, sudo panel-agent -mirror-promote') }}</p>
            </x-wire::section>
        </div>

        @if ($this->layers() !== [])
            <x-wire::section :heading="__('Kopie')" :description="$this->role() === 'standby' ? __('Co zrcadlo drží a jak čerstvé to je. Synchronizace: :ago', ['ago' => ($rt['syncing'] ?? false) ? __('právě běží') : $this->ago($rt['last_sync'] ?? null)]) : null">
                <ul class="divide-y divide-gray-100 text-sm dark:divide-white/5">
                    @foreach ($this->layers() as $l)
                        <li class="flex flex-wrap items-start justify-between gap-2 py-2" wire:key="layer-{{ $l['key'] }}">
                            <span class="flex items-center gap-2">
                                <x-wire::badge size="xs" :color="$l['ok'] ? 'success' : 'danger'">{{ $l['ok'] ? __('v pořádku') : __('chyba') }}</x-wire::badge>
                                <span class="font-medium">{{ $l['label'] }}</span>
                            </span>
                            <span class="text-right text-xs text-gray-500">
                                {{ $l['detail'] }}
                                @if ($l['lag'] !== null) · {{ __('zpoždění :s s', ['s' => round($l['lag'], 1)]) }} @endif
                                · {{ $this->ago($l['at']) }}
                                @if (! $l['ok'] && $l['error'] !== '')
                                    <span class="block break-words text-red-600">{{ $l['error'] }}</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </x-wire::section>
        @endif

        @if ($this->role() === '')
            <x-wire::section :heading="__('Jak zrcadlo funguje')">
                <div class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
                    <p>{{ __('Dva servery: primár obsluhuje weby, zrcadlo (standby) drží jeho kopii a při výpadku převezme provoz. Zrcadlo může běžet i ve VM v Hyper-V na Windows.') }}</p>
                    <p>{{ __('Zrcadlo se připojuje k primáru jedním šifrovaným spojením (vzájemné TLS) a stahuje: stav a konfiguraci, soubory webů a poštu (rsync), MariaDB a PostgreSQL průběžnou replikací, MongoDB a vyhrazené databáze výpisy každých 5 minut a repliku Valkey/Redis každého webu.') }}</p>
                    <p>{{ __('O přepnutí rozhoduje agent zrcadla sám, takže funguje i tehdy, když vypadne panel primáru. Automaticky jen se svědkem (brána LAN, řadič domény): odříznuté zrcadlo se nikdy samo nepovýší. Primár, který ztratí spojení se zrcadlem i se svědky, se sám odstaví. Když se oba vrátí, rozhodne epocha, který je primár.') }}</p>
                    <p>{{ __('Ve stejné LAN je nejjednodušší plovoucí IP: jedna volná adresa, kterou drží vždy aktivní server. DNS i router na ni míří natrvalo a při přepnutí se nic nepřepisuje. Jinak aktivní server přepíše A záznamy svých webů ve Windows DNS (a v Cloudflare, mají-li servery různé veřejné IP); pro přesměrování portů na routeru jsou háky v /etc/wspanel/mirror/hooks/promote.d.') }}</p>
                    <p>{{ __('Každý server má vlastní panel (vlastní doménu): na zrcadle spravujete server samotný (aktualizace, moduly, služby, zrcadlo), weby, databáze a poštu na aktivním serveru.') }}</p>
                </div>
            </x-wire::section>
        @endif

        @if ($this->events() !== [])
            <x-wire::section :heading="__('Události')">
                <ul class="space-y-1 text-sm">
                    @foreach ($this->events() as $e)
                        <li class="flex gap-3">
                            <span class="shrink-0 text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($e['at'])->format('j. n. H:i:s') }}</span>
                            <span @class(['text-red-600' => $e['level'] === 'danger', 'text-amber-600' => $e['level'] === 'warning'])>{{ $e['message'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-wire::section>
        @endif
    @endif
</div>
