@php
    $site = $this->site();
    $deployments = $this->deployments();
    $live = $deployments->firstWhere('release', $current);
    $last = $deployments->first();
    $selected = $deployments->firstWhere('release', $logRelease);
@endphp
<div class="wire-resource-page space-y-4 sm:space-y-6">
    @include('wire-panels::pages.partials.header')

    <div @if ($running || $logStatus === 'running') wire:poll.2s="refreshLog" @endif class="space-y-6">
        @if (! $config)
            {{-- Not set up yet: the only time the walkthrough takes the page. --}}
            <x-wire::section :heading="__('Deploy zatím není nastavený')"
                :description="__('Deploy nasadí novou verzi z Gitu bez výpadku: připraví ji vedle běžící a přepne, až když je hotová a odpovídá.')">
                <div class="grid gap-6 lg:grid-cols-2">
                    <ol class="flex flex-col gap-3 text-sm">
                        @foreach ([
                            [__('Nastavení'), __('adresa repozitáře a větev (tlačítko Nastavení vpravo nahoře).')],
                            [__('Deploy klíč'), __('u privátního repozitáře ho přidejte do Gitu (Další → Deploy klíč).')],
                            ['.env', __('doplňte hodnoty aplikace (Další → .env). Databázi a poštu vyplní panel sám.')],
                            ['Deploy', __('první nasazení tlačítkem Deploy. Automatické nasazení zapnete v Automatický deploy.')],
                        ] as $i => [$title, $desc])
                            <li class="flex gap-3">
                                <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary-50 text-xs font-semibold text-primary-700 dark:bg-primary-400/10 dark:text-primary-400">{{ $i + 1 }}</span>
                                <span><span class="font-medium">{{ $title }}</span>: <span class="text-gray-600 dark:text-gray-300">{{ $desc }}</span></span>
                            </li>
                        @endforeach
                    </ol>
                    @if ($site->framework === \App\Enums\Framework::Php)
                        <x-wire::callout color="info" :heading="__('Deploy nepotřebujete?')">
                            {{ __('U čistého PHP stačí nahrát soubory přes SFTP do složky') }} <code>public_html/</code> {{ __('(Nastavení → Přístup → SSH / SFTP).') }}
                        </x-wire::callout>
                    @endif
                </div>
            </x-wire::section>
        @else
            {{-- What runs now and how it gets there. --}}
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Běží') }}</p>
                    @if ($live)
                        <p class="mt-1 font-mono text-sm font-semibold">{{ $live->versionLabel() }}</p>
                        <p class="mt-1 text-sm text-gray-500" title="{{ $live->release }}">nasazeno {{ $live->startedLabel() }}@if ($live->source === 'artifact') · build z CI @endif</p>
                    @else
                        <p class="mt-1 text-sm text-gray-500">{{ $current !== '' && $current !== 'initial' ? $current : __('úvodní stránka (zatím bez deploye)') }}</p>
                    @endif
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Poslední deploy') }}</p>
                    @if ($last)
                        <p class="mt-1 flex items-center gap-2 text-sm">
                            <x-wire::badge :color="$last->statusColor()">{{ $last->statusLabel() }}</x-wire::badge>
                            <span class="text-gray-500">{{ $last->started_at?->diffForHumans() }}</span>
                        </p>
                        @if ($last->error)
                            <p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $last->error }}</p>
                        @endif
                    @else
                        <p class="mt-1 text-sm text-gray-500">{{ __('zatím žádný') }}</p>
                    @endif
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Zdroj') }}</p>
                    <p class="mt-1 truncate font-mono text-sm" title="{{ $config['repo'] ?? '' }}">{{ preg_replace('~^(https://|git@|ssh://git@)~', '', $config['repo'] ?? '') }}</p>
                    <p class="mt-1 text-sm text-gray-500">{{ __('větev') }} <span class="font-mono">{{ $config['branch'] ?? '' }}</span>
                        · {{ collect(['migrace' => $config['migrate'] ?? false, 'assety' => $config['assets'] ?? false, 'auto-rollback' => $config['auto_rollback'] ?? false])->filter()->keys()->join(', ') ?: __('jen kód') }}</p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Automaticky') }}</p>
                    @if ($site->webhook_enabled)
                        <p class="mt-1 text-sm font-medium">{{ __($this::TRIGGERS[$site->deploy_trigger ?? 'branch'] ?? $site->deploy_trigger) }}</p>
                        @if ($site->deploy_poll_error)
                            <p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $site->deploy_poll_error }}</p>
                        @elseif ($site->deploy_polled_at)
                            <p class="mt-1 text-sm text-gray-500">{{ __('zkontrolováno') }} {{ $site->deploy_polled_at->diffForHumans() }}</p>
                        @endif
                    @else
                        <p class="mt-1 text-sm text-gray-500">{{ __('vypnuto, nasazuje se ručně') }}</p>
                    @endif
                </div>
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-5">
            <div class="flex min-w-0 flex-col gap-6 lg:col-span-2">
                <x-wire::section :heading="__('Historie')">
                    <ul class="-mx-2 divide-y divide-gray-100 text-sm dark:divide-white/5">
                        @forelse ($deployments as $d)
                            <li wire:key="d-{{ $d->id }}">
                                <button type="button" wire:click="showLog('{{ $d->release }}')" @class([
                                    'flex w-full items-start justify-between gap-3 rounded-lg px-2 py-2.5 text-left transition hover:bg-gray-50 dark:hover:bg-white/5',
                                    'bg-primary-50/60 dark:bg-primary-400/5' => $d->release === $logRelease,
                                ])>
                                    <span class="min-w-0">
                                        <span class="flex items-center gap-2">
                                            <span class="font-medium">{{ $d->startedLabel() }}</span>
                                            @if ($d->release === $current)
                                                <x-wire::badge color="primary" size="xs">{{ __('běží') }}</x-wire::badge>
                                            @endif
                                        </span>
                                        <span class="mt-0.5 block truncate text-xs text-gray-500">
                                            <span class="font-mono">{{ $d->versionLabel() }}</span>
                                            @if ($d->source === 'artifact') · build z CI @endif
                                            @if ($d->durationLabel()) · {{ $d->durationLabel() }} @endif
                                        </span>
                                    </span>
                                    <x-wire::badge :color="$d->statusColor()">{{ $d->statusLabel() }}</x-wire::badge>
                                </button>
                            </li>
                        @empty
                            <li class="px-2 py-2 text-gray-500">{{ __('Zatím žádný deploy.') }}</li>
                        @endforelse
                    </ul>
                </x-wire::section>

                @if ($site->webhook_enabled || $this->deliveries()->isNotEmpty())
                    <x-wire::section :heading="__('Doručené webhooky')">
                        <ul class="divide-y divide-gray-100 text-sm dark:divide-white/5">
                            @forelse ($this->deliveries() as $w)
                                <li class="flex items-start justify-between gap-2 py-2" wire:key="w-{{ $w->id }}">
                                    <span class="min-w-0">
                                        {{ $w->message }}
                                        <span class="block truncate text-xs text-gray-500">{{ $w->created_at?->diffForHumans() }} · {{ $w->provider ?? '?' }}@if ($w->commit) · <span class="font-mono">{{ substr($w->commit, 0, 7) }}</span>@endif · {{ $w->ip }}</span>
                                    </span>
                                    <x-wire::badge :color="$w->resultColor()">{{ $w->result }}</x-wire::badge>
                                </li>
                            @empty
                                <li class="py-2 text-gray-500">{{ __('Zapnuto, zatím nic nepřišlo.') }}</li>
                            @endforelse
                        </ul>
                    </x-wire::section>
                @endif
            </div>

            <div class="min-w-0 lg:col-span-3">
                <div class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-4 py-3 sm:px-6 dark:border-gray-700">
                        <h3 class="text-base font-semibold">
                            {{ __('Log') }}
                            @if ($selected)
                                <span class="font-normal text-gray-500">· {{ $selected->startedLabel() }}</span>
                            @endif
                        </h3>
                        @if ($logStatus === 'running')
                            <x-wire::badge color="warning" icon="outline:arrow-path">{{ __('běží…') }}</x-wire::badge>
                        @elseif ($selected)
                            <x-wire::badge :color="$selected->statusColor()">{{ $selected->statusLabel() }}</x-wire::badge>
                        @endif
                    </div>
                    <div class="p-4 sm:p-6">
                        @if ($selected?->error)
                            <p class="mb-3 text-sm text-danger-600 dark:text-danger-400">{{ $selected->error }}</p>
                        @endif
                        <pre class="max-h-[40rem] overflow-auto whitespace-pre-wrap rounded-lg bg-gray-950 p-4 font-mono text-xs leading-relaxed text-gray-100">{{ $log !== '' ? $log : __('Vyberte deploy v historii.') }}</pre>
                    </div>
                </div>
            </div>
        </div>

        @if ($config)
            <details class="group rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <summary class="cursor-pointer list-none px-4 py-3 text-sm font-medium sm:px-6">
                    <span class="text-gray-400 transition group-open:rotate-90 inline-block">›</span> {{ __('Co deploy dělá, krok za krokem') }}
                </summary>
                <ol class="grid gap-3 border-t border-gray-200 px-4 py-4 text-sm sm:grid-cols-2 sm:px-6 lg:grid-cols-3 dark:border-gray-700">
                    @foreach ($this->pipeline() as $i => [$title, $desc])
                        <li class="flex gap-3">
                            <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ $i + 1 }}</span>
                            <span><span class="font-medium">{{ $title }}</span><span class="block text-gray-500 dark:text-gray-400">{{ $desc }}</span></span>
                        </li>
                    @endforeach
                </ol>
            </details>
        @endif
    </div>
    @include('wire-panels::pages.partials.action-modals')
</div>
