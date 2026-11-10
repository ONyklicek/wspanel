<div class="wire-resource-page space-y-4 sm:space-y-6">
    @include('wire-panels::pages.partials.header')

    @if ($error)
        <x-wire::callout color="danger" :heading="__('Agent neodpovídá')">{{ $error }}</x-wire::callout>
    @endif

    <p class="text-sm text-gray-500 dark:text-gray-400">
        {{ __('Příkazy běží jako uživatel webu') }} <span class="font-mono">{{ $site->system_user }}</span> {{ __('ve složce current (aktuální release), se stejnými nástroji a omezeními jako deploy, nejdéle 30 minut. Interaktivní programy (vstup z klávesnice) tu nefungují: na ty je SSH.') }}
    </p>

    <x-wire::section :heading="__('Rychlé příkazy')">
        <div class="flex flex-wrap gap-2">
            @foreach ($commands as $c)
                @if (str_contains($c['id'], 'migrate'))
                    <x-wire::button size="sm" color="gray" outlined wire:click="quick({{ \Illuminate\Support\Js::from($c['id']) }})" :disabled="! $done" wire:key="q-{{ $c['id'] }}"
                        wire:confirm="{{ __('Spustit :c? Změní databázi webu.', ['c' => $c['label']]) }}"><span class="font-mono text-xs">{{ $c['label'] }}</span></x-wire::button>
                @else
                    <x-wire::button size="sm" color="gray" outlined wire:click="quick({{ \Illuminate\Support\Js::from($c['id']) }})" :disabled="! $done" wire:key="q-{{ $c['id'] }}"><span class="font-mono text-xs">{{ $c['label'] }}</span></x-wire::button>
                @endif
            @endforeach
        </div>
    </x-wire::section>

    @if ($this->mayRunCustom())
        <x-wire::section :heading="__('Vlastní příkaz')">
            <form wire:submit="custom" class="flex gap-2">
                <input type="text" wire:model="line" maxlength="4000" autocomplete="off" placeholder="php artisan tinker --execute=&quot;echo 1;&quot;" class="flex-1 rounded-lg border-gray-300 font-mono text-sm dark:border-white/10 dark:bg-white/5" @disabled(! $done)>
                <x-wire::button type="submit" size="sm" :disabled="! $done">{{ __('Spustit') }}</x-wire::button>
            </form>
            @error('line')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </x-wire::section>
    @endif

    @if ($run)
        <x-wire::section :heading="__('Výstup')">
            <div @if (! $done) wire:poll.1500ms="follow" @endif>
                <div class="mb-2 text-sm">
                    @if (! $done)
                        <x-wire::badge color="warning">{{ __('běží…') }}</x-wire::badge>
                    @elseif ($exit === 0)
                        <x-wire::badge color="success">{{ __('hotovo') }}</x-wire::badge>
                    @else
                        <x-wire::badge color="danger">{{ __('skončil s chybou :n', ['n' => $exit ?? '?']) }}</x-wire::badge>
                    @endif
                </div>
                @include('partials.live-log', ['event' => 'console-output', 'initial' => $initialOutput, 'empty' => '',
                    'class' => 'max-h-[32rem] min-h-[3rem] overflow-auto whitespace-pre-wrap rounded-lg bg-gray-950 p-3 font-mono text-xs text-gray-100'])
            </div>
        </x-wire::section>
    @endif

    @if ($runs !== [])
        <x-wire::section :heading="__('Poslední příkazy')">
            <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($runs as $r)
                        <tr wire:key="r-{{ $r['id'] }}">
                            <td class="max-w-md truncate py-1 pe-3 font-mono text-xs">{{ $r['command'] }}</td>
                            <td class="py-1 pe-3 text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($r['started'])->setTimezone(config('app.timezone'))->isoFormat('D. M. HH:mm') }}</td>
                            <td class="py-1 pe-3 text-xs">@if (! isset($r['exit']))<x-wire::badge color="warning">{{ __('běží') }}</x-wire::badge>@elseif ($r['exit'] === 0)<x-wire::badge color="success">OK</x-wire::badge>@else<x-wire::badge color="danger">{{ $r['exit'] }}</x-wire::badge>@endif</td>
                            <td class="py-1 text-right"><x-wire::button size="xs" color="gray" outlined wire:click="show({{ \Illuminate\Support\Js::from($r['id']) }})">{{ __('Výstup') }}</x-wire::button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </x-wire::section>
    @endif

    @include('wire-panels::pages.partials.action-modals')
</div>
