@php($packages = collect($updates['packages'] ?? []))
<div class="space-y-6">
    @if ($error)
        <x-wire::callout color="danger" :heading="__('Agent neodpovídá')">{{ $error }}</x-wire::callout>
    @endif
    @if ($updates['reboot_required'] ?? false)
        <x-wire::callout color="warning" :heading="__('Server potřebuje restart')">
            {{ __('Aktualizace jádra nebo knihoven se projeví až po restartu serveru') }}{{ ! empty($updates['reboot_packages']) ? ' ('.implode(', ', $updates['reboot_packages']).')' : '' }}.
        </x-wire::callout>
    @endif

    @php($release = \App\Support\PanelRelease::available())
    @php($lastCheck = \App\Support\PanelRelease::last())
    <x-wire::section heading="WSPanel" :description="__('Panel, agent a jejich služby. Aktualizace se spouští na serveru jedním příkazem, nastavení zůstane.')">
        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
            <span>{{ __('Tento server:') }} <strong class="font-mono">{{ \App\Support\PanelRelease::current() ?? __('sestaveno ze zdrojáků') }}</strong></span>
            @if (\App\Support\PanelRelease::checkable())
                <span>{{ __('Nejnovější vydání:') }}
                    @if ($lastCheck['version'] ?? null)
                        <a href="{{ $lastCheck['url'] }}" target="_blank" rel="noopener" class="font-mono font-semibold text-primary-600 hover:underline dark:text-primary-400">{{ $lastCheck['version'] }}</a>
                    @else
                        <span class="text-gray-500">{{ __('zatím nezjištěno') }}</span>
                    @endif
                </span>
                @if ($release)
                    <x-wire::badge color="info">{{ __('k dispozici nová verze') }}</x-wire::badge>
                @elseif ($lastCheck['version'] ?? null)
                    <x-wire::badge color="success">{{ __('aktuální') }}</x-wire::badge>
                @endif
                <x-wire::button size="sm" color="gray" outlined icon="outline:arrow-path" wire:click="checkRelease">{{ __('Zkontrolovat') }}</x-wire::button>
            @endif
        </div>
        @if (\App\Support\PanelRelease::checkable())
            <p class="mt-2 text-xs text-gray-500">
                {{ __('Kontroluje se jednou denně v :repo', ['repo' => \App\Support\PanelRelease::repo()]) }}@if ($lastCheck['checked_at'] ?? null){{ __(', naposledy :when', ['when' => \Illuminate\Support\Carbon::parse($lastCheck['checked_at'])->diffForHumans()]) }}@endif.
                @if ($lastCheck['error'] ?? null)<span class="text-red-600">{{ $lastCheck['error'] }}</span>@endif
            </p>
            <div class="mt-3">
                <p class="mb-1 text-sm">{{ $release ? __('Aktualizace na :version: na serveru jako root spusťte', ['version' => $release['version']]) : __('Aktualizace (až vyjde nová verze): na serveru jako root spusťte') }}</p>
                <pre class="overflow-x-auto rounded-lg bg-gray-950 px-3 py-2 font-mono text-xs text-gray-100">{{ \App\Support\PanelRelease::upgradeCommand() }}</pre>
                <p class="mt-1 text-xs text-gray-500">{{ __('Instalátor nabídne aktualizaci se stávajícím nastavením. Panel je pár minut nedostupný, weby jen na několik sekund (restart proxy a PHP).') }}</p>
            </div>
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
