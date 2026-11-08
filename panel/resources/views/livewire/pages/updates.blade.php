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

    <x-wire::section :heading="__('Aktualizace systému')" :description="$packages->isEmpty() ? __('Systém je aktuální.') : $packages->count().__(' balíčků k aktualizaci, z toho bezpečnostních ').$packages->where('security', true)->count().'.'">
        <div class="flex flex-wrap items-center gap-3">
            <x-wire::button size="sm" color="gray" outlined icon="outline:arrow-path" wire:click="refresh">{{ __('Zkontrolovat znovu') }}</x-wire::button>
            <span class="text-sm text-gray-600 dark:text-gray-300">
                {{ __('Automatické bezpečnostní aktualizace:') }}
                <x-wire::badge :color="($updates['auto_security'] ?? false) ? 'success' : 'gray'">{{ ($updates['auto_security'] ?? false) ? __('zapnuto (denně)') : 'vypnuto' }}</x-wire::badge>
            </span>
            <x-wire::button size="sm" color="gray" outlined wire:click="toggleAuto">{{ ($updates['auto_security'] ?? false) ? 'Vypnout' : 'Zapnout' }}</x-wire::button>
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
