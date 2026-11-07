@php
    use NyonCode\WireCore\Actions\BulkAction;

    assert($action instanceof BulkAction);

    // Delegate color/size to the canonical Foundation resolver so every hue
    // (success/warning/info, solid + outlined) is supported and never re-encoded here.
    $classes = $action->getButtonClasses();

    $wireClickAction = $wireClick ?? '';
    $wireModifiers = $wireClickModifiers ?? '';
@endphp

<button
        type="button"
        @if($wireClickAction)
            wire:click{{ $wireModifiers }}="{{ $wireClickAction }}"
        @endif
        class="{{ $classes }}"
        data-testid="bulk-action-{{ $action->getName() }}"
        @if($action->getLabel()) aria-label="{{ $action->getLabel() }}" @endif
        @if($action->getTooltip()) title="{{ $action->getTooltip() }}" @endif
>
    @if($action->getIcon())
        {!! $action->renderIconSvg($action->getIcon(), 'w-4 h-4') !!}
    @endif
    <span>{{ $action->getLabel() }}</span>
</button>
