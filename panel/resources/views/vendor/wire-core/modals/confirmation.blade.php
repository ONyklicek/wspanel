{{-- Confirmation Dialog Component --}}
{{-- Teleported to <body> so a transformed/overflow ancestor can never break the
     fixed overlay's viewport positioning. (Floating UI N/A — confirmations are
     centered on the viewport, not anchored to a trigger.) --}}
@php
    // wire:model / wire:click come from the <x-wire-modals::confirmation> tag's
    // attribute bag (consumer path) or from the Htmlable Confirmation object,
    // which passes $wireModel / $wireClick strings (Rule 5 — no <x-*> needed).
    // isset()/?? keep $attributes untouched when it is absent (object path).
    $modelBinding = $wireModel ?? (isset($attributes) ? $attributes->wire('model') : null);
    $confirmClick = ($wireClick ?? (isset($attributes) ? $attributes->wire('click')->value() : null)) ?: null;
    // The consumer-path wire() macro returns a WireDirective even when the
    // attribute is absent, so "has a binding" must go through value().
    $hasModelBinding = $modelBinding instanceof \Livewire\WireDirective
        ? ! in_array($modelBinding->value(), [null, false, ''], true)
        : filled($modelBinding);
    // Without a wire:model binding, `show` is plain Alpine state and an
    // optional $openOn window event opens the dialog from JS.
    $openEvent = ($openOn ?? null) ?: null;
    // Attribute-name position: only a safe token may reach the x-on: binding
    // (a space would inject a new attribute — Blade only escapes quotes).
    if ($openEvent !== null && ! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $openEvent)) {
        $openEvent = null;
    }
@endphp
<template x-teleport="body" wire:key="wire-modal-confirmation{{ $id ? '-'.$id : '' }}">
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
    aria-labelledby="confirmation-modal-title"
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

        {{-- Dialog Panel --}}
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
                'relative inline-block transform overflow-hidden bg-white dark:bg-gray-800 px-4 pt-5 pb-4 text-left align-bottom shadow-xl transition-all sm:p-6 sm:align-middle',
                $style->panelWidthClass(),
                $style->panelVariantClasses(),
            ])
        >
            <div class="sm:flex sm:items-start">
                {{-- Icon --}}
                @if($icon)
                    <div class="mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full {{ $style->iconBgClass() }} sm:mx-0 sm:h-10 sm:w-10">
                        {!! icon($icon, 'w-4 h-4', 'h-6 w-6 ' . $style->iconColorClass()) !!}
                    </div>
                @endif

                <div class="mt-3 text-center sm:mt-0 {{ $icon ? 'sm:ml-4' : '' }} sm:text-left flex-1">
                    @if($heading)
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white" id="confirmation-modal-title">
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

                    {{-- Extra body content: a component slot (consumer tag), an
                         @include'd partial ($bodyView), or a pre-rendered Htmlable
                         ($body) from the Confirmation object (Rule 5).

                         $maxHeight caps this block rather than the panel, so a
                         halt asking eight questions scrolls its fields while the
                         heading and the two buttons stay where the eye left them.
                         The general modal caps the panel instead, because there
                         the header and footer are sticky rows of their own. --}}
                    @php
                        $bodyMaxHeight = ($maxHeight ?? null) ?: null;
                        $bodyClasses = 'mt-4'.($bodyMaxHeight ? ' overflow-y-auto overscroll-contain' : '');
                    @endphp
                    @if(isset($bodyView))
                        <div class="{{ $bodyClasses }}" @if($bodyMaxHeight) style="max-height: {{ $bodyMaxHeight }}" @endif>
                            @include($bodyView, $bodyData ?? [])
                        </div>
                    @elseif(! empty($body))
                        <div class="{{ $bodyClasses }}" @if($bodyMaxHeight) style="max-height: {{ $bodyMaxHeight }}" @endif>
                            {!! $body !!}
                        </div>
                    @elseif(isset($slot) && $slot->isNotEmpty())
                        <div class="{{ $bodyClasses }}" @if($bodyMaxHeight) style="max-height: {{ $bodyMaxHeight }}" @endif>
                            {{ $slot }}
                        </div>
                    @endif
                </div>
            </div>

            {{-- Buttons. The row is reversed, so the first child renders rightmost:
                 [before…] [cancel] [submit] [after…] visually (matching the general
                 modal footer). Additional footer actions use the Action API. --}}
            <div class="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse gap-3">
                {{-- 'after' footer actions → rightmost --}}
                @foreach(($footerActions ?? []) as $footerAction)
                    @if(($footerAction['position'] ?? 'before') === 'after')
                        @include('wire-core::actions.partials.modal-host-footer-action', ['footerAction' => $footerAction])
                    @endif
                @endforeach

                {{-- Submit button (hidden for informative dialogs) --}}
                @unless($isInformative)
                    <button
                        type="button"
                        @if($confirmClick) wire:click="{{ $confirmClick }}" @endif
                        data-testid="confirmation-confirm" @wireEl('confirmation-confirm')
                        class="{{ $style->submitButtonClasses() }}"
                    >
                        {{ $submitLabel }}
                    </button>
                @endunless

                {{-- Cancel / Close button --}}
                <button
                    type="button"
                    @click="show = false; {{ $closeAction ? "\$wire.{$closeAction}()" : '' }}"
                    data-testid="confirmation-cancel" @wireEl('confirmation-cancel')
                    class="mt-3 inline-flex w-full justify-center rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 px-4 py-2.5 text-sm font-semibold text-gray-700 dark:text-gray-300 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 sm:mt-0 sm:w-auto"
                >
                    {{ $cancelLabel }}
                </button>

                {{-- 'before' footer actions → leftmost --}}
                @foreach(($footerActions ?? []) as $footerAction)
                    @if(($footerAction['position'] ?? 'before') === 'before')
                        @include('wire-core::actions.partials.modal-host-footer-action', ['footerAction' => $footerAction])
                    @endif
                @endforeach
            </div>
        </div>
    </div>
</div>
</template>
