{{-- Every select surface, whichever control it draws: the combobox (Teleport +
     Floating UI, below), the browser's <select> (wire-core::partials.native-select),
     or both with the mobile breakpoint choosing (->nativeOnMobile()).

     One shared owner for the forms Select and BelongsToSelect and the table
     SelectFilter and TernaryFilter, panel and header row alike. Consumers
     resolve the mode through Foundation\Concerns\HasNativeControl and hand it
     in; the branching lives here once instead of in each of the seven views.
     Supports single and multiple selection and binds to a Livewire property
     path ($wire.entangle / wire:model) so the host component owns the state.

     The combobox markup is inline rather than a partial of its own on purpose:
     a Select costs one view render for its control, not two (FormRenderCountTest).

     Expected variables:
       $selectId         string                    DOM id for the trigger button
       $statePath        string                    Wire property path to entangle
       $options          array<array-key, string>  value => label map
       $placeholder      string|null               empty-state / clear label
       $multiple         bool                      multi-select mode (default false)
       $searchPrompt     string                    search input placeholder
       $noResultsMessage string                    shown when search matches nothing
       $disabled         bool                      disable the trigger (default false)
       $searchable       bool                      show the in-panel search input
                                                   (default true). When false the panel
                                                   is a plain option list, so searchable
                                                   and non-searchable selects share one
                                                   design. Ignored when $remoteSearch is
                                                   set (remote search needs the input).
       $hasError         bool                      apply error styling (default false)
       $panelFooter      string|null               extra HTML rendered at the bottom
                                                   of the dropdown panel (optional slot,
                                                   e.g. a "create new option" button)
       $remoteSearch     bool                      fetch options from the server as the
                                                   user types instead of filtering the
                                                   preloaded $options client-side. Calls
                                                   $wire.searchSelectOptions($statePath, term),
                                                   so the host must expose that method
                                                   (wire-forms WithForms). Default false.
       $loadingMessage   string                    shown while a remote search is in flight
       $live             bool                      entangle with the .live modifier so a
                                                   selection syncs to the server immediately
                                                   (fields with live()/afterStateUpdated,
                                                   table filters). Default false (deferred).
       $disabledValues   list<array-key>           option values shown but not pickable,
                                                   in both controls (Select::disabledOptions()).
                                                   Compared as strings. Default [].
       $nativeMode       NativeControlMode         Never (default) | Always | Mobile
       $mobileBreakpoint string                    where Mobile switches controls, and
                                                   where the combobox becomes a sheet
       $wireModel        string                    native binding attribute; defaults
                                                   to `wire:model.live` when $live
       $required         bool                      native `required` (default false)
       $selectedValues   list<string>|null         native first paint only
       $ariaLabel        string|null               native name in Mobile mode, where
                                                   the <label for> names the combobox
       $testId           string|null               native data-testid
       $touch            bool                      ->touchOnMobile(): below the breakpoint the
                                                   list opens as a full-height touch sheet
                                                   (44px+ rows, 16px, swipe down to close)
                                                   instead of the floating panel. Default false.
       $sheetTitle       string|null               the touch sheet's heading (the label)
--}}
@php
    $selectId ??= 'searchable-select';
    $placeholder ??= null;
    $multiple ??= false;
    $disabled ??= false;
    $hasError ??= false;
    $panelFooter ??= null;
    $remoteSearch ??= false;
    $loadingMessage ??= null;
    $searchable ??= true;
    $live ??= false;
    $nativeMode ??= \NyonCode\WireCore\Foundation\Enums\NativeControlMode::Never;
    $responsive = $nativeMode->isResponsive();
    $wireModel ??= $live ? 'wire:model.live' : 'wire:model';
    $required ??= false;
    $selectedValues ??= null;
    $ariaLabel ??= null;
    $testId ??= null;
    // Compared as strings on both sides: a PHP array key is an int whenever it
    // looks like one, and a disabled value may arrive either way.
    $disabledValues = array_map('strval', array_values($disabledValues ?? []));
    // Handed over as [value, label] pairs, not as an object: a JS object lists
    // integer-like keys in ascending order whatever order they were written in,
    // so a relationship select sorted by name would come out sorted by id.
    // Values go over as strings — what Object.entries() handed the controller
    // before, and so what a selection has always written back to the state.
    $optionPairs = [];
    if ($nativeMode->rendersCustom()) {
        foreach ($options as $optionValue => $optionLabel) {
            $optionPairs[] = [(string) $optionValue, $optionLabel];
        }
    }
    // Below sm the listbox becomes a bottom sheet (comfortable native-style
    // picker) instead of a trigger-anchored floating panel. Callers pass the
    // resolved value; a bare include falls back to the global config. When a
    // native twin owns that screen the sheet is never seen, so it is not drawn.
    $touch ??= false;
    $sheetTitle ??= null;
    // The floating panel never becomes a sheet when the phone has a native twin
    // or a touch sheet of its own — neither would ever be seen.
    $sheetOnMobile = ($sheetOnMobile ?? (bool) config('wire-core.mobile.sheet', true)) && ! $responsive && ! $touch;
    $mobileBreakpoint ??= \NyonCode\WireCore\Foundation\Support\MobileSheet::breakpoint();
    $sheetBpPx = \NyonCode\WireCore\Foundation\Support\MobileSheet::px($mobileBreakpoint);
    $sheetPanel = \NyonCode\WireCore\Foundation\Support\MobileSheet::panel($mobileBreakpoint);
    $sheetMotion = \NyonCode\WireCore\Foundation\Support\MobileSheet::motion($mobileBreakpoint);
    $sheetBackdrop = \NyonCode\WireCore\Foundation\Support\MobileSheet::backdropHide($mobileBreakpoint);
    // Remote search cannot work without the input; force it on in that mode.
    $showSearch = $searchable || $remoteSearch;
