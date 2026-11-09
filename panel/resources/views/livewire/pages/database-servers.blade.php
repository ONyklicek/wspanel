<div class="space-y-6">
    @include('partials.background-jobs')
    @if ($error)
        <x-wire::callout color="danger" :heading="__('Stav databázových serverů se nepodařilo načíst')">{{ $error }}</x-wire::callout>
    @elseif ($servers === [])
        <x-wire::empty-state icon="outline:circle-stack" :heading="__('Žádný databázový server')"
            :description="__('MariaDB ani PostgreSQL nejsou nainstalované. Doinstalujete je tlačítkem Doinstalovat engine.')" />
    @endif

    @foreach ($servers as $engine => $s)
        @php
            $access = \App\Actions\Databases\ManageDatabaseServers::access($s);
            $label = $this::engineLabel($engine);
        @endphp
        <div wire:key="server-{{ $engine }}" class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
            <div class="flex flex-col gap-3 border-b border-gray-200 px-4 py-4 sm:px-6 lg:flex-row lg:items-center lg:justify-between dark:border-gray-700">
                <h3 class="flex flex-wrap items-center gap-2 text-base font-semibold text-gray-900 dark:text-white">
                    {{ $label }}
                    @isset($s['version'])<span class="text-sm font-normal text-gray-500">{{ $s['version'] }}</span>@endisset
                    @if ($access === 'anywhere')
                        <x-wire::badge color="danger" icon="outline:globe-europe-africa">{{ __('z celého internetu') }}</x-wire::badge>
                    @elseif ($access === 'allowlist')
                        <x-wire::badge color="warning" icon="outline:shield-check">{{ __('z vybraných adres') }}</x-wire::badge>
                    @else
                        <x-wire::badge color="gray" icon="outline:lock-closed">jen tento server</x-wire::badge>
                    @endif
                </h3>
                <div class="flex flex-wrap gap-2">
                    @foreach ($this->tools($engine) as $tool)
                        <x-wire::button size="sm" color="gray" icon="outline:table-cells" :href="route('dbtools.server', [$engine, $tool])" target="_blank">
                            {{ $tool === 'phpmyadmin' ? 'phpMyAdmin' : 'Adminer' }} {{ __('· všechny databáze') }}
                        </x-wire::button>
                    @endforeach
                    <x-wire-actions::button :action="$this->rootPasswordAction($engine)" />
                    <x-wire-actions::button :action="$this->accessAction($engine)" />
                </div>
            </div>

            <dl class="px-4 py-4 sm:px-6 grid gap-x-8 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Superuživatel') }}</dt>
                    <dd class="mt-1">
                        <span class="font-mono">{{ $s['root_user'] }}</span>
                        @if ($s['root_password'])
                            <span class="text-gray-500">· heslo nastaveno{{ $this->setAt($engine) ? ' '.$this->setAt($engine) : '' }}</span>
                        @else
                            <span class="text-gray-500">· bez hesla, jen ze serveru</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Adresa') }}</dt>
                    <dd class="mt-1 font-mono">{{ $this->host() }}:{{ $s['port'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Povolené adresy') }}</dt>
                    <dd class="mt-1">
                        @if ($access === 'anywhere')
                            {{ __('všechny') }}
                        @elseif ($access === 'allowlist')
                            <span class="font-mono">{{ implode(', ', $s['allow']) }}</span>
                        @else
                            <span class="text-gray-500">{{ __('žádné (port') }} {{ $s['port'] }} {{ __('je zavřený)') }}</span>
                        @endif
                    </dd>
                </div>
                @if ($access !== 'local')
                    <div class="sm:col-span-2 lg:col-span-3">
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Připojení') }}</dt>
                        <dd class="mt-2 space-y-2">
                            @foreach ($this->examples($engine) as $what => $cmd)
                                <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-3">
                                    <span class="w-32 shrink-0 text-gray-500">{{ $what }}</span>
                                    <code class="block overflow-x-auto rounded-md bg-gray-100 px-2 py-1 text-xs dark:bg-white/5">{{ $cmd }}</code>
                                </div>
                            @endforeach
                            <p class="text-gray-500">
                                {{ __('Šifrování (TLS) je povinné.') }}
                                @isset($s['tls_sha256'])
                                    {{ __('Certifikát serveru je vlastní (self-signed), otisk SHA-256:') }}
                                    <span class="break-all font-mono text-xs">{{ $s['tls_sha256'] }}</span>
                                @else
                                    {{ __('PostgreSQL používá certifikát serveru; v klientovi zvolte') }} <code>sslmode=require</code>.
                                @endisset
                            </p>
                        </dd>
                    </div>
                @endif
            </dl>
        </div>
    @endforeach


    @if ($instances !== [])
        <div class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
            <div class="border-b border-gray-200 px-4 py-4 sm:px-6 dark:border-gray-700">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Vyhrazené servery webů') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Vlastní databázový server jednoho webu: vlastní uživatel a data, bez sítě, připojí se jen ten web (přes socket). Smazat jde, až v něm nejsou žádné databáze.') }}</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/5">
                        <tr>
                            <th class="py-2 ps-4 pe-3 font-medium sm:ps-6">{{ __('Web') }}</th>
                            <th class="py-2 pe-3 font-medium">{{ __('Server') }}</th>
                            <th class="py-2 pe-3 font-medium">{{ __('Limity') }}</th>
                            <th class="py-2 pe-3 font-medium">{{ __('Databáze') }}</th>
                            <th class="py-2 pe-4 sm:pe-6"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($instances as $i)
                            @php($running = ($i['active'] ?? '') === 'active')
                            <tr wire:key="inst-{{ $i['id'] }}">
                                <td class="py-2 ps-4 pe-3 sm:ps-6">
                                    <span class="font-medium">{{ $i['domain'] }}</span>
                                    <div class="font-mono text-xs text-gray-500" title="{{ __('Připojení webu') }}">{{ ($i['socket'] ?? '') ?: $i['host'].':'.$i['port'] }}</div>
                                </td>
                                <td class="py-2 pe-3">
                                    {{ $this::engineLabel($i['engine']) }} <span class="text-xs text-gray-500">{{ $i['version'] ?? '' }}</span>
                                    <div><x-wire::badge :color="$running ? 'success' : (($i['active'] ?? '') === 'failed' ? 'danger' : 'gray')">{{ $running ? __('běží') : (($i['active'] ?? '') === 'failed' ? __('chyba') : __('zastaveno')) }}</x-wire::badge></div>
                                </td>
                                <td class="py-2 pe-3 text-xs">
                                    {{ \Illuminate\Support\Number::fileSize($i['memory_mb'] * 1048576) }}
                                    · {{ ($i['cpu_percent'] ?? 0) > 0 ? __(':n % CPU', ['n' => $i['cpu_percent']]) : __('CPU bez limitu') }}
                                    <div class="text-gray-500">{{ __('data :size', ['size' => \Illuminate\Support\Number::fileSize((int) ($i['size'] ?? 0), 1)]) }}</div>
                                </td>
                                <td class="py-2 pe-3 font-mono text-xs">{{ $i['databases'] === [] ? '—' : implode(', ', $i['databases']) }}</td>
                                <td class="whitespace-nowrap py-2 pe-4 text-right sm:pe-6">
                                    <div class="inline-flex flex-wrap justify-end gap-1">
                                        <x-wire-actions::button :action="$this->instanceLimitsAction((int) $i['id'])" />
                                        @if ($running)
                                            <x-wire::button size="sm" color="gray" outlined wire:click="controlInstance({{ (int) $i['id'] }}, 'restart')" wire:confirm="{{ __('Restartovat databázový server webu :domain?', ['domain' => $i['domain']]) }}">{{ __('Restartovat') }}</x-wire::button>
                                            <x-wire::button size="sm" color="gray" outlined wire:click="controlInstance({{ (int) $i['id'] }}, 'stop')" wire:confirm="{{ __('Zastavit databázový server webu :domain? Web se k databázi nepřipojí.', ['domain' => $i['domain']]) }}">{{ __('Zastavit') }}</x-wire::button>
                                        @else
                                            <x-wire::button size="sm" color="success" outlined wire:click="controlInstance({{ (int) $i['id'] }}, 'start')">{{ __('Spustit') }}</x-wire::button>
                                        @endif
                                        @if ($i['databases'] === [])
                                            <x-wire::button size="sm" color="danger" outlined wire:click="deleteInstance({{ (int) $i['id'] }})" wire:confirm="{{ __('Smazat vyhrazený server webu :domain?', ['domain' => $i['domain']]) }}">{{ __('Smazat') }}</x-wire::button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($servers !== [])
        <p class="text-sm text-gray-500">
            {{ __('Bez otevření do internetu se ze svého počítače připojíte SSH tunelem, např.') }}
            <code class="rounded bg-gray-100 px-1 dark:bg-white/5">ssh -L 3306:127.0.0.1:3306 {{ 'root@'.$this->host() }}</code>
            a v klientovi server <code class="rounded bg-gray-100 px-1 dark:bg-white/5">127.0.0.1</code>.
        </p>
    @endif
</div>
