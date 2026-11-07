{{-- A single custom modal footer action (Action::modalFooterActions()).
     Expects $footerAction in scope. --}}
@php
    use NyonCode\WireCore\Foundation\Concerns\HasColor;

    $color = $footerAction['color'] ?? 'gray';
    $outlined = (bool) ($footerAction['outlined'] ?? false);
    // One owner for the expression, because the button names it three times and
    // the three have to agree. `wire:target` and the spinner carried the bare
    // method name, which Livewire matches against any call to it — and
    // modalFooterActions() is a list, so one click disabled and span every
    // custom footer button beside it.
    $footerClick = "callModalFooterAction('".addslashes((string) $footerAction['name'])."')";
    $footerButtonClasses = $outlined
        ? 'inline-flex w-full sm:w-auto justify-center rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 px-4 py-2.5 text-sm font-semibold text-gray-700 dark:text-gray-300 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-600'
        : 'inline-flex w-full sm:w-auto justify-center rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-sm '.HasColor::getModalSubmitButtonClasses($color);
@endphp

<button
    type="button"
    wire:click="{{ $footerClick }}"
    @if(! empty($footerAction['confirmMessage']))
        wire:confirm="{{ $footerAction['confirmMessage'] }}"
    @endif
    wire:loading.attr="disabled"
    wire:target="{{ $footerClick }}"
    data-testid="modal-footer-action-{{ $footerAction['name'] }}"
    @if(! empty($footerAction['label'])) aria-label="{{ $footerAction['label'] }}" @endif
    @class(['items-center gap-2', $footerButtonClasses])
>
    @include('wire-core::partials.spinner', ['wireTarget' => $footerClick, 'class' => 'h-4 w-4'])
    @if(! empty($footerAction['icon']))
        {!! icon($footerAction['icon'], 'w-4 h-4', 'h-4 w-4') !!}
    @endif
    <span>{{ $footerAction['label'] }}</span>
</button>
