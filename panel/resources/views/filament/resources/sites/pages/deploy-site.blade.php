<x-filament-panels::page>
    <div @if ($running || $logStatus === 'running') wire:poll.2s="refreshLog" @endif class="grid gap-6 lg:grid-cols-3">
        <div class="flex flex-col gap-6">
            <x-filament::section heading="Nastavení">
                @if ($config)
                    <dl class="grid grid-cols-3 gap-x-3 gap-y-2 text-sm">
                        <dt class="text-gray-500">Repozitář</dt><dd class="col-span-2 font-mono break-all">{{ $config['repo'] ?? '' }}</dd>
                        <dt class="text-gray-500">Větev</dt><dd class="col-span-2 font-mono">{{ $config['branch'] ?? '' }}</dd>
                        <dt class="text-gray-500">Kroky</dt>
                        <dd class="col-span-2">{{ collect(['migrace' => $config['migrate'] ?? false, 'assety' => $config['assets'] ?? false, 'auto-rollback' => $config['auto_rollback'] ?? false])->filter()->keys()->join(', ') ?: '—' }}</dd>
                        <dt class="text-gray-500">Aktivní release</dt><dd class="col-span-2 font-mono">{{ $current }}</dd>
                    </dl>
                @else
                    <p class="text-sm text-gray-500">Deploy zatím není nastavený. Otevřete <strong>Nastavení</strong> a zadejte repozitář; u privátního repozitáře přidejte <strong>Deploy klíč</strong>.</p>
                @endif
            </x-filament::section>

            @if ($this->record->webhook_enabled || $this->deliveries()->isNotEmpty())
                <x-filament::section heading="Webhook">
                    <ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">
                        @forelse ($this->deliveries() as $w)
                            <li class="flex items-start justify-between gap-2 py-2" wire:key="w-{{ $w->id }}">
                                <span>
                                    {{ $w->message }}
                                    <span class="block text-xs text-gray-500">{{ $w->created_at?->diffForHumans() }} · {{ $w->provider ?? '?' }} @if ($w->commit) · {{ substr($w->commit, 0, 10) }} @endif · {{ $w->ip }}</span>
                                </span>
                                <x-filament::badge :color="$w->resultColor()">{{ $w->result }}</x-filament::badge>
                            </li>
                        @empty
                            <li class="py-2 text-gray-500">Zapnuto, zatím nic nepřišlo.</li>
                        @endforelse
                    </ul>
                </x-filament::section>
            @endif

            <x-filament::section heading="Historie">
                <ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">
                    @forelse ($this->deployments() as $d)
                        <li wire:key="d-{{ $d->id }}">
                            <button type="button" wire:click="showLog('{{ $d->release }}')" class="flex w-full items-center justify-between gap-2 py-2 text-left">
                                <span>
                                    <span class="font-mono">{{ $d->release }}</span>
                                    @if ($d->release === $current) <span class="text-xs text-primary-600">· aktivní</span> @endif
                                    <span class="block text-xs text-gray-500">{{ $d->started_at?->diffForHumans() }} @if ($d->commit) · {{ substr($d->commit, 0, 10) }} @endif</span>
                                </span>
                                <x-filament::badge :color="$d->statusColor()">{{ $d->statusLabel() }}</x-filament::badge>
                            </button>
                        </li>
                    @empty
                        <li class="py-2 text-gray-500">Zatím žádný deploy.</li>
                    @endforelse
                </ul>
            </x-filament::section>
        </div>

        <x-filament::section class="lg:col-span-2" :heading="$logRelease ? 'Log '.$logRelease : 'Log'">
            <pre class="max-h-[36rem] overflow-auto whitespace-pre-wrap rounded-lg bg-gray-950 p-4 font-mono text-xs leading-relaxed text-gray-100">{{ $log !== '' ? $log : 'Žádný log.' }}</pre>
            @if ($logStatus === 'running')
                <p class="mt-2 text-sm text-warning-600">Deploy běží…</p>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
