{{-- Footer of the table action SLIDE-OVER: wizard navigation, or the
     cancel/submit buttons + footer actions. Rendered via the SlideOver object's
     footerView (Rule 5). Expects $isWizard, $wizardCurrentStep, $wizardTotalSteps,
     $modalData, $hasInfolist in scope (passed as footerData). --}}
@if($isWizard)
    @include('wire-table::tables.partials.wizard-footer', [
        'currentStep' => $wizardCurrentStep,
        'totalSteps' => $wizardTotalSteps,
        'modalData' => $modalData,
        'secondaryClasses' => 'rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 px-4 py-2.5 text-sm font-semibold text-gray-700 dark:text-gray-300 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-600',
        'primaryClasses' => 'rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-sm inline-flex items-center gap-2 '.($modalData['submitButtonClasses'] ?? \NyonCode\WireCore\Foundation\Concerns\HasColor::getModalSubmitButtonClasses($modalData['actionColor'] ?? 'primary')),
    ])
@else
<div class="flex justify-end gap-3">
    @include('wire-table::tables.partials.modal-footer-actions', [
        'footerActions' => $modalData['footerActions'] ?? [],
        'position' => 'before',
    ])
    <button
        type="button"
        wire:click="closeActionModal" data-testid="modal-cancel" @wireEl('modal-cancel')
        class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 px-4 py-2.5 text-sm font-semibold text-gray-700 dark:text-gray-300 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-600"
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
            'rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-sm inline-flex items-center gap-2',
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
