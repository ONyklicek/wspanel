{{-- Footer of a Select create/edit option modal: cancel + save buttons. Rendered
     via the Htmlable Modal object's footerView (Rule 5). Expects $mode
     ('create'|'edit'), $cancelAction, $saveAction, $saveLabel, $cancelLabel in
     scope (passed as footerData); the two labels come from the field's modal
     config, so ->cancelLabel()/->submitLabel() reach both buttons.

     $wizardKey / $wizardSteps arrive when the option form holds a wizard that
     gave up its own navigation (Wizard::navigation(false)). The footer then
     drives the steps: it mirrors the wizard's state from the `wire-wizard-state`
     window event and steps it back with `wire-wizard-navigate`. Window, because
     the wizard is a sibling subtree inside the modal — a bubbling event never
     reaches here. Both are scoped by the wizard's name. --}}
@php
    $drivesWizard = ($wizardSteps ?? 0) > 1;
@endphp
<div class="flex justify-end gap-2"
    @if($drivesWizard)
        x-data="{
            wizard: @js($wizardKey),
            step: 0,
            total: @js($wizardSteps),
            validating: false,
            go(direction) {
                window.dispatchEvent(new CustomEvent('wire-wizard-navigate', {
                    detail: { wizard: this.wizard, direction },
                }));
            },
        }"
        x-on:wire-wizard-state.window="if ($event.detail?.wizard === wizard) {
            step = $event.detail.step;
            total = $event.detail.total;
            validating = $event.detail.validating;
        }"
    @endif
>
    <button
        type="button"
        wire:click="{{ $cancelAction }}" data-testid="select-{{ $mode }}-cancel"
        class="inline-flex items-center rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-200 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-150"
    >
        {{ $cancelLabel }}
    </button>
    @if($drivesWizard)
        <button
            type="button"
            x-show="step > 0"
            x-cloak
            @click="go('previous')"
            data-testid="select-{{ $mode }}-back"
            class="inline-flex items-center gap-1 rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-200 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-150"
        >
            {!! icon('outline:chevron-left', 'w-4 h-4', 'w-4 h-4') !!}
            {{ __('wire-core::actions.wizard_previous') }}
        </button>
        <button
            type="button"
            x-show="step < total - 1"
            x-cloak
            @click="go('next')"
            :disabled="validating"
            data-testid="select-{{ $mode }}-next"
            class="inline-flex items-center gap-1 rounded-md border border-transparent bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-wait transition-colors duration-150"
        >
            {{ __('wire-core::actions.wizard_next') }}
            {!! icon('outline:chevron-right', 'w-4 h-4', 'w-4 h-4') !!}
        </button>
    @endif
    <button
        type="button"
        @if($drivesWizard) x-show="step >= total - 1" x-cloak @endif
        wire:click="{{ $saveAction }}" data-testid="select-{{ $mode }}-save"
        wire:loading.attr="disabled"
        class="inline-flex items-center rounded-md border border-transparent bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-50 transition-colors duration-150"
    >
        {{ $saveLabel }}
    </button>
</div>
