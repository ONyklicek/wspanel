@php($size = fn ($b) => \Illuminate\Support\Number::fileSize((int) $b, 1))
@php($state = $status['state'] ?? null)
<div class="wire-resource-page space-y-4 sm:space-y-6" wire:poll.15s="refreshStatus">
    @include('wire-panels::pages.partials.header')

    @if ($error)
        <x-wire::callout color="danger" :heading="__('Agent neodpovídá')">{{ $error }}</x-wire::callout>
    @endif

    <x-wire::section>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <dl class="grid flex-1 gap-x-8 gap-y-3 text-sm sm:grid-cols-4">
                <div><dt class="text-xs text-gray-500">{{ __('Stav') }}</dt>
                    <dd>
                        @if ($server->status === 'installing')
                            <x-wire::badge color="warning">{{ __('instaluje se') }}</x-wire::badge>
                        @elseif ($state === 'active')
                            <x-wire::badge color="success">{{ __('běží') }}</x-wire::badge>
                        @elseif (in_array($state, ['activating', 'reloading'], true))
                            <x-wire::badge color="warning">{{ __('spouští se') }}</x-wire::badge>
                        @elseif ($state === 'failed')
                            <x-wire::badge color="danger">{{ __('spadl') }}</x-wire::badge>
                        @else
                            <x-wire::badge color="gray">{{ __('zastaveno') }}</x-wire::badge>
                        @endif
                    </dd></div>
                <div><dt class="text-xs text-gray-500">{{ __('Adresa pro hráče') }}</dt><dd class="font-mono">{{ $server->address() }}</dd></div>
                <div><dt class="text-xs text-gray-500">{{ __('Hráči') }}</dt>
                    <dd>
                        @if (isset($status['players']))
                            {{ $status['players']['online'] }}{{ isset($status['players']['max']) ? ' / '.$status['players']['max'] : '' }}
                            @if ($status['players']['names'] !== [])<span class="text-xs text-gray-500">({{ implode(', ', $status['players']['names']) }})</span>@endif
                        @else
                            —
                        @endif
                    </dd></div>
                <div><dt class="text-xs text-gray-500">{{ __('Paměť') }}</dt>
                    <dd>{{ isset($status['memory_bytes']) ? $size($status['memory_bytes']) : '—' }} <span class="text-xs text-gray-500">/ {{ $size($server->memory_mb * 1048576) }}</span></dd></div>
                @if ($server->sftp)
                    <div class="sm:col-span-4 text-xs text-gray-500">SFTP: <span class="font-mono">sftp -P {{ config('panel.ssh_port') }} {{ $server->sftpTarget() }}</span></div>
                @endif
                <div class="sm:col-span-4 text-xs text-gray-500">{{ $server->game->label() }} · {{ $server->versionLabel() }} · {{ __('port') }} {{ $server->port }}/{{ $server->game->proto() }}@if ($server->cpu_percent) · {{ __('CPU :n %', ['n' => $server->cpu_percent]) }}@endif</div>
            </dl>
            <div class="flex gap-2">
                @if ($state === 'active' || $state === 'activating')
                    <x-wire::button size="sm" color="gray" outlined icon="outline:arrow-path" wire:click="control('restart')">{{ __('Restartovat') }}</x-wire::button>
                    <x-wire::button size="sm" color="danger" outlined icon="outline:stop" wire:click="control('stop')" wire:confirm="{{ __('Zastavit server? Hráči se odpojí.') }}">{{ __('Zastavit') }}</x-wire::button>
                @else
                    <x-wire::button size="sm" icon="outline:play" wire:click="control('start')">{{ __('Spustit') }}</x-wire::button>
                @endif
            </div>
        </div>
    </x-wire::section>

    <x-wire::section :heading="__('Konzole')" :description="$server->game === \App\Enums\Game::Minecraft ? __('Příkazy serveru, např. list, say Ahoj, op Jmeno, whitelist add Jmeno.') : __('Příkazy serveru, např. /players, /admins, /promote Jmeno.')">
        <div class="max-h-80 overflow-y-auto rounded-lg bg-gray-950 p-3 font-mono text-xs text-gray-100">
            @forelse ($console as $c)
                <div class="text-emerald-400">&gt; {{ $c['command'] }}</div>
                <div class="mb-2 whitespace-pre-wrap">{{ $c['output'] !== '' ? $c['output'] : __('(bez odpovědi)') }}</div>
            @empty
                <div class="text-gray-500">{{ __('Zatím žádný příkaz.') }}</div>
            @endforelse
        </div>
        <form wire:submit="send" class="mt-2 flex gap-2">
            <input type="text" wire:model="command" maxlength="1000" autocomplete="off" placeholder="{{ __('Příkaz') }}" class="flex-1 rounded-lg border-gray-300 font-mono text-sm dark:border-white/10 dark:bg-white/5" @disabled($state !== 'active')>
            <x-wire::button type="submit" size="sm" :disabled="$state !== 'active'">{{ __('Odeslat') }}</x-wire::button>
        </form>
        @error('command')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </x-wire::section>

    <x-wire::section :heading="__('Log')">
        @if ($logs === null)
            <x-wire::button size="sm" color="gray" outlined icon="outline:document-text" wire:click="loadLogs">{{ __('Zobrazit log') }}</x-wire::button>
        @else
            <div class="mb-2"><x-wire::button size="xs" color="gray" outlined icon="outline:arrow-path" wire:click="loadLogs">{{ __('Obnovit') }}</x-wire::button></div>
            <pre class="max-h-96 overflow-auto rounded-lg bg-gray-950 p-3 text-xs text-gray-100">{{ implode("\n", $logs) ?: __('Log je prázdný.') }}</pre>
        @endif
    </x-wire::section>

    <x-wire::section :heading="__('Zálohy')" :description="__('Data serveru (svět, nastavení, pluginy či módy), každou noc a kdykoli tlačítkem; se zapnutými zálohami mimo server se kopírují i tam. Na serveru se uchovává posledních 10 záloh. Obnova vrátí data do stavu zálohy, server se na chvíli zastaví.')">
        <div class="flex flex-wrap gap-2">
            <x-wire::button size="sm" icon="outline:archive-box" wire:click="backupNow">{{ __('Zálohovat teď') }}</x-wire::button>
            @if ($backups === null)
                <x-wire::button size="sm" color="gray" outlined wire:click="loadBackups">{{ __('Zobrazit zálohy') }}</x-wire::button>
            @endif
            <span wire:loading wire:target="backupNow,restore" class="text-sm text-gray-500">{{ __('Probíhá…') }}</span>
        </div>
        @if ($backups !== null)
            @if ($backups === [])
                <p class="mt-3 text-sm text-gray-500">{{ __('Zatím žádná záloha.') }}</p>
            @else
                <table class="mt-3 w-full text-left text-sm">
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($backups as $b)
                            <tr wire:key="b-{{ $b['file'] }}">
                                <td class="py-1 pe-3">{{ \Illuminate\Support\Carbon::parse($b['created_at'])->setTimezone(config('app.timezone'))->isoFormat('D. M. YYYY HH:mm') }}</td>
                                <td class="py-1 pe-3 text-xs text-gray-500">{{ $size($b['size']) }}</td>
                                <td class="py-1 text-right"><x-wire::button size="xs" color="gray" outlined wire:click="restore(@js($b['file']))" wire:confirm="{{ __('Obnovit data serveru ze zálohy? Současný stav se přepíše.') }}">{{ __('Obnovit') }}</x-wire::button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            @php($local = collect($backups)->pluck('file'))
            @php($remoteOnly = collect($offsite)->reject(fn ($o) => $local->contains($o['file'])))
            @if ($remoteOnly->isNotEmpty())
                <p class="mt-4 text-sm font-medium">{{ __('Jen mimo server') }}</p>
                <table class="mt-1 w-full text-left text-sm">
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($remoteOnly as $o)
                            <tr wire:key="o-{{ $o['file'] }}">
                                <td class="py-1 pe-3 font-mono text-xs">{{ $o['file'] }}</td>
                                <td class="py-1 pe-3 text-xs text-gray-500">{{ $size($o['size']) }}</td>
                                <td class="py-1 text-right"><x-wire::button size="xs" color="gray" outlined wire:click="fetchOffsite(@js($o['file']))">{{ __('Stáhnout zpět') }}</x-wire::button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @elseif ($offsite !== [])
                <p class="mt-3 text-xs text-gray-500">{{ \App\Support\Lang::count(count($offsite), ':count kopie mimo server', ':count kopie mimo server', ':count kopií mimo server') }}</p>
            @endif
        @endif
    </x-wire::section>

    @include('wire-panels::pages.partials.action-modals')
</div>
