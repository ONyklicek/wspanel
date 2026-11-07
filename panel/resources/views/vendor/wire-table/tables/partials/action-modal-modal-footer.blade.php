{{-- Footer of the table action FORM MODAL: wizard navigation, or the
     cancel/submit buttons + footer actions (touch-friendly, full-width on mobile).
     Rendered via the Modal object's footerView (Rule 5). Expects $isWizard,
     $wizardCurrentStep, $wizardTotalSteps, $modalData, $hasInfolist in scope
     (passed as footerData). --}}
@if($isWizard)
    @include('wire-table::tables.partials.wizard-footer', [
        'currentStep' => $wizardCurrentStep,
        'totalSteps' => $wizardTotalSteps,
        'modalData' => $modalData,
        'secondaryClasses' => 'inline-flex w-full justify-center rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 px-4 py-3 sm:py-2.5 text-sm font-semibold text-gray-700 dark:text-gray-300 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-600 active:bg-gray-100 dark:active:bg-gray-600 sm:w-auto touch-manipulation',
        'primaryClasses' => 'inline-flex w-full justify-center items-center gap-2 rounded-xl px-4 py-3 sm:py-2.5 text-sm font-semibold text-white shadow-sm sm:w-auto touch-manipulation '.($modalData['submitButtonClasses'] ?? \NyonCode\WireCore\Foundation\Concerns\HasColor::getModalSubmitButtonClasses($modalData['actionColor'] ?? 'primary')),
    ])
@else
<div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3">
    @include('wire-table::tables.partials.modal-footer-actions', [
        'footerActions' => $modalData['footerActions'] ?? [],
        'position' => 'before',
    ])
    <button
        type="button"
        wire:click="closeActionModal" data-testid="modal-cancel" @wireEl('modal-cancel')
        class="inline-flex w-full justify-center rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 px-4 py-3 sm:py-2.5 text-sm font-semibold text-gray-700 dark:text-gray-300 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-600 active:bg-gray-100 dark:active:bg-gray-600 sm:w-auto touch-manipulation"
    >
        {{ $modalData['cancelLabel'] }}
    </button>
    @unless($hasInfolist)
    <button
        type="button"
        wire:click="submitActionModal" data-testid="modal-submit" @wireEl('modal-submit')
        wire:loading.attr="disabled"
        wire:target="submitActionModal"
        @class([
            'inline-flex w-full justify-center items-center gap-2 rounded-xl px-4 py-3 sm:py-2.5 text-sm font-semibold text-white shadow-sm sm:w-auto touch-manipulation',
            $modalData['submitButtonClasses'] ?? \NyonCode\WireCore\Foundation\Concerns\HasColor::getModalSubmitButtonClasses($modalData['actionColor'] ?? 'primary'),
        ])
    >
        @include('wire-core::partials.spinner', ['wireTarget' => 'submitActionModal', 'class' => 'h-4 w-4'])
        <span wire:loading.remove wire:target="submitActionModal">{{ $modalData['submitLabel'] }}</span>
        <span wire:loading wire:target="submitActionModal">{{ $modalData['savingLabel'] ?? __('Saving...') }}</span>
    </button>
    @endunless
    @include('wire-table::tables.partials.modal-footer-actions', [
        'footerActions' => $modalData['footerActions'] ?? [],
        'position' => 'after',
    ])
</div>
@endif
