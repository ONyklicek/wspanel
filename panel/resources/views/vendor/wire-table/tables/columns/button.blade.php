{{-- ButtonColumn cell --}}
@php
    /** @var string|null $url */
    /** @var bool $openInNewTab */
    /** @var string $classes */
    /** @var string $iconHtml resolved icon svg (may be empty) */
    /** @var string $iconPosition before|after */
    /** @var bool $iconOnly */
    /** @var string $buttonLabel */
    /** @var array<string, mixed> $extraAttributes */
    /** @var string|null $disabledTooltip */
    /** @var bool $isDisabled */
    /** @var bool $showLoading */
    /** @var string|null $loadingText */
    /** @var string $wireClick full wire:click attribute (button only) */
    /** @var string $loadingTarget */
    /** @var string $removeTarget */
@endphp

@if($url)
    <a
        href="{{ $url }}"
        @if($openInNewTab) target="_blank" rel="noopener noreferrer" @elseif($navigate ?? false) wire:navigate @endif
        class="{{ $classes }}"
        data-testid="column-button" @wireEl('column-button')
        @if($buttonLabel) aria-label="{{ $buttonLabel }}" @endif
        @foreach($extraAttributes as $key => $value) {{ $key }}="{{ $value }}" @endforeach
        @if($disabledTooltip) title="{{ $disabledTooltip }}" @endif
    >
        @if($iconHtml && $iconPosition === 'before')
            {!! $iconHtml !!}
        @endif

        @unless($iconOnly)
            <span>{{ $buttonLabel }}</span>
        @endunless

        @if($iconHtml && $iconPosition === 'after')
            {!! $iconHtml !!}
        @endif
    </a>
@else
    <button
        type="button"
        class="{{ $classes }}"
        {!! $wireClick !!}
        data-testid="column-button" @wireEl('column-button')
        @if($buttonLabel) aria-label="{{ $buttonLabel }}" @endif
        @if($isDisabled) disabled @endif
        @foreach($extraAttributes as $key => $value) {{ $key }}="{{ $value }}" @endforeach
        @if($disabledTooltip) title="{{ $disabledTooltip }}" @endif
    >
        <span wire:loading.remove wire:target="{{ $removeTarget }}">
            @if($iconHtml && $iconPosition === 'before'){!! $iconHtml !!}@endif
            @unless($iconOnly)<span>{{ $buttonLabel }}</span>@endunless
            @if($iconHtml && $iconPosition === 'after'){!! $iconHtml !!}@endif
        </span>

        @if($showLoading)
            @php $loadingContent = $loadingText ?: ($iconOnly ? '' : $buttonLabel); @endphp
            <span wire:loading wire:target="{{ $loadingTarget }}" class="inline-flex items-center gap-1.5">
                {!! $spinnerHtml !!}
                @if($loadingContent)<span>{{ $loadingContent }}</span>@endif
            </span>
        @endif
    </button>
@endif
