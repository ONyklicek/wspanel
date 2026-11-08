<div class="space-y-6">
    @if ($error)
        <x-wire::section>
            <p class="text-sm text-red-600">{{ __('Stav pošty se nepodařilo načíst:') }} {{ $error }}</p>
        </x-wire::section>
    @else
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2"><x-wire::section :heading="__('Jak to funguje')" >
                <div class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
                    <p>{{ __('Weby posílají poštu přes') }} <strong>{{ __('Postfix') }}</strong> na tomto serveru (PHP <code>mail()</code> i SMTP na <code>127.0.0.1:25</code>{{ __('). Postfix přijímá jen z tohoto serveru a každou zprávu podepíše') }} <strong>DKIM</strong> {{ __('klíčem domény odesílatele.') }}</p>
                    <p>{{ __('Web v režimu') }} <strong>{{ __('Zachytávat') }}</strong> {{ __('posílá poštu do') }} <strong>{{ __('Mailpitu') }}</strong>{{ __(': nic neodejde ven a zprávy si prohlédnete v panelu. Hodí se pro vývoj, testy a staging.') }}</p>
                    <p>{{ __('Aby pošta nepadala do spamu, doména potřebuje DNS záznamy') }} <strong>SPF</strong>, <strong>DKIM</strong> a <strong>DMARC</strong> {{ __('(níže u každé domény).') }}</p>
                </div>
            </x-wire::section></div>
            <x-wire::section :heading="__('Stav')">
                <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                    @foreach (['postfix.service' => 'Postfix', 'opendkim.service' => 'OpenDKIM', 'mailpit.service' => 'Mailpit'] as $unit => $label)
                        <dt class="text-gray-500">{{ $label }}</dt>
                        <dd><x-wire::badge :color="$this->serviceState($unit) === 'active' ? 'success' : 'danger'">{{ $this->serviceState($unit) === 'active' ? __('běží') : $this->serviceState($unit) }}</x-wire::badge></dd>
                    @endforeach
                    <dt class="text-gray-500">{{ __('Doručování') }}</dt><dd>{{ ($status['relay'] ?? '') !== '' ? __('přes ').$status['relay'] : __('přímo (bez relaye)') }}</dd>
                    <dt class="text-gray-500">{{ __('Ve frontě') }}</dt><dd>{{ $status['queue'] ?? 0 }} {{ __('zpráv') }}</dd>
                    <dt class="text-gray-500">{{ __('Nové weby') }}</dt><dd>{{ ($status['default_mode'] ?? 'send') === 'capture' ? __('zachytávají') : __('odesílají') }}</dd>
                </dl>
            </x-wire::section>
        </div>

        <x-wire::section :heading="__('Weby')" :description="__('Režim pošty a DNS záznamy pro každou doménu.')">
            <div class="divide-y divide-gray-200 dark:divide-white/10">
                @forelse ($this->sites() as $site)
                    @php($capture = ($status['sites'][$site->domain] ?? $site->mail_mode) === 'capture')
                    <div class="py-4" wire:key="mail-{{ $site->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <span class="font-medium">{{ $site->domain }}</span>
                                <x-wire::badge :color="$capture ? 'warning' : 'success'">{{ $capture ? __('zachytává (Mailpit)') : __('odesílá') }}</x-wire::badge>
                            </div>
                            <div class="flex gap-2">
                                @if ($capture)
                                    <x-wire::button size="sm" color="gray" outlined wire:click="setMode({{ $site->id }}, 'send')">{{ __('Začít odesílat') }}</x-wire::button>
                                @else
                                    <x-wire::button size="sm" color="gray" outlined wire:click="setMode({{ $site->id }}, 'capture')">{{ __('Zachytávat v Mailpitu') }}</x-wire::button>
                                @endif
                                @unless (isset($this->dkimKeys()[$site->domain]))
                                    <x-wire::button size="sm" wire:click="createDkim('{{ $site->domain }}')">{{ __('Vytvořit DKIM klíč') }}</x-wire::button>
                                @endunless
                            </div>
                        </div>
                        @unless ($capture)
                            <div class="mt-3 overflow-x-auto">
                                <table class="w-full text-left text-xs">
                                    <thead class="text-gray-500"><tr><th class="py-1 pe-3">{{ __('Typ') }}</th><th class="py-1 pe-3">{{ __('Název') }}</th><th class="py-1 pe-3">{{ __('Hodnota') }}</th><th class="py-1">{{ __('K čemu') }}</th></tr></thead>
                                    <tbody>
                                        @foreach ($this->dnsRecords($site->domain) as $r)
                                            <tr class="align-top">
                                                <td class="py-1 pe-3 font-mono">{{ $r['type'] }}</td>
                                                <td class="py-1 pe-3 font-mono">{{ $r['name'] }}</td>
                                                <td class="max-w-xl py-1 pe-3 font-mono break-all select-all">{{ $r['value'] }}</td>
                                                <td class="py-1 text-gray-500">{{ $r['note'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endunless
                    </div>
                @empty
                    <p class="text-sm text-gray-500">{{ __('Zatím žádné weby.') }}</p>
                @endforelse
            </div>
        </x-wire::section>

        <x-wire::section :heading="__('Fronta')" :description="__('Zprávy, které čekají na doručení (server příjemce je nedostupný nebo je odložil), a proč.')">
            <div class="flex flex-wrap gap-2">
                <x-wire::button size="sm" color="gray" outlined icon="outline:arrow-path" wire:click="loadQueue">{{ $queue === null ? __('Načíst frontu') : 'Obnovit' }}</x-wire::button>
                @if (! empty($queue))
                    <x-wire::button size="sm" color="gray" outlined icon="outline:paper-airplane" wire:click="flushQueue">{{ __('Odeslat znovu') }}</x-wire::button>
                    <x-wire::button size="sm" color="danger" outlined icon="outline:trash" wire:click="deleteQueued" wire:confirm="{{ __('Smazat všechny zprávy ve frontě? Nebudou doručeny.') }}">{{ __('Smazat vše') }}</x-wire::button>
                @endif
            </div>
            @if ($queue !== null)
                @if ($queue === [])
                    <p class="mt-3 text-sm text-gray-500">{{ __('Fronta je prázdná.') }}</p>
                @else
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="text-gray-500"><tr><th class="py-1 pe-3">{{ __('Přijato') }}</th><th class="py-1 pe-3">{{ __('Od') }}</th><th class="py-1 pe-3">{{ __('Komu') }}</th><th class="py-1 pe-3">{{ __('Důvod') }}</th><th class="py-1"></th></tr></thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                @foreach ($queue as $m)
                                    <tr class="align-top" wire:key="q-{{ $m['id'] }}">
                                        <td class="py-1.5 pe-3 whitespace-nowrap">{{ \Illuminate\Support\Carbon::createFromTimestamp($m['arrival'])->format('j. n. H:i') }}<span class="block font-mono text-gray-400">{{ $m['id'] }} · {{ $m['queue'] }}</span></td>
                                        <td class="py-1.5 pe-3 font-mono break-all">{{ $m['sender'] ?: '<>' }}</td>
                                        <td class="py-1.5 pe-3 font-mono break-all">{{ implode(', ', $m['recipients'] ?? []) }}</td>
                                        <td class="max-w-md py-1.5 pe-3 break-words text-gray-500">{{ $m['reason'] ?? '' }}</td>
                                        <td class="py-1.5"><x-wire::button size="xs" color="gray" outlined wire:click="deleteQueued('{{ $m['id'] }}')">{{ __('Smazat') }}</x-wire::button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif
        </x-wire::section>

        <x-wire::section :heading="__('Log pošty')" :description="__('Doručení, odmítnutí a odložené zprávy (Postfix a OpenDKIM).')">
            <x-wire::button size="sm" color="gray" outlined icon="outline:arrow-path" wire:click="loadLog">{{ $log === '' ? __('Načíst log') : 'Obnovit' }}</x-wire::button>
            @if ($log !== '')
                <pre class="mt-3 max-h-[28rem] overflow-auto whitespace-pre-wrap break-all rounded-lg bg-gray-950 p-4 font-mono text-xs leading-relaxed text-gray-100">{{ $log }}</pre>
            @endif
        </x-wire::section>
    @endif
</div>
