@php
    $byVersion = $this->sitesByVersion();
    $franken = $status['frankenphp'] ?? [];
@endphp
<div class="space-y-6">
    @include('partials.background-jobs')
    @if ($error)
        <x-wire::callout color="danger" :heading="__('Stav PHP se nepodařilo načíst')">{{ $error }}</x-wire::callout>
    @endif

    <div class="grid gap-6 xl:grid-cols-2">
        @foreach ($status['versions'] ?? [] as $v)
            @php($sites = $byVersion[$v['version']] ?? [])
            <div wire:key="php-{{ $v['version'] }}" class="flex flex-col rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-4 sm:px-6 dark:border-gray-700">
                    <h3 class="flex items-center gap-2 text-base font-semibold">
                        PHP {{ $v['version'] }}
                        <span class="text-sm font-normal text-gray-500">PHP-FPM</span>
                        @if ($v['default'])
                            <x-wire::badge color="primary" title="{{ __('výchozí pro nové weby') }}">{{ __('výchozí') }}</x-wire::badge>
                        @endif
                        @unless ($v['installed'])
                            <x-wire::badge color="danger">{{ __('chybí') }}</x-wire::badge>
                        @endunless
                    </h3>
                    <div class="flex gap-2">
                        <x-wire-actions::button :action="$this->addExtensionAction($v['version'])" />
                        <x-wire-actions::button :action="$this->removeExtensionAction($v['version'])" />
                    </div>
                </div>
                <div class="flex-1 space-y-4 px-4 py-4 text-sm sm:px-6">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Weby (') }}{{ count($sites) }})</p>
                        <p class="mt-1">{{ $sites === [] ? __('žádný web tuto verzi nepoužívá') : implode(', ', $sites) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Rozšíření (') }}{{ count($v['extensions']) }})</p>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @forelse ($v['extensions'] as $ext)
                                <x-wire::badge color="gray">{{ $ext }}</x-wire::badge>
                            @empty
                                <span class="text-gray-500">{{ __('jen jádro PHP') }}</span>
                            @endforelse
                        </div>
                        <p class="mt-2 text-xs text-gray-500">{{ __('K dispozici v repozitáři:') }} {{ count(array_diff($v['available'], $v['extensions'])) }} {{ __('dalších.') }}</p>
                    </div>
                </div>
            </div>
        @endforeach

        <div class="flex flex-col rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
            <div class="border-b border-gray-200 px-4 py-4 sm:px-6 dark:border-gray-700">
                <h3 class="flex items-center gap-2 text-base font-semibold">
                    {{ __('FrankenPHP') }}
                    @isset($franken['php'])<span class="text-sm font-normal text-gray-500">PHP {{ $franken['php'] }} {{ __('· worker režim') }}</span>@endisset
                </h3>
            </div>
            <div class="flex-1 space-y-4 px-4 py-4 text-sm sm:px-6">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Verze') }}</p>
                    <p class="mt-1 font-mono text-xs">{{ $franken['version'] ?? __('neznámá') }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Weby (') }}{{ count($this->workerSites()) }})</p>
                    <p class="mt-1">{{ $this->workerSites() === [] ? __('žádný web neběží ve worker režimu') : implode(', ', $this->workerSites()) }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Zabudovaná rozšíření (') }}{{ count($franken['extensions'] ?? []) }})</p>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach ($franken['extensions'] ?? [] as $ext)
                            <x-wire::badge color="gray">{{ $ext }}</x-wire::badge>
                        @endforeach
                    </div>
                    <p class="mt-2 text-xs text-gray-500">{{ __('FrankenPHP je jedna binárka s pevnou sadou rozšíření; nová verze se instaluje v Server → Služby. Hodnoty php.ini a počet workerů nastavíte u každého webu v Nastavení → Běh a PHP.') }}</p>
                </div>
            </div>
        </div>
    </div>
</div>
