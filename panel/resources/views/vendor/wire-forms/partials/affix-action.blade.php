{{-- Interactive field affix action button (prefix/suffix slot).
     Expects: $action (Action), $statePath (string), $position ('left'|'right'). --}}
@php
    $affixPosition = $position ?? 'right';
    $rounding = $affixPosition === 'left' ? 'rounded-l-md border-r-0' : 'rounded-r-md border-l-0';
@endphp
<button
    type="button"
    wire:click="callFieldAction('{{ $statePath }}', '{{ $action->getName() }}')" data-testid="field-action-{{ $statePath }}-{{ $action->getName() }}"
    @if($action->getLabel()) aria-label="{{ $action->getLabel() }}" @endif
    wire:loading.attr="disabled"
    wire:target="callFieldAction"
    @if($action->getTooltip()) title="{{ $action->getTooltip() }}" @endif
    @class([
        'inline-flex items-center gap-1.5 px-3 border border-gray-300 dark:border-gray-600 text-sm font-medium transition-colors',
        $rounding,
        $action->getButtonColorClasses(),
    ])
>
    {!! app(\NyonCode\WireCore\Foundation\View\Primitives::class)->spinner('h-4 w-4', 'callFieldAction') !!}
    @if($action->getIcon())
        {!! icon($action->getIcon(), 'w-4 h-4', 'w-4 h-4') !!}
    @endif
    @unless($action->isHideLabel())
        <span>{{ $action->getLabel() }}</span>
    @endunless
</button>
