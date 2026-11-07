{{-- PollColumn cell.

     The cell is four nested wrappers and every one of them is optional: the poll
     host (a badge span, a plain div, or nothing at all), the loading row, the
     "hide the stale value while refreshing" wrapper, and the state's own classed
     span. They used to be assembled by concatenating HTML strings in a PHP
     preamble; they are written as markup here instead.

     Three of the wrappers therefore open and close in separate @if arms, marked
     below as pairs. That is the price of "wrap this when …" without a Blade
     component — Rendering rule 5 keeps <x-*> out of the framework's own render
     paths — and it is cheaper than the alternative, which is repeating the whole
     inner region once per combination.

     Mind the whitespace: every tag below touches its neighbour, and the line
     breaks are hidden inside Blade comments so they emit nothing. A run of
     whitespace between two of these spans is a rendered gap inside an
     inline-flex, and a DOM text node the morph walks on every commit. The one
     deliberate space is the one after a state icon, which the concatenation also
     emitted. --}}
@php
    /** @var string $stateIconHtml resolved state icon svg (may be empty) */
    /** @var string $content rendered state content */
    /** @var string $allClasses state + color + transition classes for the inner span */
    /** @var bool $shouldPoll */
    /** @var bool $isBadge */
    /** @var bool $showLoadingIndicator */
    /** @var bool $keepContentWhileLoading */
    /** @var string $loadingIndicator */
    /** @var string $position before|after */
    /** @var string $pollDirective wire:poll attribute (only when polling) */
    /** @var string $wireKey */
@endphp
@if($shouldPoll && $isBadge)<span @wireEl('table-badge') {!! $pollDirective !!} wire:key="{{ $wireKey }}" class="inline-flex items-center rounded-full font-medium">@elseif($shouldPoll)<div {!! $pollDirective !!} wire:key="{{ $wireKey }}">@endif{{--
--}}@if($showLoadingIndicator)<span class="inline-flex items-center gap-0">@endif{{--
--}}@if($showLoadingIndicator && $position === 'before')<span wire:loading>{!! $loadingIndicator !!}</span>@endif{{--
--}}@if($showLoadingIndicator && ! $keepContentWhileLoading)<span wire:loading.remove>@endif{{--
--}}@if($allClasses !== '')<span class="{{ $allClasses }}">@endif{{--
--}}@if($stateIconHtml !== ''){!! $stateIconHtml !!} @endif{!! $content !!}{{--
--}}@if($allClasses !== '')</span>@endif{{--
--}}@if($showLoadingIndicator && ! $keepContentWhileLoading)</span>@endif{{--
--}}@if($showLoadingIndicator && $position !== 'before')<span wire:loading class="ml-1">{!! $loadingIndicator !!}</span>@endif{{--
--}}@if($showLoadingIndicator)</span>@endif{{--
--}}@if($shouldPoll && $isBadge)</span>@elseif($shouldPoll)</div>@endif
