@php
    use NyonCode\WireForms\Components\Display\Alert;

    assert($field instanceof Alert);
@endphp

{{-- Field-style alias of the canonical callout; markup lives in the shared partial. --}}
@include('wire-core::partials.callout', [
    'colorClasses' => $field->getColorClasses(),
    'icon' => $field->getIcon(),
    'heading' => $field->getTitle(),
    'body' => $field->getContent() !== null ? e($field->getContent()) : '',
    'dismissible' => $field->isDismissible(),
    'component' => $field,
])
