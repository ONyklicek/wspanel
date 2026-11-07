{{-- Slide-Over Panel Component --}}
{{-- Teleported to <body> so a transformed/overflow ancestor can never break the
     fixed overlay's viewport positioning. (Floating UI N/A — slide-overs are
     edge-pinned to the viewport, not anchored to a trigger.) --}}
@php
    // wire:model comes from the <x-wire-modals::slide-over> tag's attribute bag
    // (consumer path) or the Htmlable SlideOver object, which passes $wireModel
    // (Rule 5). isset()/?? keep $attributes untouched off the component path.
    $modelBinding = $wireModel ?? (isset($attributes) ? $attributes->wire('model') : null);
    // The consumer-path wire() macro returns a WireDirective even when the
    // attribute is absent, so "has a binding" must go through value().
    $hasModelBinding = $modelBinding instanceof \Livewire\WireDirective
        ? ! in_array($modelBinding->value(), [null, false, ''], true)
        : filled($modelBinding);
    // Without a wire:model binding, `show` is plain Alpine state and an
    // optional $openOn window event opens the panel from JS.
    $openEvent = ($openOn ?? null) ?: null;
    // Attribute-name position: only a safe token may reach the x-on: binding
    // (a space would inject a new attribute — Blade only escapes quotes).
    if ($openEvent !== null && ! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $openEvent)) {
        $openEvent = null;
    }
@endphp
<template x-teleport="body" wire:key="wire-modal-slideover{{ $id ? '-'.$id : '' }}">
<div
    @if($hasModelBinding)
    x-data="{ show: @entangle($modelBinding) }"
    @else
    x-data="{ show: false }"
    @if($openEvent) x-on:{{ $openEvent }}.window="show = true" @endif
    @endif
    @include('wire-core::modals.partials.focus-trap')
    x-show="show"
    x-cloak
    style="display: none;@if($zIndex !== null) z-index: {{ $zIndex }};@endif"
    @if($closeOnEscape) x-on:keydown.escape.window="show = false; {{ $closeAction ? "\$wire.{$closeAction}()" : '' }}" @endif
    class="fixed inset-0 z-50 overflow-hidden"
    @if($id) id="{{ $id }}" @endif
    aria-labelledby="slide-over-title"
    role="dialog"
    aria-modal="true"
>
    {{-- Backdrop --}}
    <div
        x-show="show"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/80 backdrop-blur-sm transition-opacity"
        @if($closeOnClickAway) @click="show = false; {{ $closeAction ? "\$wire.{$closeAction}()" : '' }}" @endif
    ></div>

    <div class="fixed {{ $style->positionClasses() }} flex max-w-full">
        {{-- Panel --}}
        <div
            x-show="show"
            x-transition:enter="transform transition ease-in-out duration-300"
            x-transition:enter-start="{{ $style->translateEnterStart() }}"
            x-transition:enter-end="{{ $style->translateEnterEnd() }}"
            x-transition:leave="transform transition ease-in-out duration-300"
            x-transition:leave-start="{{ $style->translateLeaveStart() }}"
            x-transition:leave-end="{{ $style->translateLeaveEnd() }}"
            class="{{ $style->widthWrapperClasses() }} {{ $style->widthClass() }}"
        >
            <div class="flex flex-col bg-white dark:bg-gray-800 shadow-xl {{ $style->panelClasses() }}">
                {{-- Header --}}
                <div @class([
                    'px-4 py-6 sm:px-6',
                    'sticky top-0 z-10 bg-white dark:bg-gray-800 border-b border-gray-100 dark:border-gray-700' => $stickyHeader,
                ])>
                    <div class="flex items-start justify-between">
                        <div>
                            @if($heading)
                                <h2 class="text-lg font-semibold text-gray-900 dark:text-white" id="slide-over-title">
                                    {{ $heading }}
                                </h2>
                            @endif

                            @if($description)
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    {{ $description }}
                                </p>
                            @endif
                        </div>

                        {{-- Close button --}}
                        <div class="ml-3 flex h-7 items-center">
                            {{-- Negative margin grows the tap area to ~36px without
                                 shifting the icon's visual position. --}}
                            <button
                                type="button"
                                @click="show = false; {{ $closeAction ? "\$wire.{$closeAction}()" : '' }}"
                                data-testid="slide-over-close" @wireEl('slide-over-close')
                                aria-label="{{ __('Close') }}"
                                class="-m-1.5 rounded-md p-1.5 text-gray-500 hover:text-gray-500 dark:hover:text-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 touch-manipulation"
                            >
                                <span class="sr-only">{{ __('Close') }}</span>
                                {!! icon('outline:x-mark', 'h-6 w-6') !!}
                            </button>
                        </div>
                    </div>

                    @isset($header)
                        <div class="mt-4">
                            {{ $header }}
                        </div>
                    @endisset
                </div>

                {{-- Body --}}
                {{-- overscroll-contain stops the scroll chaining to the page behind
                     once the body reaches its top/bottom edge. --}}
                <div @class([
                    'relative flex-1 px-4 sm:px-6 py-4 overflow-y-auto overscroll-contain',
                ])
                    @if($maxHeight) style="max-height: {{ $maxHeight }}" @endif
                >
                    {{-- Body: a component slot (consumer tag), an @include'd partial
                         ($bodyView), or a pre-rendered Htmlable ($body) from the
                         SlideOver object (Rule 5). --}}
                    @if(isset($bodyView))
                        @include($bodyView, $bodyData ?? [])
                    @elseif(! empty($body))
                        {!! $body !!}
                    @else
                        {{ $slot ?? '' }}
                    @endif
                </div>

                {{-- Footer --}}
                {{-- The bottom padding carries the safe-area inset: a full-height
                     slide-over and a mobile sheet both end at the bottom edge of
                     the screen, which on a phone is where the home indicator is
                     drawn. `env()` is 0 everywhere else, so this costs nothing on
                     a desktop. --}}
                @if(isset($footerView) || isset($footer))
                    <div @class([
                        'px-4 pt-4 pb-[calc(1rem_+_env(safe-area-inset-bottom))] sm:px-6 border-t border-gray-200 dark:border-gray-700',
                        'sticky bottom-0 z-10 bg-white dark:bg-gray-800' => $stickyFooter,
                    ])>
                        @isset($footerView)
                            @include($footerView, $footerData ?? [])
                        @else
                            {!! $footer !!}
                        @endisset
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
</template>
