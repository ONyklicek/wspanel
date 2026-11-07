{{-- Create / edit option modals for a Select-family combobox, rendered only
     while mounted (one open at a time, keyed by state path — see
     InteractsWithSelectCreation). Shared by the base Select view and
     relationship variants (BelongsToSelect) so the modal flow has one owner.

     Presentation comes from the field's canonical Modals\Modal config
     (Select::getCreateOptionModal() / getEditOptionModal()), projected onto the
     Htmlable render object below — the same shape as actions/modal-host.blade.php.
     The teleport id, wire:model and close action are NOT taken from the config:
     they identify the modal to Livewire and are owned here.

     Expected variables:
       $field     Select   the field owning the option forms
       $livewire  mixed    the bound Livewire host (non-null when included) --}}
@php
    // Which modal to draw. Select owns the rule (the callers gate on
    // hasMountedOptionModal() before including this at all), so the two views and
    // this partial can never disagree about what "mounted" means.
    $isCreateModalMounted = $field->hasMountedCreateOptionModal($livewire);
    $isEditModalMounted = $field->hasMountedEditOptionModal($livewire);

    // When the Select lives inside a stacked action modal, the option overlay must
    // sit one layer above the top action frame — otherwise it renders behind the
    // modal it was opened from. Outside any action modal (depth 0) the default
    // base layer is fine. The depth read is side-effect-free (no form rebuild).
    $optionModalZ = (is_object($livewire) && method_exists($livewire, 'actionStackDepth') && $livewire->actionStackDepth() > 0)
        ? \NyonCode\WireCore\Modals\ModalStack::zIndexForDepth($livewire->actionStackDepth())
        : null;
@endphp

@if($isCreateModalMounted)
    @php
        $createModal = $field->getCreateOptionModal();
        $createForm = $field->getCreateOptionForm($livewire);
        // A wizard that gave up its own navigation hands it to this footer; one
        // that kept it drives itself and the footer stays a plain submit/cancel.
        $createWizard = $createForm?->getWizard();
        $createWizard = ($createWizard !== null && ! $createWizard->hasNavigation()) ? $createWizard : null;
    @endphp
    {{-- Rule 5: Htmlable object, not the <x-wire::modal> component. --}}
    {{ new \NyonCode\WireCore\Modals\Html\Modal(
        heading: $field->getCreateOptionModalHeading(),
        description: $createModal->getDescription($field),
        width: $createModal->getWidth(),
        icon: $createModal->getIcon(),
        iconColor: $createModal->getIconColor(),
        maxHeight: $createModal->getMaxHeight(),
        closeOnClickAway: $createModal->shouldCloseOnClickAway(),
        closeOnEscape: $createModal->shouldCloseOnEscape(),
        fullScreenOnMobile: $createModal->isFullScreenOnMobile(),
        stickyFooter: $createModal->hasStickyFooter(),
        stickyHeader: $createModal->hasStickyHeader(),
        zIndex: $optionModalZ,
        {{-- Keys the teleport: nothing stops both option modals from being
             mounted at once (the two properties are independent), and two
             unkeyed Modal shells in one component share a wire:key. --}}
        id: 'select-create-option-'.md5($field->getStatePath()),
        closeAction: 'unmountCreateOption',
        wireModel: 'mountedCreateOptionSelect',
        bodyView: 'wire-forms::partials.select-option-modal-body',
        bodyData: ['field' => $field, 'form' => $createForm, 'mode' => 'create'],
        footerView: 'wire-forms::partials.select-option-modal-footer',
        footerData: [
            'mode' => 'create',
            'cancelAction' => 'unmountCreateOption',
            'saveAction' => 'createSelectOption',
            'saveLabel' => $createModal->getSubmitLabel(),
            'cancelLabel' => $createModal->getCancelLabel(),
            'wizardKey' => $createWizard?->getName() ?: null,
            'wizardSteps' => $createWizard === null ? 0 : count($createWizard->getSteps()),
        ],
    ) }}
@endif

@if($isEditModalMounted)
    @php
        $editModal = $field->getEditOptionModal();
        $editForm = $field->getEditOptionForm($livewire);
        $editWizard = $editForm?->getWizard();
        $editWizard = ($editWizard !== null && ! $editWizard->hasNavigation()) ? $editWizard : null;
    @endphp
    {{-- Rule 5: Htmlable object, not the <x-wire::modal> component. --}}
    {{ new \NyonCode\WireCore\Modals\Html\Modal(
        heading: $field->getEditOptionModalHeading(),
        description: $editModal->getDescription($field),
        width: $editModal->getWidth(),
        icon: $editModal->getIcon(),
        iconColor: $editModal->getIconColor(),
        maxHeight: $editModal->getMaxHeight(),
        closeOnClickAway: $editModal->shouldCloseOnClickAway(),
        closeOnEscape: $editModal->shouldCloseOnEscape(),
        fullScreenOnMobile: $editModal->isFullScreenOnMobile(),
        stickyFooter: $editModal->hasStickyFooter(),
        stickyHeader: $editModal->hasStickyHeader(),
        zIndex: $optionModalZ,
        id: 'select-edit-option-'.md5($field->getStatePath()),
        closeAction: 'unmountEditOption',
        wireModel: 'mountedEditOptionSelect',
        bodyView: 'wire-forms::partials.select-option-modal-body',
        bodyData: ['field' => $field, 'form' => $editForm, 'mode' => 'edit'],
        footerView: 'wire-forms::partials.select-option-modal-footer',
        footerData: [
            'mode' => 'edit',
            'cancelAction' => 'unmountEditOption',
            'saveAction' => 'updateSelectOption',
            'saveLabel' => $editModal->getSubmitLabel(),
            'cancelLabel' => $editModal->getCancelLabel(),
            'wizardKey' => $editWizard?->getName() ?: null,
            'wizardSteps' => $editWizard === null ? 0 : count($editWizard->getSteps()),
        ],
    ) }}
@endif
