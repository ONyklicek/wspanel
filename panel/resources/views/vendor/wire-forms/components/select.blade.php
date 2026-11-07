@php
    use NyonCode\WireForms\Components\Select;

    assert($field instanceof Select);

    // Shared by Select and BelongsToSelect: the relationship field differs only in
    // where its options come from, which is the field's business, not the view's.
    $wireModifier = $field->getWireModelModifier();
    $nativeMode = $field->getNativeControlMode();
    $livewire = $field->getLivewire();
    $statePath = $field->getStatePath();
    $hasCreateOption = $field->hasCreateOptionForm() && $livewire !== null;
    // Editing targets the single selected option; multi-selects have no single target.
    $hasEditOption = $field->hasEditOptionForm() && ! $field->isMultiple() && $livewire !== null;
    // The render-time option list, plus the label(s) for the current selection
    // so a remotely-searched value still shows. Resolved once for both controls.
    $options = $field->getRenderedOptions($livewire ? data_get($livewire, $statePath) : null);
    $panelFooter = null;

    if ($nativeMode->rendersCustom()) {
        $panelFooter = ($hasCreateOption || $hasEditOption)
            ? view('wire-forms::partials.select-option-actions', [
                'statePath' => $statePath,
                'hasCreate' => $hasCreateOption,
                'hasEdit' => $hasEditOption,
                'createLabel' => $field->getCreateOptionModalHeading(),
                'editLabel' => $field->getEditOptionModalHeading(),
            ])->render()
            : null;
    }
@endphp

@include('wire-forms::partials.field-wrapper-start')

{{-- Combobox, native <select>, or both split at the mobile breakpoint — the
     canonical core owner decides which from the resolved mode. --}}
@include('wire-core::partials.select-control', [
    'nativeMode' => $nativeMode,
    'selectId' => $field->getId(),
    'statePath' => $field->getWireModelAttribute(),
    'options' => $options,
    'placeholder' => $field->getPlaceholder(),
    'multiple' => $field->isMultiple(),
    'searchable' => $field->isSearchable(),
    'searchPrompt' => $field->getSearchPrompt(),
    'noResultsMessage' => $field->getNoSearchResultsMessage(),
    'disabled' => $field->isDisabled(),
    'required' => $field->isRequired(),
    'hasError' => $errors->has($statePath),
    'disabledValues' => $field->getDisabledOptionValues(),
    'remoteSearch' => $field->isRemoteSearch(),
    'loadingMessage' => $field->getLoadingMessage(),
    'panelFooter' => $panelFooter,
    'sheetOnMobile' => $field->usesSheetOnMobile(),
    'touch' => $field->usesTouchOnMobile(),
    'sheetTitle' => $field->getLabel(),
    'mobileBreakpoint' => $field->getMobileBreakpoint(),
    // Honor live(): a live select must sync its selection to the server on
    // click (afterStateUpdated, visibleWhen siblings), not on the next
    // unrelated roundtrip.
    'live' => $field->isLive() || $field->isLiveOnBlur(),
    'wireModel' => 'wire:model'.($wireModifier ? ".{$wireModifier}" : ''),
    'ariaLabel' => $field->getLabel(),
    'extraInputAttributes' => $field->getExtraInputAttributesHtml(),
])

@include('wire-forms::partials.field-wrapper-end')

@if($livewire !== null && $field->hasMountedOptionModal($livewire))
    {{-- Isolated create/edit option modals (one open at a time, keyed by state path).
         Gated on the mount check so a Select with no modal open costs no view
         render here — the partial emits nothing in that case. --}}
    @include('wire-forms::partials.select-option-modals')
@endif
