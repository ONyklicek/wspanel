<div class="space-y-6">
    @if ($error)
        <x-wire::callout color="danger" :heading="__('Stav databázových serverů se nepodařilo načíst')">{{ $error }}</x-wire::callout>
    @elseif ($servers === [])
        <x-wire::empty-state icon="outline:circle-stack" :heading="__('Žádný databázový server')"
            :description="__('MariaDB ani PostgreSQL nejsou nainstalované. Doinstalujete je na stránce Databáze (Doinstalovat engine).')" />
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

    @if ($servers !== [])
        <p class="text-sm text-gray-500">
            {{ __('Bez otevření do internetu se ze svého počítače připojíte SSH tunelem, např.') }}
            <code class="rounded bg-gray-100 px-1 dark:bg-white/5">ssh -L 3306:127.0.0.1:3306 {{ 'root@'.$this->host() }}</code>
            a v klientovi server <code class="rounded bg-gray-100 px-1 dark:bg-white/5">127.0.0.1</code>.
        </p>
    @endif
</div>
