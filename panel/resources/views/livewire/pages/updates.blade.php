@php($packages = collect($updates['packages'] ?? []))
<div class="space-y-6">
    @if ($error)
        <x-wire::callout color="danger" heading="Agent neodpovídá">{{ $error }}</x-wire::callout>
    @endif
    @if ($updates['reboot_required'] ?? false)
        <x-wire::callout color="warning" heading="Server potřebuje restart">
            Aktualizace jádra nebo knihoven se projeví až po restartu serveru{{ ! empty($updates['reboot_packages']) ? ' ('.implode(', ', $updates['reboot_packages']).')' : '' }}.
        </x-wire::callout>
    @endif

    <x-wire::section heading="Aktualizace systému" :description="$packages->isEmpty() ? 'Systém je aktuální.' : $packages->count().' balíčků k aktualizaci, z toho bezpečnostních '.$packages->where('security', true)->count().'.'">
        <div class="flex flex-wrap items-center gap-3">
            <x-wire::button size="sm" color="gray" outlined icon="outline:arrow-path" wire:click="refresh">Zkontrolovat znovu</x-wire::button>
            <span class="text-sm text-gray-600 dark:text-gray-300">
                Automatické bezpečnostní aktualizace:
                <x-wire::badge :color="($updates['auto_security'] ?? false) ? 'success' : 'gray'">{{ ($updates['auto_security'] ?? false) ? 'zapnuto (denně)' : 'vypnuto' }}</x-wire::badge>
            </span>
            <x-wire::button size="sm" color="gray" outlined wire:click="toggleAuto">{{ ($updates['auto_security'] ?? false) ? 'Vypnout' : 'Zapnout' }}</x-wire::button>
        </div>
        @if ($packages->isNotEmpty())
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs text-gray-500"><tr><th class="py-1 pe-3 font-normal">Balíček</th><th class="py-1 pe-3 font-normal">Teď</th><th class="py-1 pe-3 font-normal">Nová verze</th><th class="py-1 font-normal"></th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($packages->sortByDesc('security') as $p)
                            <tr>
                                <td class="py-1 pe-3 font-mono text-xs">{{ $p['name'] }}</td>
                                <td class="py-1 pe-3 font-mono text-xs text-gray-500">{{ $p['from'] ?: '—' }}</td>
                                <td class="py-1 pe-3 font-mono text-xs">{{ $p['to'] }}</td>
                                <td class="py-1">@if ($p['security'])<x-wire::badge color="danger">bezpečnostní</x-wire::badge>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-wire::section>

    <x-wire::section heading="Zablokované adresy (fail2ban)" description="Adresy, které opakovaně selhaly při přihlášení (SSH, pošta…), jsou na čas zablokované.">
        @php($banned = collect($bans)->filter())
        @if ($banned->isEmpty())
            <p class="text-sm text-gray-500">Žádná adresa teď není zablokovaná{{ $bans ? ' (hlídá: '.implode(', ', array_keys($bans)).')' : '' }}.</p>
        @else
            <table class="w-full text-left text-sm">
                <thead class="text-xs text-gray-500"><tr><th class="py-1 pe-3 font-normal">Adresa</th><th class="py-1 pe-3 font-normal">Kde</th><th class="py-1"></th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($banned as $jail => $ips)
                        @foreach ($ips as $ip)
                            <tr wire:key="ban-{{ $jail }}-{{ $ip }}">
                                <td class="py-1 pe-3 font-mono text-xs">{{ $ip }}</td>
                                <td class="py-1 pe-3 text-gray-500">{{ $jail }}</td>
                                <td class="py-1 text-right"><x-wire::button size="xs" color="gray" outlined wire:click="unban('{{ $ip }}')">Odblokovat</x-wire::button></td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-wire::section>
</div>
