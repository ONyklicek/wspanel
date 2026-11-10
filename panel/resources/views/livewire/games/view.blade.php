@php($size = fn ($b) => \Illuminate\Support\Number::fileSize((int) $b, 1))
<div class="wire-resource-page space-y-4 sm:space-y-6">
    @include('wire-panels::pages.partials.header')

    @if ($server->status === 'failed')
        <x-wire::callout color="danger" :heading="__('Instalace selhala')">{{ $server->error ?: __('Podrobnosti jsou v oznámení úlohy.') }}</x-wire::callout>
    @endif

    @livewire(\App\Livewire\Games\GameServerStatus::class, ['server' => $server->getKey()], key('game-status-'.$server->getKey()))

    @livewire(\App\Livewire\Games\GameConsole::class, ['server' => $server->getKey()], key('game-console-'.$server->getKey()))

    <x-wire::section wire:init="loadBackups" :heading="__('Zálohy')" :description="__('Data serveru (svět, nastavení, pluginy či módy), každou noc a kdykoli tlačítkem; se zapnutými zálohami mimo server se kopírují i tam. Na serveru se uchovává posledních 10 záloh. Obnova vrátí data do stavu zálohy, server se na chvíli zastaví.')">
        <div class="flex flex-wrap gap-2">
            <x-wire::button size="sm" icon="outline:archive-box" wire:click="backupNow">{{ __('Zálohovat teď') }}</x-wire::button>
            @if ($backups === null)
                <span class="text-sm text-gray-500">{!! icon('outline:arrow-path', 'inline size-4 animate-spin') !!} {{ __('Načítám zálohy…') }}</span>
            @endif
            <span wire:loading wire:target="backupNow,restore" class="text-sm text-gray-500">{{ __('Probíhá…') }}</span>
        </div>
        @if ($backups !== null)
            @if ($backups === [])
                <p class="mt-3 text-sm text-gray-500">{{ __('Zatím žádná záloha.') }}</p>
            @else
                <div class="overflow-x-auto">
                <table class="mt-3 w-full text-left text-sm">
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($backups as $b)
                            <tr wire:key="b-{{ $b['file'] }}">
                                <td class="py-1 pe-3">{{ \Illuminate\Support\Carbon::parse($b['created_at'])->setTimezone(config('app.timezone'))->isoFormat('D. M. YYYY HH:mm') }}</td>
                                <td class="py-1 pe-3 text-xs text-gray-500">{{ $size($b['size']) }}</td>
                                <td class="py-1 text-right"><x-wire::button size="xs" color="gray" outlined wire:click="restore({{ \Illuminate\Support\Js::from($b['file']) }})" wire:confirm="{{ __('Obnovit data serveru ze zálohy? Současný stav se přepíše.') }}">{{ __('Obnovit') }}</x-wire::button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            @endif
            @php($local = collect($backups)->pluck('file'))
            @php($remoteOnly = collect($offsite)->reject(fn ($o) => $local->contains($o['file'])))
            @if ($remoteOnly->isNotEmpty())
                <p class="mt-4 text-sm font-medium">{{ __('Jen mimo server') }}</p>
                <div class="overflow-x-auto">
                <table class="mt-1 w-full text-left text-sm">
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($remoteOnly as $o)
                            <tr wire:key="o-{{ $o['file'] }}">
                                <td class="py-1 pe-3 font-mono text-xs">{{ $o['file'] }}</td>
                                <td class="py-1 pe-3 text-xs text-gray-500">{{ $size($o['size']) }}</td>
                                <td class="py-1 text-right"><x-wire::button size="xs" color="gray" outlined wire:click="fetchOffsite({{ \Illuminate\Support\Js::from($o['file']) }})">{{ __('Stáhnout zpět') }}</x-wire::button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            @elseif ($offsite !== [])
                <p class="mt-3 text-xs text-gray-500">{{ \App\Support\Lang::count(count($offsite), ':count kopie mimo server', ':count kopie mimo server', ':count kopií mimo server') }}</p>
            @endif
        @endif
    </x-wire::section>

    @include('wire-panels::pages.partials.action-modals')
</div>
