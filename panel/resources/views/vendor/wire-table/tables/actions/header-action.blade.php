@php

    use NyonCode\WireCore\Actions\HeaderAction;

    assert($action instanceof HeaderAction);

    $loadingState = $action->getLoadingStateData();
    $shortcutLabel = $action->getKeyboardShortcutLabel();
    $shortcutAlpine = $action->getAlpineKeydownExpression();

    // Size scale is header-button specific; color is owned by Foundation HasColor.
    $classes = 'inline-flex items-center gap-1.5 font-medium rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 dark:focus:ring-offset-gray-800 '
        . match ($action->getSize()) { 'xs' => 'px-2 py-1 text-xs', 'sm' => 'px-3 py-1.5 text-sm', 'md' => 'px-4 py-2 text-sm', 'lg' => 'px-5 py-2.5 text-base', default => 'px-3 py-1.5 text-sm' }
        . ' ' . $action->getButtonColorClasses();
@endphp

@if($action->getUrl())
    <a
            href="{{ $action->getUrl() }}"
            @if($action->shouldOpenUrlInNewTab()) target="_blank" @elseif($action->shouldNavigateTo($action->getUrl())) wire:navigate @endif
            class="{{ $classes }} relative"
            data-testid="header-action-{{ $action->getName() }}"
            @if($action->getLabel()) aria-label="{{ $action->getLabel() }}" @endif
            @if($action->getTooltip()) title="{{ $action->getTooltip() }}" @endif
    >
        @if($action->getIcon())
            {!! $action->renderIconSvg($action->getIcon(), 'w-4 h-4') !!}
        @endif
        <span>{{ $action->getLabel() }}</span>
        @if($shortcutLabel)
            <kbd
                    class="hidden sm:inline-block ml-1 px-1 py-0.5 text-[10px] font-mono bg-white/20 rounded-sm opacity-80">{{ $shortcutLabel }}</kbd>
        @endif
        {!! $action->getBadgeHtml() !!}
    </a>
@else
    @php
        $wireAction = $action->hasModal()
            ? "openHeaderActionModal('{$action->getName()}')"
            : "executeHeaderAction('{$action->getName()}')";
    @endphp
    <button
            type="button"
            wire:click{{ $loadingState['wireModifiers'] }}="{{ $wireAction }}"
            {{-- Targets the modal island, as wire-core's action button does. In the
                 toolbar it only spares the table a render; in the empty state it is
                 what makes the button work at all: that state sits inside the
                 data-region island, Livewire targets the closest island by itself,
                 and a call targeting data-region returns that island alone — the
                 modal opens on the server and never reaches the page. --}}
            @if($action->hasModal()) wire:island="action-modals" @endif
            class="{{ $classes }} relative"
            data-testid="header-action-{{ $action->getName() }}"
            @if($action->getLabel()) aria-label="{{ $action->getLabel() }}" @endif
            @if($action->getTooltip()) title="{{ $action->getTooltip() }}" @endif
            @if($shortcutAlpine)
                x-on:keydown.{{ $shortcutAlpine }}.window.prevent="$el.click()"
            @endif
    >
        {{-- Loading spinner --}}
        @if($loadingState['showLoading'])
            @include('wire-core::partials.spinner', ['wireTarget' => $wireAction, 'class' => 'w-4 h-4'])
        @endif

        {{-- The button's face, and what `wire:loading.remove` swaps for the
             spinner. The wrapper is unconditional and only the directive is
             conditional: opening the span inside one `@if` and closing it inside
             another two blocks later parses as valid Blade and reads as a bug —
             and an editor that reformats the file, or a later `@if` inserted
             between them, silently changes which elements it wraps. --}}
        <span
            @if($loadingState['showLoading'])
                wire:loading.remove wire:target="{{ $wireAction }}"
            @endif
            class="inline-flex items-center gap-1.5"
        >
            @if($action->getIcon())
                {!! $action->renderIconSvg($action->getIcon(), 'w-4 h-4') !!}
            @endif

            <span>{{ $action->getLabel() }}</span>

            @if($shortcutLabel)
                <kbd class="hidden sm:inline-block ml-1 px-1 py-0.5 text-[10px] font-mono bg-white/20 rounded-sm opacity-80">{{ $shortcutLabel }}</kbd>
            @endif
        </span>

        @if($loadingState['showLoading'] && $loadingState['loadingText'])
            <span wire:loading wire:target="{{ $wireAction }}">{{ $loadingState['loadingText'] }}</span>
        @endif

        {!! $action->getBadgeHtml() !!}
    </button>
@endif
