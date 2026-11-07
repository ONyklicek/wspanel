@php
    use NyonCode\WireForms\Components\Button;

    assert($field instanceof Button);
    $action = $field->getButtonAction();
@endphp

@include('wire-forms::partials.field-wrapper-start', ['hideLabel' => true])

<button
    type="button"
    wire:click="callFieldAction('{{ $field->getStatePath() }}', '{{ $action->getName() }}')" data-testid="form-button-{{ $field->getStatePath() }}"
    wire:loading.attr="disabled"
    wire:target="callFieldAction"
    @if($field->isDisabled()) disabled @endif
    @if($action->getTooltip()) title="{{ $action->getTooltip() }}" @endif
    @class([$action->getButtonClasses(), 'gap-2 opacity-60 cursor-not-allowed' => $field->isDisabled()])
>
    @include('wire-core::partials.spinner', ['wireTarget' => 'callFieldAction', 'class' => 'h-4 w-4'])
    @if($action->getIcon() && $action->getIconPosition() === 'before')
        {!! icon($action->getIcon(), 'w-4 h-4', 'w-4 h-4') !!}
    @endif
    <span>{{ $action->getLabel() }}</span>
    @if($action->getIcon() && $action->getIconPosition() === 'after')
        {!! icon($action->getIcon(), 'w-4 h-4', 'w-4 h-4') !!}
    @endif
</button>

@include('wire-forms::partials.field-wrapper-end')
