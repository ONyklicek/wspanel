<div class="wire-resource-page space-y-4 sm:space-y-6">
    @include('wire-panels::pages.partials.header')

    @if ($error)
        <x-wire::callout color="danger" :heading="__('Agent neodpovídá')">{{ $error }}</x-wire::callout>
    @endif

    <p class="text-sm text-gray-500 dark:text-gray-400">
        {{ __('Proměnné a tajné hodnoty aplikace, zapsané do sdíleného .env webu (jako u GitHubu: proměnné jsou vidět, tajné hodnoty jdou jen zapsat). Deploy je nepřepíše, ani když repozitář nebo CI přinese vlastní .env. Aplikace je uvidí po restartu nebo dalším deployi (u Laravelu s cache konfigurace po deployi nebo příkazu config:cache).') }}
    </p>

    <div class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between border-b border-gray-200 px-4 py-4 sm:px-6 dark:border-gray-700">
            <div class="min-w-0 flex-1">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Proměnné') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Běžné hodnoty, zobrazují se.') }}</p>
            </div>
            <x-wire::button size="sm" icon="outline:plus" wire:click="add(false)">{{ __('Přidat proměnnou') }}</x-wire::button>
        </div>
        <div class="px-4 py-4 sm:px-6">
            @if ($vars === [])
                <p class="text-sm text-gray-500">{{ __('Zatím žádné proměnné.') }}</p>
            @else
                <div class="overflow-x-auto">
                <table class="w-full table-fixed text-left text-sm">
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($vars as $k => $v)
                            <tr wire:key="v-{{ $k }}">
                                <td class="w-1/3 py-1.5 pe-3 font-mono text-xs font-medium">{{ $k }}</td>
                                <td class="truncate py-1.5 pe-3 font-mono text-xs text-gray-600 dark:text-gray-300" title="{{ $v }}">{{ $v === '' ? '—' : $v }}</td>
                                <td class="w-40 whitespace-nowrap py-1.5 text-right">
                                    <x-wire::button size="xs" color="gray" outlined wire:click="edit({{ \Illuminate\Support\Js::from($k) }}, false)">{{ __('Upravit') }}</x-wire::button>
                                    <x-wire::button size="xs" color="danger" outlined wire:click="remove({{ \Illuminate\Support\Js::from($k) }})" wire:confirm="{{ __('Smazat :key z .env?', ['key' => $k]) }}">{{ __('Smazat') }}</x-wire::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            @endif
        </div>
    </div>

    <div class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between border-b border-gray-200 px-4 py-4 sm:px-6 dark:border-gray-700">
            <div class="min-w-0 flex-1">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Tajné hodnoty') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Hodnoty panel nikdy nezobrazí. Změníte je zadáním nové.') }}</p>
            </div>
            <x-wire::button size="sm" icon="outline:plus" wire:click="add(true)">{{ __('Přidat tajnou hodnotu') }}</x-wire::button>
        </div>
        <div class="px-4 py-4 sm:px-6">
            @if ($secrets === [])
                <p class="text-sm text-gray-500">{{ __('Zatím žádné tajné hodnoty.') }}</p>
            @else
                <div class="overflow-x-auto">
                <table class="w-full table-fixed text-left text-sm">
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($secrets as $k => $set)
                            <tr wire:key="s-{{ $k }}">
                                <td class="w-1/3 py-1.5 pe-3 font-mono text-xs font-medium">{{ $k }}</td>
                                <td class="py-1.5 pe-3 text-xs">@if ($set)<span class="font-mono text-gray-400">••••••••</span>@else<x-wire::badge color="warning">{{ __('v .env chybí') }}</x-wire::badge>@endif</td>
                                <td class="w-40 whitespace-nowrap py-1.5 text-right">
                                    <x-wire::button size="xs" color="gray" outlined wire:click="edit({{ \Illuminate\Support\Js::from($k) }}, true)">{{ __('Změnit') }}</x-wire::button>
                                    <x-wire::button size="xs" color="danger" outlined wire:click="remove({{ \Illuminate\Support\Js::from($k) }})" wire:confirm="{{ __('Smazat :key z .env?', ['key' => $k]) }}">{{ __('Smazat') }}</x-wire::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            @endif
        </div>
    </div>

    @if ($this->usesComposer())
        <div class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between border-b border-gray-200 px-4 py-4 sm:px-6 dark:border-gray-700">
                <div class="min-w-0 flex-1">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Composer: přístup k soukromým balíčkům (auth.json)') }}</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Přihlašovací údaje k soukromým repozitářům (Private Packagist, Satis, GitHub, GitLab). Composer je použije při deployi i v SSH. Hesla a tokeny se už nezobrazí.') }}</p>
                </div>
                <x-wire::button size="sm" icon="outline:plus" wire:click="addComposer">{{ __('Přidat přístup') }}</x-wire::button>
            </div>
            <div class="px-4 py-4 sm:px-6">
            @if (($composer ?? []) !== [])
                <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($composer as $c)
                            <tr wire:key="c-{{ $c['type'] }}-{{ $c['host'] }}">
                                <td class="py-1.5 pe-3 font-mono text-xs font-medium">{{ $c['host'] }}</td>
                                <td class="py-1.5 pe-3 text-xs text-gray-500">{{ $c['type'] }}@if ($c['username'] ?? null) · {{ $c['username'] }}@endif · <span class="font-mono">••••••</span></td>
                                <td class="py-1.5 text-right"><x-wire::button size="xs" color="danger" outlined wire:click="removeComposer({{ \Illuminate\Support\Js::from($c['type']) }}, {{ \Illuminate\Support\Js::from($c['host']) }})" wire:confirm="{{ __('Smazat přístup pro :host?', ['host' => $c['host']]) }}">{{ __('Smazat') }}</x-wire::button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            @else
                <p class="text-sm text-gray-500">{{ __('Zatím žádné přístupy.') }}</p>
            @endif
            </div>
        </div>
    @endif

    @if ($managed !== [])
        <p class="text-xs text-gray-500">{{ __('Spravuje panel (databáze, Valkey, pošta, workery), tady se nemění:') }} <span class="font-mono">{{ implode(', ', $managed) }}</span></p>
    @endif

    <x-wire-modals::modal wire:model="formOpen" id="env-form" :heading="$editing ? __('Upravit :key', ['key' => $editing]) : ($secret ? __('Nová tajná hodnota') : __('Nová proměnná'))" :description="$secret ? __('Po uložení už se hodnota nezobrazí, jde jen přepsat.') : null" width="lg">
        <form id="env-var-form" wire:submit="save" class="space-y-3">
            <div>
                <label for="env-key" class="mb-1 block text-sm font-medium">{{ __('Název') }}</label>
                <input id="env-key" type="text" wire:model="key" placeholder="STRIPE_KEY" maxlength="128" @readonly($editing !== null) class="w-full rounded-lg border-gray-300 font-mono text-sm uppercase read-only:bg-gray-50 dark:border-white/10 dark:bg-white/5">
                @error('key')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="env-value" class="mb-1 block text-sm font-medium">{{ __('Hodnota') }}</label>
                <input id="env-value" type="{{ $secret ? 'password' : 'text' }}" wire:model="value" autocomplete="off" maxlength="8192"
                    placeholder="{{ $secret && $editing ? __('nová hodnota (současná se nezobrazí)') : '' }}" class="w-full rounded-lg border-gray-300 font-mono text-sm dark:border-white/10 dark:bg-white/5">
                @error('value')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
        </form>
        <x-slot:footer>
            <div class="flex justify-end gap-2">
                <x-wire::button type="button" color="gray" outlined wire:click="cancel">{{ __('Zrušit') }}</x-wire::button>
                <x-wire::button type="submit" form="env-var-form">{{ __('Uložit') }}</x-wire::button>
            </div>
        </x-slot:footer>
    </x-wire-modals::modal>

    @if ($this->usesComposer())
        <x-wire-modals::modal wire:model="composerOpen" id="composer-auth" :heading="__('Nový přístup pro Composer')" width="lg">
                <form id="composer-form" wire:submit="saveComposer" class="grid gap-3">
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
                </form>
            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <x-wire::button type="button" color="gray" outlined wire:click="$set('composerOpen', false)">{{ __('Zrušit') }}</x-wire::button>
                    <x-wire::button type="submit" form="composer-form">{{ __('Uložit přístup') }}</x-wire::button>
                </div>
            </x-slot:footer>
        </x-wire-modals::modal>
    @endif

    @include('wire-panels::pages.partials.action-modals')
</div>
