@php($packages = collect($updates['packages'] ?? []))
<div class="space-y-6">
    @if ($error)
        <x-wire::callout color="danger" :heading="__('Agent neodpovídá')">{{ $error }}</x-wire::callout>
    @endif
    @if ($updates['reboot_scheduled'] ?? null)
        <x-wire::callout color="info" :heading="__('Restart serveru je naplánovaný')">
            <div class="flex flex-wrap items-center gap-3">
                <span>{{ __('Server se restartuje :when.', ['when' => \App\Livewire\Pages\UpdatesPage::at($updates['reboot_scheduled'])]) }}</span>
                <x-wire::button size="xs" color="gray" outlined wire:click="cancelReboot">{{ __('Zrušit restart') }}</x-wire::button>
            </div>
        </x-wire::callout>
    @endif
    @if ($updates['reboot_required'] ?? false)
        <x-wire::callout color="warning" :heading="__('Server potřebuje restart')">
            {{ __('Aktualizace jádra nebo knihoven se projeví až po restartu serveru') }}{{ ! empty($updates['reboot_packages']) ? ' ('.implode(', ', $updates['reboot_packages']).')' : '' }}.
        </x-wire::callout>
    @endif

    @php($release = \App\Support\PanelRelease::available())
    @php($lastCheck = \App\Support\PanelRelease::last())
    @php($upState = $upgrade['state'] ?? 'none')
    <x-wire::section heading="WSPanel" :description="__('Panel, agent a jejich služby. Aktualizace ponechá nastavení, účty, weby i data; panel je při ní pár minut nedostupný, weby jen na několik sekund.')">
        @if ($upState === 'running')
            {{-- Polled with plain requests: during the upgrade the panel restarts. --}}
            <div x-data="{ down: false }" x-init="setInterval(async () => {
                    try {
                        const r = await fetch(@js(route('updates.upgrade-status')), { cache: 'no-store', headers: { Accept: 'application/json' } });
                        if (! r.ok) { down = true; return; }
                        const s = await r.json();
                        if (s.state !== 'running') { window.location.reload(); }
                    } catch (e) { down = true; }
                }, 5000)">
                <x-wire::callout color="info" :heading="__('Probíhá aktualizace na :version', ['version' => $upgrade['version'] ?? '…'])">
                    <p x-show="! down">{{ __('Stahuje se a instaluje nové vydání. Stránka se po dokončení obnoví sama.') }}</p>
                    <p x-show="down" x-cloak>{{ __('Panel se právě restartuje, chvíli nebude odpovídat. Stránka se obnoví sama.') }}</p>
                    @if (($upgrade['log'] ?? []) !== [])
                        <pre class="mt-2 max-h-48 overflow-auto rounded-lg bg-gray-950 p-2 text-xs text-gray-100">{{ implode("\n", array_slice($upgrade['log'], -12)) }}</pre>
                    @endif
                </x-wire::callout>
            </div>
        @elseif ($upState === 'failed')
            <x-wire::callout color="danger" :heading="__('Aktualizace na :version se nepodařila', ['version' => $upgrade['version'] ?? '?'])">
                <p>{{ __('Panel běží dál na předchozí verzi, nebo se vraťte příkazem na serveru: sudo wspanel-rollback') }}</p>
                @if (($upgrade['log'] ?? []) !== [])
                    <pre class="mt-2 max-h-64 overflow-auto rounded-lg bg-gray-950 p-2 text-xs text-gray-100">{{ implode("\n", array_slice($upgrade['log'], -30)) }}</pre>
                @endif
            </x-wire::callout>
        @elseif ($upState === 'done' && ($upgrade['version'] ?? null) === \App\Support\PanelRelease::current())
            <x-wire::callout color="success" :heading="__('Aktualizováno na :version', ['version' => $upgrade['version']])" />
        @endif

        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
            <span>{{ __('Tento server:') }} <strong class="font-mono">{{ \App\Support\PanelRelease::current() ?? __('sestaveno ze zdrojáků') }}</strong></span>
            @if (\App\Support\PanelRelease::checkable())
                @if ($release)
                    <span>{{ __('Nové vydání:') }}
                        <a href="{{ $release['url'] }}" target="_blank" rel="noopener" class="font-mono font-semibold text-primary-600 hover:underline dark:text-primary-400">{{ $release['version'] }}</a>
                    </span>
                    @if ($upState !== 'running')
                        <x-wire::button size="sm" icon="outline:arrow-down-tray" wire:click="upgradePanel"
                            wire:confirm="{{ __('Aktualizovat WSPanel na :version? Panel bude pár minut nedostupný, weby jen několik sekund. Nastavení, účty i data zůstanou; před aktualizací se uloží stav pro návrat (wspanel-rollback).', ['version' => $release['version']]) }}">{{ __('Aktualizovat na :version', ['version' => $release['version']]) }}</x-wire::button>
                    @endif
                @else
                    @if ($lastCheck['version'] ?? null)
                        <x-wire::badge color="success">{{ __('aktuální') }}</x-wire::badge>
                    @else
                        <span class="text-gray-500">{{ __('nové vydání zatím nezjištěno') }}</span>
                    @endif
                    <x-wire::button size="sm" color="gray" outlined icon="outline:arrow-path" wire:click="checkRelease">{{ __('Zkontrolovat aktualizace') }}</x-wire::button>
                @endif
            @endif
        </div>
        @if (\App\Support\PanelRelease::checkable())
            <p class="mt-2 text-xs text-gray-500">
                {{ __('Kontroluje se jednou denně v :repo', ['repo' => \App\Support\PanelRelease::repo()]) }}@if ($lastCheck['checked_at'] ?? null){{ __(', naposledy :when', ['when' => \Illuminate\Support\Carbon::parse($lastCheck['checked_at'])->diffForHumans()]) }}@endif.
                @if ($lastCheck['error'] ?? null)<span class="text-red-600">{{ $lastCheck['error'] }}</span>@endif
            </p>
            <details class="mt-2 text-xs text-gray-500">
                <summary class="cursor-pointer">{{ __('Aktualizace z příkazové řádky') }}</summary>
                <pre class="mt-1 overflow-x-auto rounded-lg bg-gray-950 px-3 py-2 font-mono text-xs text-gray-100">{{ \App\Support\PanelRelease::upgradeCommand() }}</pre>
            </details>
        @else
            <p class="mt-2 text-xs text-gray-500">{{ __('Tento server nebyl nainstalován z vydání (chybí verze nebo repozitář v PANEL_VERSION a PANEL_RELEASE_REPO), nové verze se proto nekontrolují.') }}</p>
        @endif
    </x-wire::section>

    <x-wire::section :heading="__('Balíčky')" :description="$packages->isEmpty() ? __('Systém je aktuální.') : \App\Support\Lang::count($packages->count(), ':count balíček k aktualizaci', ':count balíčky k aktualizaci', ':count balíčků k aktualizaci').__(', z toho bezpečnostních: :n.', ['n' => $packages->where('security', true)->count()])">
        <div class="flex flex-wrap items-center gap-3">
            <x-wire::button size="sm" color="gray" outlined icon="outline:arrow-path" wire:click="refresh">{{ __('Zkontrolovat znovu') }}</x-wire::button>
            <span class="text-sm text-gray-600 dark:text-gray-300">
                {{ __('Automatické bezpečnostní aktualizace:') }}
                <x-wire::badge :color="($updates['auto_security'] ?? false) ? 'success' : 'gray'">{{ ($updates['auto_security'] ?? false) ? __('zapnuto (denně)') : __('vypnuto') }}</x-wire::badge>
            </span>
            <x-wire::button size="sm" color="gray" outlined wire:click="toggleAuto">{{ ($updates['auto_security'] ?? false) ? __('Vypnout') : __('Zapnout') }}</x-wire::button>
            <span class="text-sm text-gray-600 dark:text-gray-300">
                {{ __('Automatický restart:') }}
                <x-wire::badge :color="($updates['auto_reboot'] ?? false) ? 'success' : 'gray'">{{ ($updates['auto_reboot'] ?? false) ? __('v :time, když je potřeba', ['time' => $updates['auto_reboot_time'] ?: '04:00']) : __('vypnuto') }}</x-wire::badge>
            </span>
            @if ($updates['booted_at'] ?? null)
                <span class="text-sm text-gray-500">{{ __('Server běží od :when', ['when' => \App\Livewire\Pages\UpdatesPage::at($updates['booted_at'])]) }}</span>
            @endif
        </div>
        @if ($packages->isNotEmpty())
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs text-gray-500"><tr><th class="py-1 pe-3 font-normal">{{ __('Balíček') }}</th><th class="py-1 pe-3 font-normal">{{ __('Teď') }}</th><th class="py-1 pe-3 font-normal">{{ __('Nová verze') }}</th><th class="py-1 font-normal"></th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($packages->sortByDesc('security') as $p)
                            <tr>
                                <td class="py-1 pe-3 font-mono text-xs">{{ $p['name'] }}</td>
                                <td class="py-1 pe-3 font-mono text-xs text-gray-500">{{ $p['from'] ?: '—' }}</td>
                                <td class="py-1 pe-3 font-mono text-xs">{{ $p['to'] }}</td>
                                <td class="py-1">@if ($p['security'])<x-wire::badge color="danger">{{ __('bezpečnostní') }}</x-wire::badge>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-wire::section>
</div>
