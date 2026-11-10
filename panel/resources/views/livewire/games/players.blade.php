<div class="wire-resource-page space-y-4 sm:space-y-6">
    @include('wire-panels::pages.partials.header')
    @include('partials.subject-tasks')

    @if ($error)
        <x-wire::callout color="danger" :heading="__('Agent neodpovídá')">{{ $error }}</x-wire::callout>
    @endif

    @if ($server->game !== \App\Enums\Game::Minecraft)
        <x-wire::callout color="info" :heading="__('Hráči Factoria')">{{ __('Správci a bany Factoria nastavíte v konzoli: /promote Jméno, /ban Jméno, /whitelist add Jméno.') }}</x-wire::callout>
    @else
        @unless ($running)
            <x-wire::callout color="warning" :heading="__('Server neběží')">{{ __('Seznamy jsou vidět i teď, měnit je jde jen u běžícího serveru (změny posílá do jeho konzole, platí hned).') }}</x-wire::callout>
        @endunless

        <x-wire::section :heading="__('Přidat nebo odebrat hráče')">
            <form wire:submit="act('whitelist_add')" class="flex flex-wrap items-start gap-2">
                <div>
                    <input type="text" wire:model="name" maxlength="45" placeholder="{{ __('Jméno hráče nebo IP') }}" class="w-56 rounded-lg border-gray-300 font-mono text-sm dark:border-white/10 dark:bg-white/5" @disabled(! $running)>
                    @error('name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <input type="text" wire:model="reason" maxlength="200" placeholder="{{ __('Důvod (ban, vyhození)') }}" class="w-56 rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5" @disabled(! $running)>
                <x-wire::button type="submit" size="sm" :disabled="! $running">{{ __('Na whitelist') }}</x-wire::button>
                <x-wire::button type="button" size="sm" color="gray" outlined wire:click="act('op')" :disabled="! $running">{{ __('Operátor') }}</x-wire::button>
                <x-wire::button type="button" size="sm" color="gray" outlined wire:click="act('kick')" :disabled="! $running">{{ __('Vyhodit') }}</x-wire::button>
                <x-wire::button type="button" size="sm" color="danger" outlined wire:click="act('ban')" :disabled="! $running">{{ __('Zabanovat') }}</x-wire::button>
                <x-wire::button type="button" size="sm" color="danger" outlined wire:click="act('ban_ip')" :disabled="! $running">{{ __('Zabanovat IP') }}</x-wire::button>
            </form>
            @if ($online !== [])
                <p class="mt-3 text-sm">{{ __('Teď hrají:') }}
                    @foreach ($online as $p)
                        <button type="button" wire:click="$set('name', @js($p))" class="font-mono text-primary-600 hover:underline dark:text-primary-400">{{ $p }}</button>@if (! $loop->last), @endif
                    @endforeach
                </p>
            @endif
        </x-wire::section>

        @php($lists = $lists ?? [])
        <x-wire::section :heading="__('Whitelist')">
            <div class="mb-3 flex items-center gap-3 text-sm">
                <x-wire::badge :color="($lists['whitelist_on'] ?? false) ? 'success' : 'gray'">{{ ($lists['whitelist_on'] ?? false) ? __('zapnutý: hrát smí jen hráči ze seznamu') : __('vypnutý: hrát smí kdokoli') }}</x-wire::badge>
                <x-wire::button size="xs" color="gray" outlined wire:click="act({{ \Illuminate\Support\Js::from(($lists['whitelist_on'] ?? false) ? 'whitelist_off' : 'whitelist_on') }})" :disabled="! $running">{{ ($lists['whitelist_on'] ?? false) ? __('Vypnout') : __('Zapnout') }}</x-wire::button>
            </div>
            @include('livewire.games.partials.player-list', ['rows' => $lists['whitelist'] ?? [], 'remove' => 'whitelist_remove', 'label' => __('Odebrat'), 'empty' => __('Seznam je prázdný.')])
        </x-wire::section>

        <x-wire::section :heading="__('Operátoři')" :description="__('Smí používat příkazy serveru (úroveň 4 = všechny).')">
            @include('livewire.games.partials.player-list', ['rows' => $lists['ops'] ?? [], 'remove' => 'deop', 'label' => __('Odebrat'), 'empty' => __('Žádní operátoři.')])
        </x-wire::section>

        <x-wire::section :heading="__('Zabanovaní')">
            @include('livewire.games.partials.player-list', ['rows' => $lists['banned'] ?? [], 'remove' => 'pardon', 'label' => __('Odbanovat'), 'empty' => __('Nikdo.')])
            @if (($lists['banned_ips'] ?? []) !== [])
                <p class="mt-4 text-sm font-medium">{{ __('IP adresy') }}</p>
                @include('livewire.games.partials.player-list', ['rows' => array_map(fn ($r) => $r + ['name' => $r['ip'] ?? ''], $lists['banned_ips']), 'remove' => 'pardon_ip', 'label' => __('Odbanovat'), 'empty' => ''])
            @endif
        </x-wire::section>
    @endif

    @include('wire-panels::pages.partials.action-modals')
</div>
