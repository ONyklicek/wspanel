{{-- Renders the mounted action's modal / slide-over / wizard / confirmation for
     a WithActions host. Self-contained (no table view dependencies) and driven
     entirely by the host's action engine. --}}

{{-- Preload the floating-dropdown bundle on the host's initial render (this host
     view is always rendered, even with no modal open). A form modal opened by a
     later action can contain a searchable Select / dropdown whose `$float` magic
     must already be registered — its own @assets sits inside the teleported modal
     that only appears on the action roundtrip, which Livewire does not inject in
     time, so the panel would otherwise pin to the top-left corner. --}}
@include('wire-core::partials.floating-assets')

@php
    use NyonCode\WireCore\Foundation\Concerns\HasColor;
    use NyonCode\WireCore\Modals\ModalStack;

    /** @var object $component The Livewire host (WithActions). */
    $secondaryButtonClasses = 'inline-flex w-full sm:w-auto justify-center rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 px-4 py-2.5 text-sm font-semibold text-gray-700 dark:text-gray-300 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-600';
@endphp

@if($component->isActionModalVisible())
    @php
        $modalData = $component->getActionModalData();
        $actionFormInstance = $component->getActionModalFormInstance();
        $actionInfolistInstance = $component->getActionModalInfolistInstance();
        $hasInfolist = $modalData['hasInfolist'] ?? false;
        $isSlideOver = $modalData['slideOver'] ?? false;
        $isFullScreenMobile = $modalData['fullScreenOnMobile'] ?? false;
        $isSlideOverOnMobile = $modalData['slideOverOnMobile'] ?? false;
        $wizardSteps = $modalData['steps'] ?? null;
        $isWizard = is_array($wizardSteps) && count($wizardSteps) > 0;
        $currentStep = $isWizard ? $component->getMountedActionStepIndex() : 0;
        $totalSteps = $isWizard ? count($wizardSteps) : 0;
        $isLastStep = ! $isWizard || $currentStep >= ($totalSteps - 1);
        $primaryButtonClasses = 'inline-flex w-full sm:w-auto justify-center items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-sm '
            .($modalData['submitButtonClasses'] ?? HasColor::getModalSubmitButtonClasses($modalData['actionColor'] ?? 'primary'));

        // Modal stacking: every open modal is a live frame. Draw each parent
        // frame (all but the top) as a dimmed, click-inert — but still live —
        // form behind the active one, then layer the active modal on top. The
        // parent forms stay bound to their own depth-scoped state path, so a
        // nested modal's $setParent write is reflected behind it immediately.
        $mountedModals = $component->getMountedActionModals();
        $stackDepth = max(0, count($mountedModals) - 1);
        $activeZIndex = $stackDepth > 0 ? ModalStack::zIndexForDepth($stackDepth) : null;
        // The active modal binds to a stable flag (not a per-depth path) so the
        // reused modal element is not re-initialised by Alpine on push/pop.
        $activeShowModel = 'actionModalOpen';
    @endphp

    @for($depth = 0; $depth < $stackDepth; $depth++)
        @include('wire-core::modals.suspended', [
            'modalData' => $mountedModals[$depth],
            'formInstance' => $component->getActionModalFormInstanceForDepth($depth),
            'zIndex' => ModalStack::zIndexForDepth($depth),
            'depthBelowTop' => $stackDepth - $depth,
        ])
    @endfor

    @if(! empty($modalData) && isset($modalData['heading']))
        {{-- Confirmation --}}
        @if(($modalData['isConfirmation'] ?? false) && ! $isSlideOver && ! $actionFormInstance)
            {{-- Rule 5: the framework renders the modal as a Htmlable object, not
                 the <x-wire-modals::confirmation> Blade component. --}}
            {{ new \NyonCode\WireCore\Modals\Html\Confirmation(
                heading: $modalData['heading'],
                description: $modalData['description'] ?? null,
                width: $modalData['width'] ?? 'md',
                icon: $modalData['icon'] ?? 'exclamation-triangle',
                iconColor: $modalData['iconColor'] ?? 'warning',
                submitLabel: $modalData['submitLabel'],
                cancelLabel: $modalData['cancelLabel'],
                color: $modalData['actionColor'] ?? 'primary',
                closeOnClickAway: $modalData['closeOnClickAway'] ?? true,
                closeOnEscape: $modalData['closeOnEscape'] ?? true,
                maxHeight: $modalData['maxHeight'] ?? null,
                fullScreenOnMobile: $isFullScreenMobile,
                slideOverOnMobile: $isSlideOverOnMobile,
                breakpoint: $modalData['mobileBreakpoint'] ?? null,
                zIndex: $activeZIndex,
                closeAction: $closeAction,
                wireModel: $activeShowModel,
                wireClick: $submitAction,
                footerActions: $modalData['footerActions'] ?? [],
            ) }}
        @else
            {{-- Modal / Slide-over shell (Rule 5: Htmlable objects, not <x-*>).
                 The body/footer keep their existing partials — the current view
                 scope is handed to them as bodyData/footerData so they render
                 exactly as they did inside the component slot. --}}
            @php $modalVars = get_defined_vars(); @endphp
            @if($isSlideOver)
                {{ new \NyonCode\WireCore\Modals\Html\SlideOver(
                    heading: $modalData['heading'],
                    description: $modalData['description'] ?? null,
                    width: $modalData['width'] ?? 'md',
                    closeOnClickAway: $modalData['closeOnClickAway'] ?? true,
                    closeOnEscape: $modalData['closeOnEscape'] ?? true,
                    zIndex: $activeZIndex,
                    bottomSheetOnMobile: $isSlideOverOnMobile,
                    breakpoint: $modalData['mobileBreakpoint'] ?? null,
                    position: $modalData['slideOverPosition'] ?? 'right',
                    stickyHeader: $modalData['stickyHeader'] ?? false,
                    stickyFooter: $modalData['stickyFooter'] ?? false,
                    maxHeight: $modalData['maxHeight'] ?? null,
                    closeAction: $closeAction,
                    wireModel: $activeShowModel,
                    bodyView: 'wire-core::actions.partials.modal-host-body',
                    bodyData: $modalVars,
                    footerView: 'wire-core::actions.partials.modal-host-footer',
                    footerData: $modalVars,
                ) }}
            @else
                {{ new \NyonCode\WireCore\Modals\Html\Modal(
                    heading: $modalData['heading'],
                    description: $modalData['description'] ?? null,
                    width: $modalData['width'] ?? 'md',
                    closeOnClickAway: $modalData['closeOnClickAway'] ?? true,
                    closeOnEscape: $modalData['closeOnEscape'] ?? true,
                    zIndex: $activeZIndex,
                    fullScreenOnMobile: $isFullScreenMobile,
                    slideOverOnMobile: $isSlideOverOnMobile,
                    breakpoint: $modalData['mobileBreakpoint'] ?? null,
                    stickyFooter: true,
                    closeAction: $closeAction,
                    wireModel: $activeShowModel,
                    bodyView: 'wire-core::actions.partials.modal-host-body',
                    bodyData: $modalVars,
                    footerView: 'wire-core::actions.partials.modal-host-footer',
                    footerData: $modalVars,
                ) }}
            @endif
        @endif
    @endif
@endif

{{-- A halted action, waiting on the user. Rendered outside the block above
     because a halt outlives the modal that raised it — and because an action
     with no modal at all can raise one, as can a component with no actions. One
     owner for all three: the halt host. --}}
@include('wire-core::actions.halt-host', [
    'component' => $component,
    // Named in full on purpose: @include inherits the *parent's* scope, and this
    // view already has a $submitAction and a $closeAction — the action modal's.
    // Left to the include's own defaults, a halt's Confirm button called
    // callMountedAction() and the dialog never closed.
    'showModel' => 'mountedHalt.show',
    'submitAction' => 'submitHaltModal',
    'closeAction' => 'closeHaltModal',
])
