{{-- Footer of the action modal-host: custom footer actions, cancel, and the
     submit / wizard navigation buttons. Expects $component, $modalData,
     $hasInfolist, $isWizard, $currentStep, $totalSteps, $isLastStep,
     $primaryButtonClasses, $secondaryButtonClasses and the configured
     $submitAction / $closeAction / $nextStepAction / $prevStepAction. --}}
@php
    use NyonCode\WireCore\Foundation\Concerns\HasColor;

    $footerActions = $modalData['footerActions'] ?? [];
@endphp

<div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
    {{-- Custom footer actions (position: before) --}}
    @foreach($footerActions as $footerAction)
        @continue(($footerAction['position'] ?? 'before') !== 'before')
        @include('wire-core::actions.partials.modal-host-footer-action', ['footerAction' => $footerAction])
    @endforeach

    {{-- Cancel --}}
    <button type="button" wire:click="{{ $closeAction }}" data-testid="modal-cancel" @wireEl('modal-cancel') class="{{ $secondaryButtonClasses }}">
        {{ $modalData['cancelLabel'] }}
    </button>

    {{-- Wizard back --}}
    @if($isWizard && $currentStep > 0)
        <button type="button" wire:click="{{ $prevStepAction }}" data-testid="modal-back" @wireEl('modal-back') class="{{ $secondaryButtonClasses }}">
            {{ $modalData['backLabel'] ?? __('Back') }}
        </button>
    @endif

    {{-- Wizard next --}}
    @if($isWizard && ! $isLastStep)
        <button type="button" wire:click="{{ $nextStepAction }}" data-testid="modal-next" @wireEl('modal-next') class="{{ $primaryButtonClasses }}">
            {{ $modalData['nextLabel'] ?? __('Next') }}
        </button>
    @elseif(! $hasInfolist)                                                                                                                                                                                                {{-- Submit (single-step form, wizard last step, or confirmation-in-shell) --}}
        <button
            type="button"
            wire:click="{{ $submitAction }}"
            wire:loading.attr="disabled"
            wire:target="{{ $submitAction }}"
            data-testid="modal-submit" @wireEl('modal-submit')
            @class(['inline-flex items-center gap-2', $primaryButtonClasses])
        >
            @include('wire-core::partials.spinner', ['wireTarget' => $submitAction, 'class' => 'h-4 w-4'])
            <span wire:loading.remove wire:target="{{ $submitAction }}">{{ $modalData['submitLabel'] }}</span>
            <span wire:loading wire:target="{{ $submitAction }}">{{ $modalData['savingLabel'] ?? __('Saving...') }}</span>
        </button>
    @endif

    {{-- Custom footer actions (position: after) --}}
    @foreach($footerActions as $footerAction)
        @continue(($footerAction['position'] ?? 'before') !== 'after')
        @include('wire-core::actions.partials.modal-host-footer-action', ['footerAction' => $footerAction])
    @endforeach
</div>
