@php
    use Illuminate\Database\Eloquent\Model;
    use NyonCode\WireCore\Actions\ActionGroup;
    use NyonCode\WireCore\Foundation\Support\MobileSheet;

    assert($group instanceof ActionGroup);
    /** @var Model|null $record */
    $record = $record ?? null;

    // Resolve visible actions once (auto-dividers included) and count only the
    // executable ones so a lone action collapses to an inline button. A null
    // record is a record-less surface (the toolbar), not "nothing to show".
    $visibleActions = $group->getVisibleActionsWithDividers($record);
    $actionCount = $group->countExecutableActions($visibleActions);
@endphp

@if($actionCount === 1)
    {{ $group->getSingleActionHtml($record, $click ?? null) }}
@elseif($actionCount > 1)
    {{-- Scaffolding is identical for every dropdown; emit it once per request
         instead of once per row-group (and per desktop/mobile layout). --}}
    @once
        @include('wire-core::partials.floating-assets')
    @endonce

    @php $lazyMenuItems = $group->isLazyMenu() ? $group->getDropdownItemSpecs($record, $click ?? null) : null; @endphp
    <div
        class="relative inline-block text-left"
        x-data="wireDropdown(@js($group->getDropdownConfig()), @js($lazyMenuItems))"
        @keydown.escape.window="close()"
    >
        <button
            type="button"
            x-ref="trigger"
            @click="toggle()"
            data-testid="action-group-trigger" @wireEl('action-group-trigger')
            :aria-expanded="open"
            aria-haspopup="menu"
            class="relative {{ $group->getTriggerClasses() }}"
            @if($group->getTooltip()) title="{{ $group->getTooltip() }}" @endif
        >
            {{ $group->getTriggerIconHtml() }}
            @if($group->getLabel())
                <span>{{ $group->getLabel() }}</span>
                {{ $group->getChevronSvg() }}
            @endif
            {{ $group->getBadgeHtml() }}
        </button>

        {{-- Teleported to <body>. From sm up it floats next to the trigger
             (Floating UI); on a phone it becomes a bottom sheet (max-sm: classes,
             Floating UI skipped by wireDropdown) with a dimming backdrop. --}}
        @php $sheetOnMobile = $group->usesSheetOnMobile(); $sheetBp = $group->getMobileBreakpoint(); @endphp
        <template x-teleport="body">
            <div>
                @if($sheetOnMobile)
                    {{-- Backdrop: mobile-only (sm:hidden), taps to close. --}}
                    <div
                        x-show="open"
                        x-cloak
                        x-transition:enter="transition ease-out duration-150"
                        x-transition:enter-start="opacity-0"
                        x-transition:enter-end="opacity-100"
                        x-transition:leave="transition ease-in duration-100"
                        x-transition:leave-start="opacity-100"
                        x-transition:leave-end="opacity-0"
                        @click="close()"
                        class="fixed inset-0 z-40 bg-gray-500/60 dark:bg-gray-900/70 {{ MobileSheet::backdropHide($sheetBp) }}"
                    ></div>
                @endif

                {{-- Base classes are the original desktop floating panel (kept
                     byte-identical); max-sm: overrides turn it into a bottom sheet
                     on a phone. Scale-pop transition on desktop, slide-up on
                     mobile — same x-transition, breakpoint-scoped classes. --}}
                <div
                    x-ref="panel"
                    x-show="open"
                    x-cloak
                    @click.outside="$clickedInside($event) || close()"
                    @if($sheetOnMobile) x-focus-trap="open" tabindex="-1" data-sheet-bp="{{ MobileSheet::px($sheetBp) }}" @endif
                    x-transition:enter="transition ease-out duration-100"
                    x-transition:enter-start="opacity-0 scale-95 {{ $sheetOnMobile ? MobileSheet::motion($sheetBp) : '' }}"
                    x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-75"
                    x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                    x-transition:leave-end="opacity-0 scale-95 {{ $sheetOnMobile ? MobileSheet::motion($sheetBp) : '' }}"
                    @class([
                        'absolute top-0 left-0 z-50 rounded-lg bg-white dark:bg-gray-800 shadow-lg ring-1 ring-black/5 dark:ring-white/10 focus:outline-none',
                        $group->getDropdownOriginClass(),
                        $group->getDropdownWidth(),
                        MobileSheet::panel($sheetBp) => $sheetOnMobile,
                    ])
                    style="display: none;"
                    role="menu"
                >
                    @if($sheetOnMobile)
                        @include('wire-core::partials.sheet-grabber', ['dismiss' => 'close()', 'breakpoint' => $sheetBp])
                    @endif
                    <div class="py-1">
                        @if($group->isLazyMenu())
                            {{-- Lazy menu: the row ships NO menu Blade markup (zero
                                 dropdown-item view renders); the items are built
                                 client-side from the serialized spec into this hidden,
                                 teleported panel and revealed on open. The click calls
                                 the $wire captured at init (survives the teleport), never
                                 an evaluated string (CSP-safe). --}}
                            <template x-for="(item, i) in (items || [])" :key="i">
                                <div>
                                    <template x-if="item.type === 'divider'">
                                        <div class="my-1 border-t border-gray-100 dark:border-gray-700/60"></div>
                                    </template>
                                    <template x-if="item.type === 'html'">
                                        <div x-html="item.html"></div>
                                    </template>
                                    {{-- Two link templates, not one: Livewire reads `wire:navigate`
                                         once, when Alpine initialises the element, so an attribute
                                         bound or added later would never navigate. --}}
                                    <template x-if="item.type === 'link' && ! item.navigate">
                                        <a :href="item.href" :target="item.newTab ? '_blank' : null" :class="item.classes"
                                           role="menuitem" :data-testid="item.testId">
                                            <span class="contents" x-html="item.iconHtml"></span>
                                            <span class="flex-1" x-text="item.label"></span>
                                            <template x-if="item.shortcut">
                                                <kbd class="ml-auto pl-2 text-[10px] font-mono text-gray-500 dark:text-gray-400" x-text="item.shortcut"></kbd>
                                            </template>
                                        </a>
                                    </template>
                                    <template x-if="item.type === 'link' && item.navigate">
                                        <a :href="item.href" wire:navigate :class="item.classes"
                                           role="menuitem" :data-testid="item.testId">
                                            <span class="contents" x-html="item.iconHtml"></span>
                                            <span class="flex-1" x-text="item.label"></span>
                                            <template x-if="item.shortcut">
                                                <kbd class="ml-auto pl-2 text-[10px] font-mono text-gray-500 dark:text-gray-400" x-text="item.shortcut"></kbd>
                                            </template>
                                        </a>
                                    </template>
                                    <template x-if="item.type === 'disabled'">
                                        <span :class="item.classes" role="menuitem" aria-disabled="true" :data-testid="item.testId">
                                            <span class="contents" x-html="item.iconHtml"></span>
                                            <span class="flex-1" x-text="item.label"></span>
                                        </span>
                                    </template>
                                    <template x-if="item.type === 'button'">
                                        <button type="button" :class="item.classes" role="menuitem" :data-testid="item.testId"
                                                @click="runAction(item); close()">
                                            <span class="contents" x-html="item.iconHtml"></span>
                                            <span class="flex-1" x-text="item.label"></span>
                                            <template x-if="item.shortcut">
                                                <kbd class="ml-auto pl-2 text-[10px] font-mono text-gray-500 dark:text-gray-400" x-text="item.shortcut"></kbd>
                                            </template>
                                        </button>
                                    </template>
                                </div>
                            </template>
                        @else
                            {{ $group->getDropdownItemsHtml($record, $click ?? null) }}
                        @endif
                    </div>
                </div>
            </div>
        </template>
    </div>
@endif
