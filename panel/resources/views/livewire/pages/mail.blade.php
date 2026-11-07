<div class="space-y-6">
    @if ($error)
        <x-wire::section>
            <p class="text-sm text-red-600">Stav pošty se nepodařilo načíst: {{ $error }}</p>
        </x-wire::section>
    @else
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2"><x-wire::section heading="Jak to funguje" >
                <div class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
                    <p>Weby posílají poštu přes <strong>Postfix</strong> na tomto serveru (PHP <code>mail()</code> i SMTP na <code>127.0.0.1:25</code>). Postfix přijímá jen z tohoto serveru a každou zprávu podepíše <strong>DKIM</strong> klíčem domény odesílatele.</p>
                    <p>Web v režimu <strong>Zachytávat</strong> posílá poštu do <strong>Mailpitu</strong>: nic neodejde ven a zprávy si prohlédnete v panelu. Hodí se pro vývoj, testy a staging.</p>
                    <p>Aby pošta nepadala do spamu, doména potřebuje DNS záznamy <strong>SPF</strong>, <strong>DKIM</strong> a <strong>DMARC</strong> (níže u každé domény).</p>
                </div>
            </x-wire::section></div>
            <x-wire::section heading="Stav">
                <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                    @foreach (['postfix.service' => 'Postfix', 'opendkim.service' => 'OpenDKIM', 'mailpit.service' => 'Mailpit'] as $unit => $label)
                        <dt class="text-gray-500">{{ $label }}</dt>
                        <dd><x-wire::badge :color="$this->serviceState($unit) === 'active' ? 'success' : 'danger'">{{ $this->serviceState($unit) === 'active' ? 'běží' : $this->serviceState($unit) }}</x-wire::badge></dd>
                    @endforeach
                    <dt class="text-gray-500">Doručování</dt><dd>{{ ($status['relay'] ?? '') !== '' ? 'přes '.$status['relay'] : 'přímo (bez relaye)' }}</dd>
                    <dt class="text-gray-500">Ve frontě</dt><dd>{{ $status['queue'] ?? 0 }} zpráv</dd>
                    <dt class="text-gray-500">Nové weby</dt><dd>{{ ($status['default_mode'] ?? 'send') === 'capture' ? 'zachytávají' : 'odesílají' }}</dd>
                </dl>
            </x-wire::section>
        </div>

        <x-wire::section heading="Weby" description="Režim pošty a DNS záznamy pro každou doménu.">
            <div class="divide-y divide-gray-200 dark:divide-white/10">
                @forelse ($this->sites() as $site)
                    @php($capture = ($status['sites'][$site->domain] ?? $site->mail_mode) === 'capture')
                    <div class="py-4" wire:key="mail-{{ $site->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <span class="font-medium">{{ $site->domain }}</span>
                                <x-wire::badge :color="$capture ? 'warning' : 'success'">{{ $capture ? 'zachytává (Mailpit)' : 'odesílá' }}</x-wire::badge>
                            </div>
                            <div class="flex gap-2">
                                @if ($capture)
                                    <x-wire::button size="sm" color="gray" outlined wire:click="setMode({{ $site->id }}, 'send')">Začít odesílat</x-wire::button>
                                @else
                                    <x-wire::button size="sm" color="gray" outlined wire:click="setMode({{ $site->id }}, 'capture')">Zachytávat v Mailpitu</x-wire::button>
                                @endif
                                @unless (isset($this->dkimKeys()[$site->domain]))
                                    <x-wire::button size="sm" wire:click="createDkim('{{ $site->domain }}')">Vytvořit DKIM klíč</x-wire::button>
                                @endunless
                            </div>
                        </div>
                        @unless ($capture)
                            <div class="mt-3 overflow-x-auto">
                                <table class="w-full text-left text-xs">
                                    <thead class="text-gray-500"><tr><th class="py-1 pe-3">Typ</th><th class="py-1 pe-3">Název</th><th class="py-1 pe-3">Hodnota</th><th class="py-1">K čemu</th></tr></thead>
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
                    <p class="text-sm text-gray-500">Zatím žádné weby.</p>
                @endforelse
            </div>
        </x-wire::section>

        <x-wire::section heading="Log pošty" description="Doručení, odmítnutí a odložené zprávy (Postfix a OpenDKIM).">
            <x-wire::button size="sm" color="gray" outlined icon="outline:arrow-path" wire:click="loadLog">{{ $log === '' ? 'Načíst log' : 'Obnovit' }}</x-wire::button>
            @if ($log !== '')
                <pre class="mt-3 max-h-[28rem] overflow-auto whitespace-pre-wrap break-all rounded-lg bg-gray-950 p-4 font-mono text-xs leading-relaxed text-gray-100">{{ $log }}</pre>
            @endif
        </x-wire::section>
    @endif
</div>
