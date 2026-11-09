@php($known = \App\Support\MinecraftProperties::KNOWN)
<div class="wire-resource-page space-y-4 sm:space-y-6">
    @include('wire-panels::pages.partials.header')

    @if ($error)
        <x-wire::callout color="danger" :heading="__('Agent neodpovídá')">{{ $error }}</x-wire::callout>
    @endif

    @if ($server->game === \App\Enums\Game::Factorio)
        @if ($exists)
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Nastavení ze souboru data/server-settings.json. Ostatní volby souboru zůstanou, jak jsou. Změny platí po restartu serveru.') }}</p>
            <form wire:submit="saveFactorio" class="space-y-6">
                <x-wire::section :heading="__('Server')">
                    <div class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                        @foreach (\App\Support\FactorioSettings::FIELDS as $key => $spec)
                            @php($key = \App\Livewire\Games\GameServerSettings::fkey($key))
                            <div wire:key="f-{{ $key }}" @class(['sm:col-span-2' => $spec[1] === 'text'])>
                                @if ($spec[1] === 'bool')
                                    <label class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-200"><input type="checkbox" wire:model="factorio.{{ $key }}" class="rounded border-gray-300">{{ __($spec[0]) }}</label>
                                @else
                                    <label for="f-{{ $key }}" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __($spec[0]) }}</label>
                                    @if ($spec[1] === 'commands')
                                        <select id="f-{{ $key }}" wire:model="factorio.{{ $key }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                                            <option value="admins-only">{{ __('jen správci') }}</option>
                                            <option value="true">{{ __('všichni') }}</option>
                                            <option value="false">{{ __('nikdo') }}</option>
                                        </select>
                                    @elseif ($spec[1] === 'text')
                                        <textarea id="f-{{ $key }}" wire:model="factorio.{{ $key }}" rows="3" maxlength="2000" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5"></textarea>
                                    @else
                                        <input id="f-{{ $key }}" type="{{ $spec[1] === 'int' ? 'number' : 'text' }}" wire:model="factorio.{{ $key }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                                    @endif
                                @endif
                                @if ($spec[3] ?? null)<p class="mt-1 text-xs text-gray-500">{{ __($spec[3]) }}</p>@endif
                                @error('factorio.'.$key)<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                        @endforeach
                    </div>
                </x-wire::section>
                <x-wire::section :heading="__('Hesla a token')" :description="__('Panel je nikdy nezobrazí; pole zůstane prázdné. Vyplněním hodnotu nastavíte nebo změníte.')">
                    <div class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                        @foreach (['token' => ['newToken', __('Token účtu factorio.com'), __('Najdete ho na factorio.com/profile. Potřebný pro veřejný seznam a módy.')], 'game_password' => ['newGamePassword', __('Heslo ke hře'), __('Hráči ho zadají při připojení. Prázdné = bez hesla.')]] as $k => [$model, $label, $help])
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ $label }}
                                    <x-wire::badge :color="($secrets[$k] ?? false) ? 'success' : 'gray'">{{ ($secrets[$k] ?? false) ? __('nastaveno') : __('nenastaveno') }}</x-wire::badge></label>
                                <input type="password" autocomplete="new-password" wire:model="{{ $model }}" maxlength="200" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                                <p class="mt-1 text-xs text-gray-500">{{ $help }}
                                    @if ($secrets[$k] ?? false)<button type="button" class="text-red-600 hover:underline" wire:click="clearSecret(@js($k))" wire:confirm="{{ __('Odebrat?') }}">{{ __('Odebrat') }}</button>@endif</p>
                                @error($model)<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                        @endforeach
                    </div>
                </x-wire::section>
                <div class="flex flex-wrap gap-2">
                    <x-wire::button type="submit">{{ __('Uložit') }}</x-wire::button>
                    <x-wire::button type="button" color="gray" outlined wire:click="saveFactorio(true)" wire:confirm="{{ __('Uložit a restartovat server? Hráči se na chvíli odpojí.') }}">{{ __('Uložit a restartovat') }}</x-wire::button>
                </div>
            </form>
        @endif
    @elseif (! $exists)
        <x-wire::callout color="info" :heading="__('Server ještě nastavení nezapsal')">{{ __('server.properties vytvoří server při prvním spuštění. Spusťte ho a vraťte se sem.') }}</x-wire::callout>
    @else
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('Nastavení ze souboru server.properties, jak ho zapsal server této verze. Změny platí po restartu. PvP, spawnování příšer a podobně jsou od verze 1.21.9 herní pravidla: nastavíte je v konzoli, např.') }} <span class="font-mono">gamerule pvp false</span>.
        </p>
        <form wire:submit="save" class="space-y-6">
            @foreach ($this->sections() as $heading => $keys)
                <x-wire::section :heading="$heading">
                    <div class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                        @foreach ($keys as $key)
                            @php($spec = $known[$key] ?? [null, $key, 'string'])
                            <div wire:key="prop-{{ $key }}">
                                <label for="prop-{{ $key }}" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __($spec[1]) }}</label>
                                @if ($spec[2] === 'bool')
                                    <select id="prop-{{ $key }}" wire:model="values.{{ $key }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                                        <option value="true">{{ __('zapnuto') }}</option>
                                        <option value="false">{{ __('vypnuto') }}</option>
                                    </select>
                                @elseif ($spec[2] === 'enum')
                                    <select id="prop-{{ $key }}" wire:model="values.{{ $key }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                                        @foreach ($spec[3] as $v => $label)
                                            <option value="{{ $v }}">{{ __($label) }}</option>
                                        @endforeach
                                        @unless (array_key_exists($values[$key] ?? '', $spec[3]))
                                            <option value="{{ $values[$key] ?? '' }}">{{ $values[$key] ?? '' }}</option>
                                        @endunless
                                    </select>
                                @elseif ($spec[2] === 'int')
                                    <input id="prop-{{ $key }}" type="number" min="{{ $spec[3][0] }}" max="{{ $spec[3][1] }}" wire:model="values.{{ $key }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                                @else
                                    <input id="prop-{{ $key }}" type="text" maxlength="4096" wire:model="values.{{ $key }}" class="mt-1 w-full rounded-lg border-gray-300 font-mono text-sm dark:border-white/10 dark:bg-white/5">
                                @endif
                                <p class="mt-1 text-xs text-gray-500"><span class="font-mono">{{ $key }}</span>@if ($spec[4] ?? null) · {{ __($spec[4]) }}@endif</p>
                                @error('values.'.$key)<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                        @endforeach
                    </div>
                </x-wire::section>
            @endforeach

            @if ($panelKeys !== [])
                <p class="text-xs text-gray-500">{{ __('Spravuje panel (port, konzole RCON):') }} <span class="font-mono">{{ implode(', ', $panelKeys) }}</span></p>
            @endif

            <div class="flex flex-wrap gap-2">
                <x-wire::button type="submit">{{ __('Uložit') }}</x-wire::button>
                <x-wire::button type="button" color="gray" outlined wire:click="save(true)" wire:confirm="{{ __('Uložit a restartovat server? Hráči se na chvíli odpojí.') }}">{{ __('Uložit a restartovat') }}</x-wire::button>
                <span wire:loading wire:target="save" class="text-sm text-gray-500">{{ __('Ukládám…') }}</span>
            </div>
        </form>
    @endif

    @include('wire-panels::pages.partials.action-modals')
</div>
