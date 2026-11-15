<div class="wire-resource-page space-y-4 sm:space-y-6">
    @include('wire-panels::pages.partials.header')

    @if ($error)
        <x-wire::callout color="danger" :heading="__('Agent neodpovídá')">{{ $error }}</x-wire::callout>
    @endif

    @if ($links === [])
        <x-wire::callout color="info" :heading="__('Herní server zatím nepoužívá žádnou databázi')">
            {{ __('Pluginy jako LuckPerms, CoreProtect nebo obchod mohou ukládat data do MariaDB. Připojte databázi webu (web pak čte stejná data), nebo vytvořte novou a nasdílejte ji webu.') }}
        </x-wire::callout>
    @endif

    @foreach ($links as $l)
        <x-wire::section wire:key="db-{{ $l['name'] }}" :heading="$l['name']" :description="__('Přihlášení jen pro tento herní server; vložte ho do nastavení pluginu (u většiny typ mariadb nebo mysql).')">
            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm" x-data="{ show: false }">
                <dt class="text-gray-500">{{ __('Server') }}</dt><dd class="font-mono">{{ $l['host'] }}:{{ $l['port'] }}</dd>
                <dt class="text-gray-500">{{ __('Databáze') }}</dt><dd class="font-mono">{{ $l['name'] }}</dd>
                <dt class="text-gray-500">{{ __('Uživatel') }}</dt><dd class="font-mono">{{ $l['user'] }}</dd>
                <dt class="text-gray-500">{{ __('Heslo') }}</dt>
                <dd class="font-mono"><span x-show="show" x-cloak>{{ $l['password'] }}</span><span x-show="! show">••••••••••</span>
                    <button type="button" class="ms-2 text-xs text-primary-600 hover:underline dark:text-primary-400" x-on:click="show = ! show" x-text="show ? @js(__('skrýt')) : @js(__('ukázat'))"></button></dd>
                <dt class="text-gray-500">JDBC</dt><dd class="break-all font-mono text-xs">{{ $l['jdbc'] }}</dd>
                <dt class="text-gray-500">{{ __('Sdíleno s weby') }}</dt>
                <dd>
                    @forelse ($l['sites'] as $s)
                        <span class="me-2 inline-flex items-center gap-1">{{ $s['domain'] }} <span class="font-mono text-xs text-gray-500">({{ $s['prefix'] }}_*)</span>
                            <button type="button" class="text-xs text-red-600 hover:underline" wire:click="unshare({{ \Illuminate\Support\Js::from($l['name']) }}, {{ \Illuminate\Support\Js::from($s['domain']) }})" wire:confirm="{{ __('Zrušit sdílení s :domain? Web se k databázi přestane přihlašovat.', ['domain' => $s['domain']]) }}">{{ __('zrušit') }}</button></span>
                    @empty
                        <span class="text-gray-500">{{ __('s žádným') }}</span>
                    @endforelse
                </dd>
            </dl>
            <div class="mt-3 flex flex-wrap gap-2">
                <x-wire::button size="xs" color="gray" outlined icon="outline:key" wire:click="newPassword({{ \Illuminate\Support\Js::from($l['name']) }})" wire:confirm="{{ __('Nastavit herním serveru nové heslo? Staré přestane platit hned.') }}">{{ __('Nové heslo') }}</x-wire::button>
                <x-wire::button size="xs" color="danger" outlined icon="outline:link-slash" wire:click="detach({{ \Illuminate\Support\Js::from($l['name']) }})" wire:confirm="{{ __('Odpojit :name? Databáze zůstane, herní server k ní ztratí přístup.', ['name' => $l['name']]) }}">{{ __('Odpojit') }}</x-wire::button>
            </div>
        </x-wire::section>
    @endforeach

    @include('wire-panels::pages.partials.action-modals')
</div>
