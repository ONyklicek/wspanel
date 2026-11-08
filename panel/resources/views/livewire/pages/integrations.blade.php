@php
    $app = $this->githubApp();
    $connections = $this->connections();
@endphp
<div class="max-w-4xl space-y-6">
    <p class="text-sm text-gray-500 dark:text-gray-400">
        {{ __('Služby propojené s vaším účtem v panelu. Propojení vidíte a používáte jen vy.') }}
    </p>

    <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900" data-testid="integration-github">
        {{-- Header --}}
        <div class="flex flex-col gap-4 p-6 sm:flex-row sm:items-start">
            <div class="grid size-12 shrink-0 place-items-center rounded-xl bg-gray-900 text-white dark:bg-white dark:text-gray-900" aria-hidden="true">
                {{-- GitHub mark (brand logo, follows the text colour) --}}
                <svg viewBox="0 0 16 16" class="size-7" fill="currentColor"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/></svg>
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">GitHub</h2>
                    @if ($connections->isNotEmpty())
                        <span class="inline-flex items-center gap-1 rounded-full bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20 dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/20">
                            <span class="size-1.5 rounded-full bg-green-500"></span>{{ __('Propojeno') }}
                        </span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-gray-50 px-2 py-0.5 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-500/10 dark:bg-gray-400/10 dark:text-gray-400 dark:ring-gray-400/20">{{ __('Nepropojeno') }}</span>
                    @endif
                </div>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Nasazujte z repozitářů na GitHubu. U webu pak vyberete repozitář a větev ze seznamu; panel sám přidá deploy klíč a webhook a výsledek každého deploye uvidíte přímo v GitHubu.') }}
                </p>
            </div>
        </div>

        {{-- Connected accounts --}}
        @if ($connections->isNotEmpty())
            <ul role="list" class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-gray-800 dark:border-gray-800">
                @foreach ($connections as $c)
                    <li wire:key="gh-{{ $c->id }}" class="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center">
                        <span class="relative grid size-10 shrink-0 place-items-center overflow-hidden rounded-full bg-gray-100 text-sm font-semibold uppercase text-gray-500 ring-1 ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700" aria-hidden="true">
                            {{ mb_substr($c->login, 0, 1) }}
                            <img src="{{ $c->avatarUrl() }}" alt="" class="absolute inset-0 size-full object-cover" referrerpolicy="no-referrer" onerror="this.remove()">
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $c->login }}</span>
                                <span class="rounded-md bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                    {{ $c->isApp() ? ($c->isOrganization() ? __('organizace') : __('osobní účet')) : __('token') }}
                                </span>
                            </div>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                @if ($c->isApp())
                                    {{ __('Repozitáře: :count', ['count' => count($c->repositories ?? [])]) }} ·
                                @endif
                                {{ __('Napojené weby: :count', ['count' => $c->sites_count]) }}
                                · {{ __('naposledy') }} {{ $c->last_used_at?->diffForHumans() ?? __('nepoužit') }}
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <x-wire-actions::button :action="$this->manageAction($c)" />
                            <x-wire-actions::button :action="$this->disconnectAction($c)" />
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        {{-- Footer: the one thing to do next --}}
        <div class="flex flex-col gap-3 border-t border-gray-100 bg-gray-50 px-6 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800 dark:bg-gray-800/40">
            <div class="text-xs text-gray-500 dark:text-gray-400">
                @if ($app)
                    {{ __('Na GitHubu vyberete účet nebo organizaci a repozitáře; panel dostane přístup jen k těm, kde jste správce.') }}
                @elseif ($this->isAdmin())
                    {{ __('Při prvním propojení GitHub vytvoří aplikaci tohoto panelu (stačí potvrdit) a hned ji nainstalujete.') }}
                @else
                    {{ __('Propojení tlačítkem zatím nenastavil správce panelu. Můžete použít osobní token.') }}
                @endif
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <x-wire-actions::button :action="$this->tokenAction()" />
                @if ($app)
                    <x-wire-actions::button :action="$this->connectAction()" />
                @elseif ($this->isAdmin())
                    <x-wire-actions::button :action="$this->organizationAppAction()" />
                    <x-wire-actions::button :action="$this->createAppAction()" />
                @endif
            </div>
        </div>
    </section>

    {{-- What it does, so nobody wonders what they grant --}}
    <section class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Co panel v repozitáři udělá') }}</h3>
        <ul class="mt-3 grid gap-3 sm:grid-cols-2">
            @foreach ([
                ['outline:key', __('Deploy klíč jen pro čtení'), __('Každý web má vlastní; jen stahuje kód.')],
                ['outline:bolt', __('Webhook na push'), __('Podepsaný; u panelu ve vnitřní síti místo něj kontrola každou minutu.')],
                ['outline:rocket-launch', __('Stav deployů v GitHubu'), __('Deployments s odkazem na web a na log v panelu.')],
                ['outline:lock-closed', __('Žádný trvalý token'), __('Panel si pro každé volání vyžádá token platný hodinu.')],
            ] as [$icon, $title, $text])
                <li class="flex gap-3">
                    <x-wire::icon :name="$icon" class="mt-0.5 size-5 shrink-0 text-gray-400" />
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $title }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $text }}</p>
                    </div>
                </li>
            @endforeach
        </ul>
        <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
            <a href="{{ \App\Livewire\Pages\HelpPage::url(parameters: ['doc' => 'github']) }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400" @wireNavigate(\App\Livewire\Pages\HelpPage::url(parameters: ['doc' => 'github']))>{{ __('Jak propojení funguje') }} →</a>
        </p>
    </section>
</div>
