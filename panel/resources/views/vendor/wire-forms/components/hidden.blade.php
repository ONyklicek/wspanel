@php /** @var \NyonCode\WireForms\Components\Hidden $field */ @endphp
<input
    {!! $field->getExtraInputAttributesHtml() !!}
    type="hidden"
    id="{{ $field->getId() }}"
    @if($field->submitsNatively()){!! $field->getNativeBindingHtml() !!}@else wire:model{{ $field->getWireModelModifier() ? '.' . $field->getWireModelModifier() : '' }}="{{ $field->getWireModelAttribute() }}"@endif
/>
