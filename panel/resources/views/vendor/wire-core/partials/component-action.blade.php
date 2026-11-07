{{-- Action button for infolist entries, schema section headers and widget
     headers.

     An action carrying `url()` renders as a link; every other one dispatches to
     the host by action name.

     `$clickExpression` is how a surface that is not an infolist gets its own
     dispatch without a second copy of this button: a widget header passes
     `callWidgetAction('key', 'name')` and everything below stays the one
     markup. Absent, the infolist resolver answers as it always did — which is
     what keeps every existing caller byte-identical.

     Expects: $action (NyonCode\WireCore\Actions\Action).
     Optional: $rowKey (int), $clickExpression (string), $testidPrefix (string). --}}
@php
    $actionIcon = $action->getIcon();
    $actionLabel = $action->getLabel();
    $actionRowKey = $rowKey ?? null;

    // `url()` is part of the Action vocabulary and this partial used to ignore
    // it: an action given one rendered as a button that dispatched
    // `callInfolistAction` and, having no callback to run, did nothing. Same
    // rule the canonical action button applies — a URL is a link, and a link is
    // the only affordance that works on a `ViewPage` at all, since a read-only
    // page composes no host trait and so has no `callInfolistAction` to call.
    $actionUrl = $action->getUrl();

    // One owner for the expression, because the button needs it three times and
    // the three have to agree. `wire:target` and the spinner used to carry the
    // bare method name, which Livewire matches against ANY call to it — so a
    // click on one action disabled and span every infolist button on the page,
    // and on a repeatable entry that is one per row. Same discipline as
    // `wire-core::actions.button`, which gates its spinner on the exact click.
    $actionClick = $clickExpression
        ?? (new \NyonCode\WireCore\Actions\Support\InfolistActionClickResolver($actionRowKey))
            ->clickHandler($action, null);

    $actionTestid = ($testidPrefix ?? 'infolist-action').'-'.$action->getName();
@endphp
@if($actionUrl)
    <a
        href="{{ $actionUrl }}"
        @if($action->shouldOpenUrlInNewTab()) target="_blank" rel="noopener" @elseif($action->shouldNavigateTo($actionUrl)) wire:navigate @endif
        data-testid="{{ $actionTestid }}"
        @if($actionLabel) aria-label="{{ $actionLabel }}" @endif
        @if($action->getTooltip()) title="{{ $action->getTooltip() }}" @endif
        @foreach($action->getExtraAttributes() as $attribute => $value)
            {{ $attribute }}="{{ $value }}"
        @endforeach
        @class([
            'inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium transition-colors',
            $action->getButtonColorClasses(),
        ])
    >
        @if($actionIcon)
            {!! icon($actionIcon, 'w-4 h-4', 'w-4 h-4') !!}
        @endif
        @unless($action->isHideLabel())
            <span>{{ $actionLabel }}</span>
        @endunless
    </a>
@else
    <button
        type="button"
        wire:click="{{ $actionClick }}"
        wire:loading.attr="disabled"
        wire:target="{{ $actionClick }}"
        data-testid="{{ $actionTestid }}"
        @if($actionLabel) aria-label="{{ $actionLabel }}" @endif
        @if($action->getTooltip()) title="{{ $action->getTooltip() }}" @endif
        @class([
            'inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium transition-colors',
            $action->getButtonColorClasses(),
        ])
    >
        {!! app(\NyonCode\WireCore\Foundation\View\Primitives::class)->spinner('h-4 w-4', $actionClick) !!}
        @if($actionIcon)
            {!! icon($actionIcon, 'w-4 h-4', 'w-4 h-4') !!}
        @endif
        @unless($action->isHideLabel())
            <span>{{ $actionLabel }}</span>
        @endunless
    </button>
@endif
