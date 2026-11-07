{{-- Action Modal (Confirmation or Form) - uses wire-core modal components --}}

{{-- Preload the floating-dropdown bundle on the table's initial render (this
     partial is always included, even with no modal open). An action modal opened
     by a header/row action can contain a searchable Select / dropdown whose
     `$float` magic must already be registered — its own @assets sits inside the
     teleported modal that only appears on the action roundtrip, which Livewire
     does not inject in time. Idempotent: @assets registers once even if the
     table's filters / column toggle already loaded it. --}}
@include('wire-core::partials.floating-assets')

@if($component->isActionModalVisible())
    @php
        $modalData = $component->getActionModalData();
        $actionFormInstance = $component->getActionModalFormInstance();
        $actionInfolistInstance = $component->getActionModalInfolistInstance();
        $hasInfolist = $modalData['hasInfolist'] ?? false;
        $isSlideOver = $modalData['slideOver'] ?? false;
        $isSlideOverOnMobile = $modalData['slideOverOnMobile'] ?? false;
        $isFullScreenMobile = $modalData['fullScreenOnMobile'] ?? false;
        $wizardSteps = $modalData['steps'] ?? null;
        $isWizard = is_array($wizardSteps) && count($wizardSteps) > 0;
        $wizardCurrentStep = $isWizard ? $component->getMountedActionStepIndex() : 0;
        $wizardTotalSteps = $isWizard ? count($wizardSteps) : 0;

        // Modal stacking: every open modal is a live frame. Draw each parent
        // frame (all but the top) as a dimmed, click-inert — but still live —
        // form behind the active one, then layer the active modal on top.
        $mountedModals = $component->getMountedActionModals();
        $stackDepth = max(0, count($mountedModals) - 1);
        $activeZIndex = $stackDepth > 0 ? \NyonCode\WireCore\Modals\ModalStack::zIndexForDepth($stackDepth) : null;
        // The active modal binds to a stable flag (not a per-depth path) so the
        // reused modal element is not re-initialised by Alpine on push/pop.
        $activeShowModel = 'tableState.modal.open';
    @endphp

    @for($depth = 0; $depth < $stackDepth; $depth++)
        @include('wire-core::modals.suspended', [
            'modalData' => $mountedModals[$depth],
            'formInstance' => $component->getActionModalFormInstanceForDepth($depth),
            'zIndex' => \NyonCode\WireCore\Modals\ModalStack::zIndexForDepth($depth),
            'depthBelowTop' => $stackDepth - $depth,
        ])
    @endfor

    @if(!empty($modalData) && isset($modalData['heading']))
        {{-- Rule 5: the framework renders modals as Htmlable objects, not <x-*>.
             The body/footer keep their partials; the current view scope is handed
             to them as bodyData/footerData so they render exactly as before. --}}
        @php $actionModalVars = get_defined_vars(); @endphp
        @if($isSlideOver)
            {{-- Slide Over Panel --}}
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
                closeAction: 'closeActionModal',
                wireModel: $activeShowModel,
                bodyView: 'wire-table::tables.partials.action-modal-body',
                bodyData: $actionModalVars,
                footerView: 'wire-table::tables.partials.action-modal-slideover-footer',
                footerData: $actionModalVars,
            ) }}
        @elseif($modalData['isConfirmation'] ?? false)
            {{-- Confirmation Modal --}}
            @php
                $iconColor = $modalData['iconColor'] ?? 'warning';
            @endphp
            {{-- Rule 5: rendered as a Htmlable object, not the <x-*> component. --}}
            {{ new \NyonCode\WireCore\Modals\Html\Confirmation(
                heading: $modalData['heading'],
                description: $modalData['description'] ?? null,
                width: $modalData['width'] ?? 'md',
                icon: 'exclamation-triangle',
                iconColor: $iconColor,
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
                closeAction: 'closeActionModal',
                wireModel: $activeShowModel,
                wireClick: 'submitActionModal',
                footerActions: $modalData['footerActions'] ?? [],
            ) }}
        @else
            {{-- Form Modal (Rule 5: Htmlable object, not <x-*>). --}}
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
                closeAction: 'closeActionModal',
                wireModel: $activeShowModel,
                bodyView: 'wire-table::tables.partials.action-modal-body',
                bodyData: $actionModalVars,
                footerView: 'wire-table::tables.partials.action-modal-modal-footer',
                footerData: $actionModalVars,
            ) }}
        @endif
    @endif
@endif
