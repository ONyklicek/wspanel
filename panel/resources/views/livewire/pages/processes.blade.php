@php($size = fn ($b) => \Illuminate\Support\Number::fileSize((int) $b, 1))
<div class="space-y-6">
    @if ($error)
        <x-wire::callout color="danger" :heading="__('Agent neodpovídá')">{{ $error }}</x-wire::callout>
    @endif

    <x-wire::section :heading="__('Procesy')" :description="__('CPU je v procentech jednoho jádra za poslední půlsekundu. Ukončit pošle procesu SIGTERM (slušná žádost), natvrdo SIGKILL. Služby spravované systemd se obvykle samy spustí znovu – ty restartujte v Server → Služby.')">
        <div class="mb-3 flex flex-wrap items-center gap-3">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Hledat (uživatel, příkaz, web, PID)') }}"
                class="w-full max-w-sm rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
            <x-wire::button size="sm" color="gray" outlined icon="outline:arrow-path" wire:click="load">{{ __('Obnovit') }}</x-wire::button>
            <span class="text-xs text-gray-500">{{ \App\Support\Lang::count(count($processes), ':count proces', ':count procesy', ':count procesů') }}</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs text-gray-500">
                    <tr>
                        <th class="py-1 pe-3 font-normal">PID</th>
                        <th class="py-1 pe-3 font-normal">{{ __('Uživatel') }}</th>
                        <th class="py-1 pe-3 font-normal"><button type="button" wire:click="sortBy('cpu')" @class(['font-semibold text-gray-900 dark:text-white' => $sort === 'cpu'])>CPU</button></th>
                        <th class="py-1 pe-3 font-normal"><button type="button" wire:click="sortBy('rss')" @class(['font-semibold text-gray-900 dark:text-white' => $sort === 'rss'])>{{ __('Paměť') }}</button></th>
                        <th class="py-1 pe-3 font-normal">{{ __('Příkaz') }}</th>
                        <th class="py-1"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($this->shown() as $p)
                        <tr wire:key="p-{{ $p['pid'] }}-{{ $p['start'] }}">
                            <td class="py-1 pe-3 font-mono text-xs">{{ $p['pid'] }}</td>
                            <td class="py-1 pe-3 text-xs">{{ $p['user'] }}@if ($p['site'] ?? null)<br><span class="text-gray-500">{{ $p['site'] }}</span>@endif</td>
                            <td class="py-1 pe-3 text-xs tabular-nums">{{ \Illuminate\Support\Number::format($p['cpu'], 1) }} %</td>
                            <td class="py-1 pe-3 text-xs tabular-nums">{{ $size($p['rss']) }}</td>
                            <td class="max-w-xl truncate py-1 pe-3 font-mono text-xs" title="{{ $p['cmd'] }}">{{ $p['cmd'] ?: '['.$p['name'].']' }}</td>
                            <td class="whitespace-nowrap py-1 text-right">
                                <x-wire::button size="xs" color="gray" outlined wire:click="terminate({{ $p['pid'] }}, {{ $p['start'] }})" wire:confirm="{{ __('Ukončit proces :pid (:name)?', ['pid' => $p['pid'], 'name' => $p['name']]) }}">{{ __('Ukončit') }}</x-wire::button>
                                <x-wire::button size="xs" color="danger" outlined wire:click="terminate({{ $p['pid'] }}, {{ $p['start'] }}, true)" wire:confirm="{{ __('Ukončit proces :pid (:name) natvrdo? Neuložená data procesu se ztratí.', ['pid' => $p['pid'], 'name' => $p['name']]) }}">{{ __('Natvrdo') }}</x-wire::button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-wire::section>

    <x-wire::section :heading="__('Co zabírá disk')" :description="__('Velikost složek v jedné úrovni, jen na stejném disku (připojené disky se nepočítají). Domovské složky systémových uživatelů (/home, /root) agent nevidí.')">
        <form wire:submit="browse" class="mb-3 flex flex-wrap items-center gap-3">
            <input type="text" wire:model="path" class="w-full max-w-sm rounded-lg border-gray-300 font-mono text-sm dark:border-white/10 dark:bg-white/5">
            <x-wire::button type="submit" size="sm" icon="outline:magnifying-glass">{{ __('Spočítat') }}</x-wire::button>
            @if ($usage && $usage['path'] !== '/')
                <x-wire::button size="sm" color="gray" outlined icon="outline:arrow-up" wire:click="up">{{ __('O úroveň výš') }}</x-wire::button>
            @endif
            <span wire:loading wire:target="browse,up" class="text-xs text-gray-500">{{ __('Počítám… (u velkých složek to chvíli trvá)') }}</span>
        </form>
        @if ($usage)
            <p class="mb-2 text-sm">{{ __(':path celkem :size', ['path' => $usage['path'], 'size' => $size($usage['total'])]) }}@if ($usage['partial'])<span class="text-xs text-gray-500"> {{ __('(některé složky nešly přečíst)') }}</span>@endif</p>
            <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($usage['entries'] as $e)
                        <tr wire:key="du-{{ md5($e['path']) }}">
                            <td class="py-1 pe-3"><button type="button" class="font-mono text-xs text-primary-600 hover:underline dark:text-primary-400" wire:click="browse(@js($e['path']))">{{ $e['path'] }}</button></td>
                            <td class="w-40 py-1 pe-3">
                                <div class="h-1.5 rounded bg-gray-100 dark:bg-white/10"><div class="h-1.5 rounded bg-primary-500" style="width: {{ $usage['total'] > 0 ? round(100 * $e['size'] / $usage['total']) : 0 }}%"></div></div>
                            </td>
                            <td class="py-1 text-right text-xs tabular-nums">{{ $size($e['size']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </x-wire::section>
</div>