@endphp

@if($nativeMode->rendersNative())
    {{-- In Mobile mode the <label for> points at the combobox trigger, so the
         twin takes its own id and carries its name itself; `required` would stop
         a form submitting on a desktop, where the twin is display:none. --}}
    @include('wire-core::partials.native-select', $responsive ? [
        'selectId' => $selectId.'-native',
        'required' => false,
        'ariaRequired' => $required,
        'visibilityClass' => \NyonCode\WireCore\Foundation\Support\MobileSheet::showBelow($mobileBreakpoint),
    ] : [
        'ariaRequired' => false,
        'ariaLabel' => null,
        'visibilityClass' => null,
    ])
@endif

@if($nativeMode->rendersCustom())
@include('wire-core::partials.floating-assets')

<div
    {{-- Body registered as `wireSearchableSelect` in core's dropdown bundle
         (packages/core/resources/js/select/controller.js) — it is core's because
         seven surfaces across forms and table render this partial. Only the
         per-instance config is markup; `state` is built here because
         `$wire.entangle` is an Alpine magic, in scope only inside x-data. --}}
    x-data="wireSearchableSelect({
        state: $wire.entangle('{{ $statePath }}'){!! $live ? '.live' : '' !!},
        statePath: @js($statePath),
        selectId: @js($selectId),
        multiple: @js($multiple),
        remote: @js($remoteSearch),
        initialOptions: @js($optionPairs),
        disabledValues: @js($disabledValues),
        placeholder: @js($placeholder ?? ''),
        sheetOnMobile: @js($sheetOnMobile),
        sheetBreakpoint: @js($sheetBpPx),
        touch: @js($touch),
        touchBreakpoint: @js($sheetBpPx),
    })"
    @class(['relative', \NyonCode\WireCore\Foundation\Support\MobileSheet::hideBelow($mobileBreakpoint) => $responsive])
    @select-option-created.window="upsertOption($event.detail)"
    @select-option-updated.window="upsertOption($event.detail)"
