@php

    use NyonCode\WireCore\Actions\BulkAction;

    assert($action instanceof BulkAction);

    // Size scale is bulk-button specific; color is owned by Foundation HasColor.
    $classes = 'inline-flex items-center gap-1.5 font-medium rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 dark:focus:ring-offset-gray-800 '
        . match ($action->getSize()) { 'xs' => 'px-2 py-1 text-xs', 'sm' => 'px-2.5 py-1.5 text-sm', 'md' => 'px-3 py-2 text-sm', 'lg' => 'px-4 py-2.5 text-base', default => 'px-2.5 py-1.5 text-sm' }
        . ' ' . $action->getButtonColorClasses();
@endphp

<button
        type="button"
        @if($action->hasModal())
            wire:click="openBulkActionModal('{{ $action->getName() }}')"
        @else
            wire:click="executeBulkAction('{{ $action->getName() }}')"
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
