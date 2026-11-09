<div class="wire-resource-page space-y-4 sm:space-y-6">
    @include('wire-panels::pages.partials.header')

    @if ($error)
        <x-wire::callout color="danger" :heading="__('Agent neodpovídá')">{{ $error }}</x-wire::callout>
    @endif

    <p class="text-sm text-gray-500 dark:text-gray-400">
        {{ __('Proměnné a tajné hodnoty aplikace, zapsané do sdíleného .env webu (jako u GitHubu: proměnné jsou vidět, tajné hodnoty jdou jen zapsat). Deploy je nepřepíše, ani když repozitář nebo CI přinese vlastní .env. Aplikace je uvidí po restartu nebo dalším deployi (u Laravelu s cache konfigurace po deployi nebo příkazu config:cache).') }}
    </p>

    <x-wire::section :heading="$editing ? __('Upravit :key', ['key' => $editing]) : __('Přidat')">
        <form wire:submit="save" class="grid gap-3 sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)_auto] sm:items-start">
            <div>
                <input type="text" wire:model="key" placeholder="STRIPE_KEY" maxlength="128" @readonly($editing !== null) class="w-full rounded-lg border-gray-300 font-mono text-sm uppercase read-only:bg-gray-50 dark:border-white/10 dark:bg-white/5">
                @error('key')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <input type="{{ $secret ? 'password' : 'text' }}" wire:model="value" autocomplete="off" maxlength="8192"
                    placeholder="{{ $secret && $editing ? __('nová hodnota (současná se nezobrazí)') : __('hodnota') }}" class="w-full rounded-lg border-gray-300 font-mono text-sm dark:border-white/10 dark:bg-white/5">
                @error('value')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                @if ($editing === null)
                    <label class="mt-2 flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="secret" class="rounded border-gray-300">{{ __('Tajná hodnota (heslo, API klíč): po uložení už se nezobrazí') }}</label>
                @endif
            </div>
            <div class="flex gap-2">
                <x-wire::button type="submit" size="sm">{{ __('Uložit') }}</x-wire::button>
                @if ($editing)<x-wire::button type="button" size="sm" color="gray" outlined wire:click="cancel">{{ __('Zrušit') }}</x-wire::button>@endif
            </div>
        </form>
    </x-wire::section>

    <x-wire::section :heading="__('Proměnné')">
        @if ($vars === [])
            <p class="text-sm text-gray-500">{{ __('Žádné.') }}</p>
        @else
            <table class="w-full table-fixed text-left text-sm">
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($vars as $k => $v)
                        <tr wire:key="v-{{ $k }}">
                            <td class="w-1/3 py-1.5 pe-3 font-mono text-xs font-medium">{{ $k }}</td>
                            <td class="truncate py-1.5 pe-3 font-mono text-xs text-gray-600 dark:text-gray-300" title="{{ $v }}">{{ $v === '' ? '—' : $v }}</td>
                            <td class="w-40 whitespace-nowrap py-1.5 text-right">
                                <x-wire::button size="xs" color="gray" outlined wire:click="edit(@js($k), false)">{{ __('Upravit') }}</x-wire::button>
                                <x-wire::button size="xs" color="danger" outlined wire:click="remove(@js($k))" wire:confirm="{{ __('Smazat :key z .env?', ['key' => $k]) }}">{{ __('Smazat') }}</x-wire::button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-wire::section>

    <x-wire::section :heading="__('Tajné hodnoty')" :description="__('Hodnoty panel nikdy nezobrazí. Změníte je zadáním nové.')">
        @if ($secrets === [])
            <p class="text-sm text-gray-500">{{ __('Žádné.') }}</p>
        @else
            <table class="w-full table-fixed text-left text-sm">
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($secrets as $k => $set)
                        <tr wire:key="s-{{ $k }}">
                            <td class="w-1/3 py-1.5 pe-3 font-mono text-xs font-medium">{{ $k }}</td>
                            <td class="py-1.5 pe-3 text-xs">@if ($set)<span class="font-mono text-gray-400">••••••••</span>@else<x-wire::badge color="warning">{{ __('v .env chybí') }}</x-wire::badge>@endif</td>
                            <td class="w-40 whitespace-nowrap py-1.5 text-right">
                                <x-wire::button size="xs" color="gray" outlined wire:click="edit(@js($k), true)">{{ __('Změnit') }}</x-wire::button>
                                <x-wire::button size="xs" color="danger" outlined wire:click="remove(@js($k))" wire:confirm="{{ __('Smazat :key z .env?', ['key' => $k]) }}">{{ __('Smazat') }}</x-wire::button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-wire::section>

    @if ($this->usesComposer())
        <x-wire::section :heading="__('Composer: přístup k soukromým balíčkům (auth.json)')" :description="__('Přihlašovací údaje k soukromým repozitářům (Private Packagist, Satis, GitHub, GitLab). Composer je použije při deployi i v SSH. Hesla a tokeny se už nezobrazí.')">
            @if (($composer ?? []) !== [])
                <table class="mb-4 w-full text-left text-sm">
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($composer as $c)
                            <tr wire:key="c-{{ $c['type'] }}-{{ $c['host'] }}">
                                <td class="py-1.5 pe-3 font-mono text-xs font-medium">{{ $c['host'] }}</td>
                                <td class="py-1.5 pe-3 text-xs text-gray-500">{{ $c['type'] }}@if ($c['username'] ?? null) · {{ $c['username'] }}@endif · <span class="font-mono">••••••</span></td>
                                <td class="py-1.5 text-right"><x-wire::button size="xs" color="danger" outlined wire:click="removeComposer(@js($c['type']), @js($c['host']))" wire:confirm="{{ __('Smazat přístup pro :host?', ['host' => $c['host']]) }}">{{ __('Smazat') }}</x-wire::button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            <form wire:submit="saveComposer" class="grid gap-3 sm:grid-cols-2">
                <select wire:model.live="cType" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                    @foreach (\App\Actions\Sites\ManageEnvironment::COMPOSER_TYPES as $t => $label)
                        <option value="{{ $t }}">{{ __($label) }}</option>
                    @endforeach
                </select>
                <div>
                    <input type="text" wire:model="cHost" placeholder="{{ $cType === 'github-oauth' ? 'github.com' : ($cType === 'gitlab-token' ? 'gitlab.com' : 'repo.packagist.com') }}" class="w-full rounded-lg border-gray-300 font-mono text-sm dark:border-white/10 dark:bg-white/5">
                    @error('cHost')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                @if ($cType === 'http-basic')
                    <div>
                        <input type="text" wire:model="cUser" autocomplete="off" placeholder="{{ __('jméno (u Private Packagist „token“)') }}" class="w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                        @error('cUser')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                @endif
                <div>
                    <input type="password" wire:model="cSecret" autocomplete="new-password" placeholder="{{ $cType === 'http-basic' ? __('heslo nebo token') : __('token') }}" class="w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                    @error('cSecret')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="sm:col-span-2"><x-wire::button type="submit" size="sm">{{ __('Uložit přístup') }}</x-wire::button></div>
            </form>
        </x-wire::section>
    @endif

    @if ($managed !== [])
        <p class="text-xs text-gray-500">{{ __('Spravuje panel (databáze, Valkey, pošta, workery), tady se nemění:') }} <span class="font-mono">{{ implode(', ', $managed) }}</span></p>
    @endif

    @include('wire-panels::pages.partials.action-modals')
</div>