>
    {{-- Every binding that re-renders reads through `$data` (`$data.open`, not
         `open`). wire:navigate can run an effect Alpine had already queued after
         this component's scope is gone — worst in the teleported panel, which
         lives outside it — and a bare name then throws "is not defined", or,
         for `open`, finds `window.open` and calls it ("Illegal invocation").
         Through `$data` it is only undefined. Handlers (@click) run on events
         and keep the short form. --}}
    <button
        type="button"
        id="{{ $selectId }}"
        data-testid="select-trigger" @wireEl('select-trigger')
        {!! $extraInputAttributes ?? '' !!}
        x-ref="trigger"
        @click="open = !open"
        @keydown.arrow-down.prevent="onArrowDown()"
        @keydown.arrow-up.prevent="onArrowUp()"
        @keydown.enter.prevent="onEnter()"
        @keydown.escape="open = false; activeIndex = -1"
        aria-haspopup="listbox"
        :aria-expanded="$data.open"
        :aria-activedescendant="$data.activeDescendant"
        @if($disabled) disabled @endif
        @class([
            'flex items-center justify-between gap-2 w-full rounded-md border border-gray-300 shadow-sm px-3 py-2 text-left text-sm',
            'bg-white dark:bg-gray-800 dark:border-gray-600 dark:text-white',
            'focus:border-primary-500 focus:ring-1 focus:ring-primary-500',
            'hover:border-gray-400 dark:hover:border-gray-500 transition-colors duration-150',
            'disabled:opacity-50 disabled:cursor-not-allowed',
            'border-red-500' => $hasError,
            \NyonCode\WireCore\Foundation\Support\MobileSheet::touchTrigger($mobileBreakpoint) => $touch,
        ])
    >
        {{-- Truncated, never wrapped or stretched: a long label — or a multi-select's
             comma-joined list — would otherwise widen the trigger past a phone's
             edge. --}}
        <span class="min-w-0 flex-1 truncate" x-text="$data.selectedLabel || $data.placeholder" :class="{ 'text-gray-500 dark:text-gray-400': ! $data.selectedLabel }"></span>
        {!! icon('chevron-down', 'w-4 h-4', 'w-4 h-4 text-gray-500 dark:text-gray-400 shrink-0 transition-transform duration-150', '', [':class' => "{ 'rotate-180': \$data.open }"]) !!}
    </button>

    {{-- Floating listbox from sm up; bottom sheet on a phone (max-sm: classes,
         $float skips Floating UI) with a dimming backdrop. --}}
    <template x-teleport="body">
        <div>
            @if($sheetOnMobile)
                {{-- Backdrop: mobile-only, taps to close. --}}
                <div
                    x-show="$data.open"
                    x-cloak
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    x-transition:leave="transition ease-in duration-100"
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                    @click="open = false; activeIndex = -1"
                    class="fixed inset-0 z-40 bg-gray-500/60 dark:bg-gray-900/70 {{ $sheetBackdrop }}"
                ></div>
            @endif

            <div
                x-ref="panel"
                x-show="$data.open"
                {{-- On a phone in touch mode this panel is hidden and the touch
                     sheet is open instead; a tap in that sheet is not "outside". --}}
                @click.outside="if (! touchNow && ! $clickedInside($event)) { open = false; activeIndex = -1 }"
                @if($sheetOnMobile) x-focus-trap="$data.open" tabindex="-1" data-sheet-bp="{{ $sheetBpPx }}" @endif
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 -translate-y-1 {{ $sheetOnMobile ? $sheetMotion : '' }}"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-100"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 -translate-y-1 {{ $sheetOnMobile ? $sheetMotion : '' }}"
                @class([
                    'absolute top-0 left-0 z-50 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-md shadow-lg max-h-60 overflow-auto',
                    $sheetPanel => $sheetOnMobile,
                    \NyonCode\WireCore\Foundation\Support\MobileSheet::hideBelow($mobileBreakpoint) => $touch,
                ])
                x-cloak
                style="display: none;"
            >
            @if($sheetOnMobile)
                @include('wire-core::partials.sheet-grabber', ['dismiss' => 'open = false; activeIndex = -1', 'breakpoint' => $mobileBreakpoint])
            @endif
            @if($showSearch)
            {{-- 16px below sm, as on the native twin: the input takes focus as the
                 list opens, and iOS Safari zooms the page into any control under
                 16px — on a phone the whole form looks pushed off the edge. --}}
            <div class="p-2">
                <input
                    type="text"
                    autocomplete="off"
                    x-model.debounce.300ms="search"
                    @keydown.arrow-down.prevent="onArrowDown()"
                    @keydown.arrow-up.prevent="onArrowUp()"
                    @keydown.enter.prevent="onEnter()"
                    @keydown.escape="open = false; activeIndex = -1"
                    placeholder="{{ $searchPrompt }}"
                    aria-label="{{ $searchPrompt }}"
                    data-testid="select-search" @wireEl('select-search')
                    class="w-full rounded-md border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-white text-base sm:text-sm focus:border-primary-500 focus:ring-primary-500 transition-colors duration-150 placeholder:text-gray-500 dark:placeholder:text-gray-400"
                    x-ref="searchInput"
                />
            </div>
            @endif

            <ul class="py-1" role="listbox" :aria-activedescendant="$data.activeDescendant" @if($multiple) aria-multiselectable="true" @endif>
                @if($placeholder !== null && $placeholder !== '')
                    <li role="option" aria-selected="false">
                        <button
                            type="button"
                            @click="clear()"
                            data-testid="select-clear" @wireEl('select-clear')
                            class="w-full px-3 py-2 text-left text-sm text-gray-500 dark:text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700 transition-colors duration-150"
                        >
                            {{ $placeholder }}
                        </button>
                    </li>
                @endif

                <template x-for="([value, label], index) in ($data.filteredOptions ?? [])" :key="value">
                    <li role="option" :aria-selected="$data.isSelected?.(value)" :aria-disabled="$data.isOptionDisabled?.(value)" :id="'{{ $selectId }}-option-' + value">
                        <button
                            type="button"
                            @click="select(value)"
                            :disabled="$data.isOptionDisabled?.(value)"
                            :data-testid="'select-option-' + value"
                            @mouseenter="activeIndex = index"
                            class="flex items-center justify-between gap-2 w-full px-3 py-2 text-left text-sm dark:text-white transition-colors duration-150 disabled:opacity-40 disabled:cursor-not-allowed"
                            :class="{
                                'bg-primary-50 dark:bg-primary-900/20 text-primary-600 dark:text-primary-400': $data.isSelected?.(value),
                                'bg-gray-100 dark:bg-gray-700': $data.activeIndex === index && ! $data.isSelected?.(value),
                                'hover:bg-gray-100 dark:hover:bg-gray-700': $data.activeIndex !== index && ! $data.isSelected?.(value) && ! $data.isOptionDisabled?.(value),
                            }"
                        >
                            <span class="min-w-0 break-words" x-text="label"></span>
                            {!! icon('check', 'w-4 h-4', 'w-4 h-4 shrink-0', '', ['x-show' => '$data.isSelected?.(value)', 'x-cloak' => '']) !!}
                        </button>
                    </li>
                </template>

                @if($remoteSearch)
                    <li x-show="$data.loading" class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400" role="option" aria-disabled="true">
                        {{ $loadingMessage ?? __('Loading...') }}
                    </li>
                @endif

                <li x-show="! $data.loading && ($data.filteredOptions ?? []).length === 0" class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400" role="option" aria-disabled="true">
                    {{ $noResultsMessage }}
                </li>
            </ul>

            @if($panelFooter !== null && $panelFooter !== '')
                {!! $panelFooter !!}
            @endif
            </div>

            @if($touch)
                @include('wire-core::partials.select-touch-sheet')
            @endif
        </div>
    </template>
</div>
@endif
