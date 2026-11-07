{{-- The touch list a select becomes on a phone (->touchOnMobile()): a bottom
     sheet with 48px rows, 16px text, the search pinned at the top and a grabber
     to swipe it away — full height when searchable, the height of its rows when
     not.

     Rendered inside wire-core::partials.select-control's teleport, beside the
     floating panel, and driven by the same `wireSearchableSelect` state — the
     options, the filtering, remote search, disabled values and the create/edit
     footer are the combobox's own, so a phone loses none of them. CSS decides
     which of the two shows (showBelow / hideBelow at the same breakpoint); the
     controller decides `touchNow` as the list opens, which gates the focus trap.

     Selection is live, as in the floating panel: a single pick closes the
     sheet, a multiple pick toggles a checkmark and leaves it open for "Done".

     Inherits select-control's scope ($selectId, $multiple, $placeholder,
     $showSearch, $searchPrompt, $noResultsMessage, $remoteSearch,
     $loadingMessage, $panelFooter, $mobileBreakpoint, $sheetTitle). --}}
@php
    use NyonCode\WireCore\Foundation\Support\MobileSheet;
@endphp
<div
    x-show="open"
    x-cloak
    x-transition.opacity.duration.150ms
    @click="open = false; activeIndex = -1"
    class="fixed inset-0 z-40 bg-gray-500/60 dark:bg-gray-900/70 {{ MobileSheet::showBelow($mobileBreakpoint) }}"
></div>

<div
    x-show="open"
    x-cloak
    x-trap.noscroll.noautofocus="open && touchNow"
    role="dialog"
    aria-modal="true"
    @if($sheetTitle) aria-label="{{ $sheetTitle }}" @endif
    @keydown.escape="open = false; activeIndex = -1"
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="translate-y-full"
    x-transition:enter-end="translate-y-0"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="translate-y-0"
    x-transition:leave-end="translate-y-full"
    {{-- A searchable list takes the full height, so it does not jump as the
         search narrows it; a short list sits at the height of its rows, the
         way an iOS sheet rests at its content. --}}
    @class([
        'fixed inset-x-0 bottom-0 z-50 rounded-t-2xl bg-white shadow-2xl dark:bg-gray-800',
        MobileSheet::showBelow($mobileBreakpoint),
        'top-12' => $showSearch,
        'max-h-[calc(100dvh-3rem)]' => ! $showSearch,
    ])
    data-testid="select-touch-sheet"
    style="display: none;"
