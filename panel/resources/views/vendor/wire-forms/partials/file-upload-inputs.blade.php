{{-- The file inputs, extracted so both layouts can hold the same ones.

     Two inputs rather than one when the browser processes the image: the user
     picks on `x-ref="picker"`, and only the cropped, downscaled result is handed
     to the input Livewire is listening on. Intercepting `change` on a single
     input would come down to listener order — Livewire is listening on its own
     input, and swallowing that event is a race, not a design. --}}
@if($processesImages)
    <input
        type="file"
        x-ref="picker"
        id="{{ $fieldId }}"
        @change="onPick($event)"
        data-testid="form-file-{{ $field->getStatePath() }}-picker"
        @if(!empty($acceptedTypes)) accept="{{ implode(',', $acceptedTypes) }}" @endif
        @if($isMultiple) multiple @endif
        @if($field->isDisabled()) disabled @endif
        @if($field->isRequired()) required @endif
        class="sr-only"
    />

    <input
        type="file"
        x-ref="fileInput"
        {{ $wireAttr }}="{{ $field->getWireModelAttribute() }}"
        @if($isMultiple) multiple @endif
        class="sr-only"
        tabindex="-1"
        aria-hidden="true"
    />
@else
    <input
        type="file"
        x-ref="fileInput"
        id="{{ $fieldId }}"
        {{ $wireAttr }}="{{ $field->getWireModelAttribute() }}"
        @if(!empty($acceptedTypes)) accept="{{ implode(',', $acceptedTypes) }}" @endif
        @if($isMultiple) multiple @endif
        @if($field->isDisabled()) disabled @endif
        @if($field->isRequired()) required @endif
        class="sr-only"
    />
@endif
