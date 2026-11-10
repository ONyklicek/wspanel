@php($size = fn ($b) => \Illuminate\Support\Number::fileSize((int) $b, 1))
@php($memLimit = $server->memory_mb * 1048576)
@php($mem = (int) ($live['memory_bytes'] ?? 0))
@php($memPct = $memLimit > 0 ? min(100, round($mem / $memLimit * 100)) : 0)
@php($cpuLimit = $server->cpu_percent > 0 ? $server->cpu_percent : 100)
@php($players = $live['players'] ?? null)
@php($busy = in_array($shown, ['starting', 'stopping'], true))
@php($dot = ['success' => 'bg-emerald-500', 'warning' => 'bg-amber-500', 'danger' => 'bg-red-500'][$badge['color']] ?? 'bg-gray-400')
<div wire:poll.{{ $interval }}ms="refresh" data-testid="game-status" data-state="{{ $shown }}"
    class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 sm:px-6 dark:border-gray-700">
        <div class="flex min-w-0 items-center gap-3">
            <span class="relative flex size-3 shrink-0">
                @if ($shown === 'running' || $busy)
                    <span class="absolute inline-flex size-full animate-ping rounded-full opacity-60 {{ $dot }}"></span>
                @endif
                <span class="relative inline-flex size-3 rounded-full {{ $dot }}"></span>
            </span>
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-base font-semibold text-gray-900 dark:text-white">{{ $badge['label'] }}</span>
                    @if ($shown === 'running' && isset($live['since']))
                        <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('už') }} <x-panel.elapsed :since="\Illuminate\Support\Carbon::createFromTimestamp((int) $live['since'])" /></span>
                    @elseif ($shown === 'starting' && $pending === null && ($live['state'] ?? '') === 'active')
                        <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('načítá svět, konzole zatím neodpovídá') }}</span>
                    @endif
                </div>
                @if (($live['restarts'] ?? 0) > 0 && $shown !== 'stopped')
                    <p class="text-xs text-amber-600 dark:text-amber-400">{{ __('Po pádu automaticky restartován :count×', ['count' => (int) $live['restarts']]) }}</p>
                @endif
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2" data-testid="game-controls">
            @if (in_array($shown, ['installing', 'failed', 'unknown'], true))
                {{-- Nothing to start or stop until the install ends or the agent answers. --}}
            @elseif (in_array($shown, ['running', 'starting'], true) && $pending !== 'stop')
                <x-wire::button size="sm" color="gray" outlined icon="outline:arrow-path" wire:click="control('restart')" wire:loading.attr="disabled" wire:target="control" :disabled="$pending !== null" :wire:confirm="($players['online'] ?? 0) > 0 ? __('Restartovat server? Hráči (:n) se odpojí.', ['n' => $players['online']]) : null">{{ __('Restartovat') }}</x-wire::button>
                <x-wire::button size="sm" color="danger" outlined icon="outline:stop" wire:click="control('stop')" wire:loading.attr="disabled" wire:target="control" wire:confirm="{{ __('Zastavit server? Hráči se odpojí a po restartu serveru se sám nespustí.') }}">{{ __('Zastavit') }}</x-wire::button>
            @elseif ($shown !== 'stopping')
                <x-wire::button size="sm" color="success" icon="outline:play" wire:click="control('start')" wire:loading.attr="disabled" wire:target="control">{{ __('Spustit') }}</x-wire::button>
            @endif
            <span wire:loading wire:target="control">{!! icon('outline:arrow-path', 'size-4 animate-spin text-gray-400') !!}</span>
        </div>
    </div>

    @if ($error)
        <p class="px-4 pt-3 text-sm text-red-600 sm:px-6 dark:text-red-400">{{ __('Agent neodpovídá:') }} {{ $error }}</p>
    @endif

    <dl class="grid grid-cols-2 gap-x-6 gap-y-4 px-4 py-4 text-sm sm:px-6 lg:grid-cols-4">
        <div class="min-w-0">
            <dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('Hráči') }}</dt>
            <dd class="mt-1">
                @if ($players !== null)
                    <span class="text-lg font-semibold tabular-nums text-gray-900 dark:text-white">{{ $players['online'] }}</span>@isset($players['max'])<span class="text-gray-500"> / {{ $players['max'] }}</span>@endisset
                    @if ($players['names'] !== [])
                        <div class="mt-1 flex flex-wrap gap-1">
                            @foreach ($players['names'] as $name)
                                <span wire:key="pl-{{ $name }}" class="rounded-md bg-gray-100 px-1.5 py-0.5 font-mono text-xs dark:bg-white/10">{{ $name }}</span>
                            @endforeach
                        </div>
                    @endif
                @else
                    <span class="text-lg font-semibold text-gray-400">—</span>
                @endif
            </dd>
        </div>
        <div class="min-w-0">
            <dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('Procesor') }}</dt>
            <dd class="mt-1">
                <span class="text-lg font-semibold tabular-nums text-gray-900 dark:text-white">{{ $cpuPercent !== null && $live !== null && ($live['state'] ?? '') === 'active' ? \Illuminate\Support\Number::format($cpuPercent, maxPrecision: 0).' %' : '—' }}</span>
                <span class="text-xs text-gray-500">{{ $server->cpu_percent > 0 ? __('z :n %', ['n' => $server->cpu_percent]) : __('bez limitu') }}</span>
                <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                    <div class="h-full rounded-full bg-primary-500 transition-[width] duration-700" style="width: {{ $cpuPercent !== null ? min(100, round($cpuPercent / $cpuLimit * 100)) : 0 }}%"></div>
                </div>
            </dd>
        </div>
        <div class="min-w-0">
            <dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('Paměť') }}</dt>
            <dd class="mt-1">
                <span class="text-lg font-semibold tabular-nums text-gray-900 dark:text-white">{{ $mem > 0 ? $size($mem) : '—' }}</span>
                <span class="text-xs text-gray-500">/ {{ $size($memLimit) }}</span>
                <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                    <div @class(['h-full rounded-full transition-[width] duration-700', 'bg-red-500' => $memPct >= 95, 'bg-amber-500' => $memPct >= 80 && $memPct < 95, 'bg-primary-500' => $memPct < 80]) style="width: {{ $memPct }}%"></div>
                </div>
            </dd>
        </div>
        <div class="col-span-2 min-w-0 lg:col-span-1">
            <dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('Adresa pro hráče') }}</dt>
            <dd class="group mt-1 flex items-center gap-1">
                <span class="truncate font-mono text-gray-900 dark:text-white">{{ $server->address() }}</span>
                {!! app(\NyonCode\WireCore\Foundation\View\CopyButton::class)->render($server->address(), __('Adresa zkopírována'), class: 'rounded-sm p-0.5 hover:bg-gray-100 dark:hover:bg-gray-700', testId: 'game-address-copy') !!}
            </dd>
        </div>
    </dl>
    <div class="border-t border-gray-100 px-4 py-2 text-xs text-gray-500 sm:px-6 dark:border-white/5 dark:text-gray-400">
        {{ $server->game->label() }} · {{ $server->versionLabel() }} · {{ __('port') }} {{ $server->port }}/{{ $server->game->proto() }}
        @if ($server->sftp) · SFTP <span class="font-mono">sftp -P {{ config('panel.ssh_port') }} {{ $server->sftpTarget() }}</span>@endif
    </div>
</div>
