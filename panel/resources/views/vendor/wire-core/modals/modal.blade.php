{{-- General Modal Component --}}
{{-- Teleported to <body> so a transformed/overflow ancestor can never break the
     fixed overlay's viewport positioning. (Floating UI N/A — modals are centered,
     not anchored to a trigger.) --}}
@php
    // wire:model comes from the <x-wire-modals::modal> tag's attribute bag
    // (consumer path) or the Htmlable Modal object, which passes $wireModel
    // (Rule 5). isset()/?? keep $attributes untouched off the component path.
    $modelBinding = $wireModel ?? (isset($attributes) ? $attributes->wire('model') : null);
    // The consumer-path wire() macro returns a WireDirective even when the
    // attribute is absent, so "has a binding" must go through value().
    $hasModelBinding = $modelBinding instanceof \Livewire\WireDirective
        ? ! in_array($modelBinding->value(), [null, false, ''], true)
        : filled($modelBinding);
    // Without a wire:model binding, `show` is plain Alpine state and an
    // optional $openOn window event opens the modal from JS.
    $openEvent = ($openOn ?? null) ?: null;
    // Attribute-name position: only a safe token may reach the x-on: binding
    // (a space would inject a new attribute — Blade only escapes quotes).
    if ($openEvent !== null && ! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $openEvent)) {
        $openEvent = null;
    }
@endphp
<template x-teleport="body" wire:key="wire-modal-modal{{ $id ? '-'.$id : '' }}">
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
    class="fixed inset-0 z-50 overflow-y-auto"
    @if($id) id="{{ $id }}" @endif
    aria-labelledby="modal-title"
    role="dialog"
    aria-modal="true"
>
    <div class="{{ $style->containerClasses() }}">
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

        <span class="hidden sm:inline-block sm:h-screen sm:align-middle" aria-hidden="true">&#8203;</span>

        {{-- Modal Panel --}}
        @php $transitions = $style->transitionClasses(); @endphp
        <div
            x-show="show"
            x-transition:enter="ease-out duration-300"
            x-transition:enter-start="{{ $transitions['enterStart'] }}"
            x-transition:enter-end="{{ $transitions['enterEnd'] }}"
            x-transition:leave="ease-in duration-200"
            x-transition:leave-start="{{ $transitions['leaveStart'] }}"
            x-transition:leave-end="{{ $transitions['leaveEnd'] }}"
            @class([
                'relative inline-block w-full transform overflow-hidden bg-white dark:bg-gray-800 text-left align-bottom shadow-xl transition-all sm:align-middle',
                $style->widthClass(),
                $style->panelVariantClasses(),
            ])
            @if($maxHeight) style="max-height: {{ $maxHeight }}" @endif
        >
            {{-- Header --}}
            @if($heading || $icon || isset($header))
                <div @class([
                    'relative px-4 pt-5 sm:px-6 sm:pt-6',
                    'sticky top-0 z-10 bg-white dark:bg-gray-800 border-b border-gray-100 dark:border-gray-700 pb-4' => $stickyHeader,
                ])>
                    <div class="sm:flex sm:items-start">
                        @if($icon)
                            <div class="mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full {{ $style->iconBgClass() }} sm:mx-0 sm:h-10 sm:w-10">
                                {!! icon($icon, 'w-4 h-4', 'h-6 w-6 ' . $style->iconColorClass()) !!}
                            </div>
                        @endif

                        {{-- px keeps a centered mobile heading clear of the pinned close button. --}}
                        <div class="mt-3 px-8 text-center sm:mt-0 sm:px-0 {{ $icon ? 'sm:ml-4' : '' }} sm:text-left flex-1">
                            @if($heading)
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white" id="modal-title">
                                    {{ $heading }}
                                </h3>
                            @endif

                            @if($description)
                                <div class="mt-2">
                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                        {{ $description }}
                                    </p>
                                </div>
                            @endif
                        </div>

                        {{-- Close button: pinned to the top-right corner on mobile (the
                             header only becomes a flex row from sm up), back in the
                             header row on ≥sm. --}}
                        <button
                            type="button"
                            @click="show = false; {{ $closeAction ? "\$wire.{$closeAction}()" : '' }}"
                            data-testid="modal-close" @wireEl('modal-close')
                            aria-label="{{ __('Close') }}"
            {{-- Larger tap target on mobile (p-2.5 ≈ 40px), back to the compact
                             desktop size from sm up. --}}
                            class="absolute right-2 top-2 sm:static sm:right-auto sm:top-auto sm:ml-auto sm:-mr-1.5 rounded-lg p-2.5 sm:p-1.5 text-gray-500 hover:text-gray-500 dark:hover:text-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 touch-manipulation"
                        >
                            <span class="sr-only">{{ __('Close') }}</span>
                            {!! icon('outline:x-mark', 'h-5 w-5') !!}
                        </button>
                    </div>

                    @isset($header)
                        {{ $header }}
                    @endisset
                </div>
            @endif

            {{-- Body --}}
            <div @class([
                'px-4 pb-4 sm:px-6 sm:pb-6',
                'pt-4' => $heading || $icon,
                'pt-5 sm:pt-6' => !$heading && !$icon,
                'overflow-y-auto overscroll-contain' => $maxHeight,
                // Mobile variants scroll the body inside the full-height panel;
                // from the breakpoint up it returns to the page-scroll layout
                // (breakpoint-aware, see ModalStyle::bodyVariantClasses()).
                $style->bodyVariantClasses() => $style->mobileVariant() !== null,
            ])>
                {{-- Body: a component slot (consumer tag), an @include'd partial
                     ($bodyView), or a pre-rendered Htmlable ($body) from the
                     Modal object (Rule 5). --}}
                @if(isset($bodyView))
                    @include($bodyView, $bodyData ?? [])
                @elseif(! empty($body))
                    {!! $body !!}
                @else
                    {{ $slot ?? '' }}
                @endif
            </div>

            {{-- Footer --}}
            @if(isset($footerView) || isset($footer))
                <div @class([
                    'px-4 pb-4 sm:px-6 sm:pb-6',
                    'sticky bottom-0 z-10 bg-white dark:bg-gray-800 border-t border-gray-100 dark:border-gray-700 pt-4' => $stickyFooter,
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
</template>