>
    <div @class(['flex flex-col', 'h-full' => $showSearch, 'max-h-[calc(100dvh-3rem)]' => ! $showSearch])>
        @include('wire-core::partials.sheet-grabber', ['dismiss' => 'open = false; activeIndex = -1', 'breakpoint' => $mobileBreakpoint])

        <div class="flex items-center justify-between gap-3 px-4 pb-2">
            <div class="min-w-0">
                <p class="truncate text-base font-semibold text-gray-900 dark:text-white">{{ $sheetTitle ?? $placeholder }}</p>
                @if($multiple)
                    <p class="text-sm text-gray-500 dark:text-gray-400"
                       x-show="Array.isArray(selected) && selected.length"
                       x-text="@js(__('wire-core::messages.select_selected', ['count' => '__N__'])).replace('__N__', Array.isArray(selected) ? selected.length : 0)"></p>
                @endif
            </div>
            <button type="button" @click="open = false; activeIndex = -1" data-testid="select-touch-done"
                    class="min-h-11 shrink-0 px-2 text-base font-semibold text-primary-600 dark:text-primary-400">
                {{ __('wire-core::messages.select_done') }}
            </button>
        </div>

        @if($showSearch)
            <div class="px-4 pb-2">
                {{-- 16px, or iOS zooms the page in the moment it is tapped. --}}
                <input
                    type="search"
                    autocomplete="off"
                    x-model.debounce.300ms="search"
                    placeholder="{{ $searchPrompt }}"
                    aria-label="{{ $searchPrompt }}"
                    enterkeyhint="search"
                    data-testid="select-touch-search"
                    class="h-11 w-full rounded-xl border-0 bg-gray-100 px-4 text-base text-gray-900 placeholder-gray-500 focus:ring-2 focus:ring-primary-500 dark:bg-gray-700 dark:text-white"
                />
            </div>
        @endif

        <ul role="listbox" @if($multiple) aria-multiselectable="true" @endif
            class="min-h-0 flex-1 overflow-y-auto overscroll-contain border-t border-gray-100 dark:border-gray-700">
            @if(! $multiple && $placeholder !== null && $placeholder !== '')
                <li role="option" :aria-selected="selected === null || selected === ''">
                    <button type="button" @click="clear()" data-testid="select-touch-clear"
                            class="flex min-h-12 w-full items-center justify-between gap-3 px-4 text-left text-base text-gray-500 active:bg-gray-100 dark:text-gray-400 dark:active:bg-gray-700">
                        <span>{{ $placeholder }}</span>
                    </button>
                </li>
            @endif

            <template x-for="[value, label] in filteredOptions" :key="value">
                <li role="option" :aria-selected="isSelected(value)" :aria-disabled="isOptionDisabled(value)">
                    <button
                        type="button"
                        @click="select(value)"
                        :disabled="isOptionDisabled(value)"
                        :data-testid="'select-touch-option-' + value"
                        class="flex min-h-12 w-full items-center gap-3 px-4 text-left text-base text-gray-900 active:bg-gray-100 disabled:opacity-40 dark:text-white dark:active:bg-gray-700"
                        :class="{ 'font-semibold text-primary-600 dark:text-primary-400': isSelected(value) && ! multiple }"
                    >
                        @if($multiple)
                            {{-- A checkbox, not a trailing tick: in a list you tick
                                 through, the mark belongs where the thumb starts. --}}
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md border-2"
                                  :class="isSelected(value) ? 'border-primary-600 bg-primary-600 text-white' : 'border-gray-300 dark:border-gray-500'">
                                {!! icon('check', 'w-4 h-4', '', '', ['x-show' => 'isSelected(value)', 'x-cloak' => '']) !!}
                            </span>
                        @endif
                        <span class="min-w-0 flex-1 truncate" x-text="label"></span>
                        @unless($multiple)
                            {!! icon('check', 'w-5 h-5', 'shrink-0 text-primary-600 dark:text-primary-400', '', ['x-show' => 'isSelected(value)', 'x-cloak' => '']) !!}
                        @endunless
                    </button>
                </li>
            </template>

            @if($remoteSearch)
                <li x-show="loading" class="px-4 py-3 text-base text-gray-500 dark:text-gray-400" role="option" aria-disabled="true">
                    {{ $loadingMessage ?? __('Loading...') }}
                </li>
            @endif

            <li x-show="!loading && filteredOptions.length === 0" class="px-4 py-3 text-base text-gray-500 dark:text-gray-400" role="option" aria-disabled="true">
                {{ $noResultsMessage }}
            </li>
        </ul>

        @if($multiple)
            <div class="flex justify-center border-t border-gray-200 px-4 py-1 dark:border-gray-700" x-show="Array.isArray(selected) && selected.length">
                <button type="button" @click="clear()" data-testid="select-touch-clear-all"
                        class="min-h-11 px-3 text-base text-gray-500 dark:text-gray-400">
                    {{ __('wire-core::messages.select_clear_all') }}
                </button>
            </div>
        @endif

        @if($panelFooter !== null && $panelFooter !== '')
            <div class="border-t border-gray-200 dark:border-gray-700">{!! $panelFooter !!}</div>
        @endif

        <div class="pb-[env(safe-area-inset-bottom)]"></div>
    </div>
</div>
